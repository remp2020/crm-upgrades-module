<?php

namespace Crm\UpgradesModule\Upgrade;

use Nette\Database\Table\IRow;

interface UpgradeableSubscriptionsInterface
{
    /**
     * @param $userId
     * @return IRow[]
     */
    public function getSubscriptions($userId): array;
}
