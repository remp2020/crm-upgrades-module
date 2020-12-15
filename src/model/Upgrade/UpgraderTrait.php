<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\UpgradesModule\DataProvider\TrackerDataProviderInterface;
use Nette\Database\Table\IRow;

/**
 * @property DataProviderManager $dataProviderManager
 */
trait UpgraderTrait
{
    private $baseSubscription;

    private $basePayment;

    private $nextSubscriptionType;

    private $targetSubscriptionType;

    private $now;

    public function setBaseSubscription(IRow $baseSubscription): UpgraderInterface
    {
        $this->baseSubscription = $baseSubscription;

        if ($baseSubscription->subscription_type->next_subscription_type_id) {
            $this->nextSubscriptionType = $baseSubscription->subscription_type->next_subscription_type;
        } else {
            $this->nextSubscriptionType = $baseSubscription->subscription_type;
        }

        return $this;
    }

    public function getBaseSubscription(): ?IRow
    {
        return $this->baseSubscription;
    }

    public function getNextSubscriptionType(): ?IRow
    {
        return $this->nextSubscriptionType;
    }

    public function setBasePayment(IRow $basePayment): UpgraderInterface
    {
        $this->basePayment = $basePayment;
        return $this;
    }

    public function getBasePayment(): ?IRow
    {
        return $this->basePayment;
    }

    public function setTargetSubscriptionType(IRow $targetSubscriptionType): UpgraderInterface
    {
        $this->targetSubscriptionType = $targetSubscriptionType;
        return $this;
    }

    public function getTargetSubscriptionType(): ?IRow
    {
        return $this->targetSubscriptionType;
    }

    /**
     * getToSubscriptionTypeItem returns subscription type item to be used within upgrade
     *
     * The primary use case is for payment items and later invoicing.
     * This feature expects that upgrade always adds just club to the existing subscription, not print.
     * Worst case scenario is that we pay more VAT than we should if something is misconfigured.
     *
     * @return bool|\Nette\Database\Table\ActiveRow|null
     */
    public function getTargetSubscriptionTypeItem()
    {
        $upgradedItem = null;
        foreach ($this->targetSubscriptionType->related('subscription_type_items') as $item) {
            if (!$upgradedItem || $upgradedItem->vat < $item->vat) {
                $upgradedItem = $item;
            }
        }
        return $upgradedItem;
    }

    public function setNow(\DateTime $now): UpgraderInterface
    {
        $this->now = $now;
        return $this;
    }

    public function now(): \DateTime
    {
        return $this->now ? clone $this->now : new \DateTime();
    }

    public function getTrackerParams(): array
    {
        $trackerParams = [];
        /** @var TrackerDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders(
            'upgrades.dataprovider.tracker',
            TrackerDataProviderInterface::class
        );
        foreach ($providers as $provider) {
            $trackerParams[] = $provider->provide();
        }

        return array_merge([], ...$trackerParams);
    }
}
