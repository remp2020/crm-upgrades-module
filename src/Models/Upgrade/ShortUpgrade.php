<?php

namespace Crm\UpgradesModule\Models\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UpgradesModule\Repositories\SubscriptionUpgradesRepository;
use League\Event\Emitter;
use Nette\Utils\DateTime;

class ShortUpgrade implements UpgraderInterface, SubsequentUpgradeInterface
{
    const TYPE = 'short';

    use UpgraderTrait;
    use ShortenSubscriptionTrait;
    use SplitSubscriptionTrait;

    private $monthlyFix;

    public function __construct(
        private PaymentsRepository $paymentsRepository,
        private RecurrentPaymentsRepository $recurrentPaymentsRepository,
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
        if ($this->basePayment->payment_gateway->is_recurrent) {
            $paymentToCheck = $this->basePayment;
            if ($paymentToCheck->subscription) {
                $originalSubscription = $this->loadOriginalUpgradedSubscriptionFromSubscription($paymentToCheck->subscription);
                $originalPayment = $originalSubscription?->related('payments')->fetch();
                if ($originalPayment) {
                    $paymentToCheck = $originalPayment;
                }
            }

            $recurrent = $this->recurrentPaymentsRepository->recurrent($paymentToCheck);
            if (!$this->recurrentPaymentsRepository->isStopped($recurrent)) {
                return false;
            }
        }
        $shortenedEndTime = $this->calculateShortenedEndTime();
        if ($this->now()->diff($shortenedEndTime)->days < 14) {
            return false;
        }

        return true;
    }

    public function applyConfig(array $config): UpgraderInterface
    {
        if (isset($config['monthly_fix'])) {
            $monthlyFix = filter_var($config['monthly_fix'], FILTER_VALIDATE_FLOAT);
            if ($monthlyFix === false) {
                throw new \Exception('Invalid value provided in ShortUpgrade config "monthly_fix": ' . $config['monthly_fix']);
            }
            $this->monthlyFix = $monthlyFix;
        }
        $this->config = $config;
        return $this;
    }

    public function profitability(): float
    {
        return $this->calculateShortenedEndTime()->getTimestamp() - $this->now()->getTimestamp();
    }

    public function upgrade(): bool
    {
        $eventParams = [
            'user_id' => $this->basePayment->user_id,
            'sales_funnel_id' => 'upgrade',
            'transaction_id' => self::TYPE,
            'product_ids' => [(string)$this->basePayment->subscription_type_id],
            'revenue' => 0,
        ];
        $this->hermesEmitter->emit(
            new HermesMessage(
                'sales-funnel',
                array_merge(['type' => 'payment'], $eventParams, $this->getTrackerParams()),
            ),
            HermesMessage::PRIORITY_DEFAULT,
        );

        $startTime = $this->calculateShortenedStartTime();
        $endTime = $this->calculateShortenedEndTime();

        $upgradedSubscription = $this->splitSubscription($endTime, $startTime);

        $this->paymentsRepository->update($this->basePayment, [
            'upgrade_type' => self::TYPE,
            'modified_at' => new DateTime(),
        ]);

        $this->subscriptionUpgradesRepository->add(
            $this->getBaseSubscription(),
            $upgradedSubscription,
            self::TYPE,
        );

        $this->hermesEmitter->emit(
            new HermesMessage(
                'subscription-split',
                array_merge($eventParams, $this->getTrackerParams()),
            ),
            HermesMessage::PRIORITY_DEFAULT,
        );

        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($this->basePayment);
        if ($recurrentPayment && $recurrentPayment->state === RecurrentPaymentStateEnum::UserStop->value) {
            $this->recurrentPaymentsRepository->stoppedBySystem($recurrentPayment->id);
        }

        $this->subsequentUpgrade();
        return true;
    }

    public function getFutureChargePrice(): float
    {
        if ($this->monthlyFix) {
            $subscriptionType = $this->baseSubscription->subscription_type;
            // TODO [crm#2938]: no need to check trials when upgrading
            if ($subscriptionType->next_subscription_type_id) {
                $subscriptionType = $subscriptionType->next_subscription_type;
            }
            $newDayPrice = ($subscriptionType->price / $this->targetSubscriptionType->length) + ($this->monthlyFix / 31);
            return round($newDayPrice * $this->targetSubscriptionType->length, 2);
        }

        $subscriptionType = $this->targetSubscriptionType;
        // TODO [crm#2938]: no need to check trials when upgrading
        if ($subscriptionType->next_subscription_type_id) {
            $subscriptionType = $subscriptionType->next_subscription_type;
        }
        return $subscriptionType->price;
    }
}
