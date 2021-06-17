<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UpgradesModule\Repository\SubscriptionUpgradesRepository;
use League\Event\Emitter;
use Nette\Utils\DateTime;

class ShortUpgrade implements UpgraderInterface
{
    const TYPE = 'short';

    use UpgraderTrait;
    use ShortenSubscriptionTrait;
    use SplitSubscriptionTrait;

    private $paymentsRepository;

    private $recurrentPaymentsRepository;

    private $subscriptionsRepository;

    private $subscriptionUpgradesRepository;

    private $emitter;

    private $hermesEmitter;

    private $monthlyFix;

    private $dataProviderManager;

    public function __construct(
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionUpgradesRepository $subscriptionUpgradesRepository,
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter,
        DataProviderManager $dataProviderManager
    ) {
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionUpgradesRepository = $subscriptionUpgradesRepository;
        $this->emitter = $emitter;
        $this->hermesEmitter = $hermesEmitter;
        $this->dataProviderManager = $dataProviderManager;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function isUsable(): bool
    {
        if ($this->basePayment->payment_gateway->is_recurrent) {
            $recurrent = $this->recurrentPaymentsRepository->recurrent($this->basePayment);
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
                array_merge(['type' => 'payment'], $eventParams, $this->getTrackerParams())
            ),
            HermesMessage::PRIORITY_DEFAULT
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
            self::TYPE
        );

        $this->hermesEmitter->emit(
            new HermesMessage(
                'subscription-split',
                array_merge($eventParams, $this->getTrackerParams())
            ),
            HermesMessage::PRIORITY_DEFAULT
        );
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
