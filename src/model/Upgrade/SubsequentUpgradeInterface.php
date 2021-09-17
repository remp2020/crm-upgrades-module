<?php

namespace Crm\UpgradesModule\Upgrade;

interface SubsequentUpgradeInterface
{
    public function setSubsequentUpgrader(?UpgraderInterface $subsequentUpgrader);

    public function getSubsequentUpgrader(): ?UpgraderInterface;

    public function subsequentUpgrade();
}
