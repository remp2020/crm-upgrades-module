<?php

namespace Crm\UpgradesModule\Models\Upgrade;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UpgradesModule\Repository\SubscriptionUpgradesRepository;
use League\Event\Emitter;
use Nette\Utils\DateTime;

class FreeRecurrentUpgrade implements UpgraderInterface, SubsequentUpgradeInterface
{
    const TYPE = 'free_recurrent';

    use UpgraderTrait;
    use SplitSubscriptionTrait;

    private $monthlyFix;

    public function __construct(
        private RecurrentPaymentsRepository $recurrentPaymentsRepository,
        private PaymentsRepository $paymentsRepository,
        private SubscriptionsRepository $subscriptionsRepository,
        private SubscriptionUpgradesRepository $subscriptionUpgradesRepository,
        private Emitter $emitter,
        private \Tomaj\Hermes\Emitter $hermesEmitter,
        private DataProviderManager $dataProviderManager,
        private UpgraderFactory $upgraderFactory,
    ) {
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
        if ($recurrent->expires_at !== null && $recurrent->expires_at < $this->now()->modify('+31 days')) {
            return false;
        }
        if ($this->recurrentPaymentsRepository->isStopped($recurrent)) {
            return false;
        }

        $remainingDiff = $this->now()->diff($this->baseSubscription->end_time);
        // allow free upgrade if target subscription type is same price as current remp/novydenik#1098
        if ($remainingDiff->days >= 5 && $this->targetSubscriptionType->price !== $this->baseSubscription->subscription_type->price) {
            return false;
        }

        return true;
    }

    public function applyConfig(array $config): UpgraderInterface
    {
        if (isset($config['monthly_fix'])) {
            $monthlyFix = filter_var($config['monthly_fix'], FILTER_VALIDATE_FLOAT);
            if ($monthlyFix === false) {
                throw new \Exception('Invalid value provided in FreeRecurrentUpgrade config "monthly_fix": ' . $config['monthly_fix']);
            }
            $this->monthlyFix = $monthlyFix;
        }
        $this->config = $config;
        return $this;
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
            'sales_funnel_id' => 'upgrade',
            'transaction_id' => self::TYPE,
            'product_ids' => [(string)$this->basePayment->subscription_type_id],
            'revenue' => 0,
        ];

        $eventParams = array_merge($eventParams, $this->getTrackerParams());

        $this->hermesEmitter->emit(
            new HermesMessage(
                'sales-funnel',
                array_merge(['type' => 'payment'], $eventParams)
            ),
            HermesMessage::PRIORITY_DEFAULT
        );

        $this->paymentsRepository->update($this->basePayment, [
            'note' => $this->basePayment->note ? $this->basePayment->note . "\n" . $note : $note,
            'modified_at' => new DateTime(),
            'upgrade_type' => self::TYPE,
        ]);

        $customAmount = $this->getFutureChargePrice();
        if ($customAmount === $this->targetSubscriptionType->price) {
            $customAmount = null;
        }
        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'next_subscription_type_id' => $this->targetSubscriptionType->id,
            'custom_amount' => $customAmount,
            'note' => $note . "\n(" . time() . ')',
        ]);

        $upgradedSubscription = $this->splitSubscription($this->baseSubscription->end_time);
        $this->subscriptionUpgradesRepository->add(
            $this->getBaseSubscription(),
            $upgradedSubscription,
            $this->getType()
        );

        $this->hermesEmitter->emit(new HermesMessage('subscription-split', $eventParams), HermesMessage::PRIORITY_DEFAULT);

        $this->subsequentUpgrade();
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
