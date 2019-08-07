<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use League\Event\Emitter;
use Nette\Utils\DateTime;

class FreeRecurrentUpgrade implements UpgraderInterface
{
    const TYPE = 'free_recurrent';

    use UpgraderTrait;
    use SplitSubscriptionTrait;

    private $recurrentPaymentsRepository;

    private $paymentsRepository;

    private $subscriptionsRepository;

    private $emitter;

    private $hermesEmitter;

    private $monthlyFix;

    public function __construct(
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter
    ) {
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->emitter = $emitter;
        $this->hermesEmitter = $hermesEmitter;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function isUsable(): bool
    {
        if (!$this->basePayment->payment_gateway->is_recurrent) {
            return false;
        }

        $recurrent = $this->recurrentPaymentsRepository->recurrent($this->basePayment);
        if (!$recurrent) {
            return false;
        }
        if ($recurrent->subscription_type_id != $this->baseSubscription->subscription_type_id) {
            return false;
        }
        if ($recurrent->expires_at !== null && $recurrent->expires_at < DateTime::from('+1 month')) {
            // return new SubscriptionUpgrade($this->translator->translate('upgrades.frontend.upgrade.error.card_expiring'), false, $basePayment, $actualUserSubscription);
            return false;
        }

        $remainingDiff = (new DateTime())->diff($this->baseSubscription->end_time);
        if ($remainingDiff->days >= 5) {
            return false;
        }

        return true;
    }

    public function applyConfig(array $config): UpgraderInterface
    {
        $clone = (clone $this);
        if (isset($config['monthly_fix'])) {
            $monthlyFix = filter_var($config['monthly_fix'], FILTER_VALIDATE_FLOAT);
            if ($monthlyFix === false) {
                throw new \Exception('Invalid value provided in ShortUpgrade config "monthly_fix": ' . $config['monthly_fix']);
            }
            $this->monthlyFix = $monthlyFix;
        }

        return $clone;
    }

    public function profitability(): float
    {
        return 1 / $this->getFutureChargePrice();
    }

    public function upgrade(): bool
    {
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($this->basePayment);
        if (!$recurrentPayment) {
            throw new \Exception('Nemalo by nikdy nastat - pokus upgradnut nerecurentne zaplatenu subscription - paymentID:' . $this->basePayment->id . ' subscriptionID:' . $this->baseSubscription->id);
        }

        $note = "Free recurrent upgrade from subscription type {$recurrentPayment->subscription_type->name} to {$this->targetSubscriptionType->name}";

        $eventParams = [
            'user_id' => $this->basePayment->user_id,
            'browser_id' => $this->browserId,
            'source' => $this->trackingParams,
            'sales_funnel_id' => 'upgrade',
            'transaction_id' => self::TYPE,
            'product_ids' => [strval($this->basePayment->subscription_type_id)],
            'revenue' => 0,
        ];
        $this->hermesEmitter->emit(new HermesMessage('sales-funnel', array_merge(['type' => 'payment'], $eventParams)));

        $this->paymentsRepository->update($this->basePayment, [
            'note' => $this->basePayment->note ? $this->basePayment->note . "\n" . $note : $note,
            'modified_at' => new DateTime(),
            'upgrade_type' => self::TYPE,
        ]);
        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'next_subscription_type_id' => $this->targetSubscriptionType->id,
            'custom_amount' => $this->getFutureChargePrice(),
            'note' => $note . "\n(" . time() . ')',
        ]);

        $this->splitSubscription($this->baseSubscription->end_time);

        $this->hermesEmitter->emit(new HermesMessage('subscription-split', $eventParams));
        return true;
    }

    public function getFutureChargePrice(): float
    {
        if ($this->monthlyFix) {
            $subscriptionType = $this->baseSubscription->subscription_type;
            if ($subscriptionType->next_subscription_type_id) {
                $subscriptionType = $subscriptionType->next_subscription_type;
            }
            $newDayPrice = ($subscriptionType->price / $this->targetSubscriptionType->length) + ($this->monthlyFix / 31);
            return round($newDayPrice * $this->targetSubscriptionType->length, 2);
        }

        $subscriptionType = $this->targetSubscriptionType;
        if ($subscriptionType->next_subscription_type_id) {
            $subscriptionType = $subscriptionType->next_subscription_type;
        }
        return $subscriptionType->price;
    }
}
