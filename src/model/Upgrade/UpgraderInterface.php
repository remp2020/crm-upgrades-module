<?php

namespace Crm\UpgradesModule\Upgrade;

use Nette\Database\Table\IRow;

interface UpgraderInterface
{
    public function getType(): string;

    public function isUsable(): bool;

    public function applyConfig(array $config): UpgraderInterface;

    public function setBaseSubscription(IRow $baseSubscription): UpgraderInterface;

    public function getBaseSubscription(): ?IRow;

    public function setBasePayment(IRow $basePayment): UpgraderInterface;

    public function getBasePayment(): ?IRow;

    public function setTargetSubscriptionType(IRow $targetSubscriptionType): UpgraderInterface;

    public function getTargetSubscriptionType(): ?IRow;

    public function setBrowserId(string $browserId): UpgraderInterface;

    public function getBrowserId(): ?string;

    /**
     * setNow sets the base date for upgrade calculation that should be used instead of current time.
     *
     * @param \DateTime $now
     * @return UpgraderInterface
     */
    public function setNow(\DateTime $now): UpgraderInterface;

    public function now(): \DateTime;

    /**
     * @return boolean|IRow
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
