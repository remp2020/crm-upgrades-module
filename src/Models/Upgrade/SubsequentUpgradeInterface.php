<?php

namespace Crm\UpgradesModule\Upgrade;

interface SubsequentUpgradeInterface
{
    public function setSubsequentUpgrader(?UpgraderInterface $subsequentUpgrader);

    public function getSubsequentUpgrader(): ?UpgraderInterface;

    /**
     * subsequentUpgrade executes the upgrade by previously-set "subsequentUpgrader"
     * on all of the available "followingSubscriptions".
     */
    public function subsequentUpgrade();

    /**
     * getFollowingSubscriptions returns list of subscriptions directly continuing after the baseSubscription
     * set on the upgrader. The returned array should contain chain of subscriptions ascendingly sorted
     * by their start time.
     */
    public function getFollowingSubscriptions(): array;
}
