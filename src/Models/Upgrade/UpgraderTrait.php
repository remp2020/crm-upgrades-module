<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UpgradesModule\DataProvider\TrackerDataProviderInterface;
use Nette\Database\Table\IRow;

/**
 * @property DataProviderManager $dataProviderManager
 */
trait UpgraderTrait
{
    private $baseSubscription;

    private $followingSubscriptions = [];

    /** @var UpgraderInterface|null */
    private $subsequentUpgrader;

    private $config;

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

        // get chain of following subscriptions; include those possibly already upgraded
        $fs = $this->paymentsRepository->followingSubscriptions(
            $baseSubscription,
            [$this->targetSubscriptionType->id]
        );

        foreach ($fs as $followingSubscription) {
            if ($followingSubscription->type !== SubscriptionsRepository::TYPE_UPGRADE // ignore already upgraded
                && $followingSubscription->subscription_type_id !== $this->targetSubscriptionType->id // ignore those with upgraded subscription type
            ) {
                $this->followingSubscriptions[] = $followingSubscription;
            }
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

    public function setSubsequentUpgrader(?UpgraderInterface $subsequentUpgrader)
    {
        $this->subsequentUpgrader = $subsequentUpgrader;
    }

    public function getSubsequentUpgrader(): ?UpgraderInterface
    {
        return $this->subsequentUpgrader;
    }

    public function subsequentUpgrade()
    {
        if (!$this->subsequentUpgrader) {
            return;
        }
        if (!count($this->followingSubscriptions)) {
            return;
        }

        $recurrentPayments = [];
        foreach ($this->followingSubscriptions as $subscription) {
            $upgrader = clone $this->subsequentUpgrader;
            $subscription = $this->subscriptionsRepository->find($subscription->id); // force refresh
            $originalEndTime = clone $subscription->end_time;
            $payment = $this->paymentsRepository->subscriptionPayment($subscription);

            $upgrader
                ->setTargetSubscriptionType($this->getTargetSubscriptionType())
                ->setBaseSubscription($subscription)
                ->setBasePayment($payment)
                ->applyConfig($this->config);

            if ($this->now) {
                $upgrader->setNow($this->now);
            }
            if ($upgrader instanceof SubsequentUpgradeInterface) {
                // Don't let subsequent upgrader to run subsequent upgrades, we don't need to go deeper.
                $upgrader->setSubsequentUpgrader(null);
            }

            // Intentional disable of transaction. It should be started by parent upgrader; we don't want to nest them.
            $upgrader->upgrade(false);

            $rp = $this->recurrentPaymentsRepository->recurrent($payment);
            $statesToHandle = [
                RecurrentPaymentsRepository::STATE_ACTIVE,
                RecurrentPaymentsRepository::STATE_USER_STOP,
            ];
            if ($rp && in_array($rp->state, $statesToHandle, true)) {
                $subscriptionUpgrade = $subscription->related('subscription_upgrades')->fetch();
                $diff = $originalEndTime->diff($subscriptionUpgrade->upgraded_subscription->end_time);
                $recurrentPayments[$rp->id] = $diff;
            }
        }

        foreach ($recurrentPayments as $rpId => $diff) {
            $rp = $this->recurrentPaymentsRepository->find($rpId);
            $this->recurrentPaymentsRepository->update($rp, [
                'charge_at' => (clone $rp->charge_at)->add($diff),
                'next_subscription_type_id' => $this->getTargetSubscriptionType()->id,
            ]);
        }
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
