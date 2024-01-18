<?php

namespace Crm\UpgradesModule\Models\Upgrade;

use Nette\Database\Table\ActiveRow;

interface UpgraderInterface
{
    public function getType(): string;

    public function isUsable(): bool;

    public function applyConfig(array $config): UpgraderInterface;

    public function setBaseSubscription(ActiveRow $baseSubscription): UpgraderInterface;

    public function getBaseSubscription(): ?ActiveRow;

    public function setBasePayment(ActiveRow $basePayment): UpgraderInterface;

    public function getBasePayment(): ?ActiveRow;

    public function setTargetSubscriptionType(ActiveRow $targetSubscriptionType): UpgraderInterface;

    public function getTargetSubscriptionType(): ?ActiveRow;

    /**
     * setNow sets the base date for upgrade calculation that should be used instead of current time.
     *
     * @param \DateTime $now
     * @return UpgraderInterface
     */
    public function setNow(\DateTime $now): UpgraderInterface;

    public function now(): \DateTime;

    /**
     * @return boolean|ActiveRow
     */
    public function upgrade();

    /**
     * Profitability should generate float that indicates how much value for money user gets. Higher the result, more
     * value user is provided.
     *
     * The returned value should be used to compare different upgrade configurations of same upgrader type. Value is
     * not expected to be used to compare different upgrader types.
     *
     * @return float
     */
    public function profitability(): float;
}
