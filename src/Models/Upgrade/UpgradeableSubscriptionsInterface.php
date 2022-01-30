<?php

namespace Crm\UpgradesModule\Upgrade;

use Nette\Database\Table\ActiveRow;

interface UpgradeableSubscriptionsInterface
{
    /**
     * @param $userId
     * @return ActiveRow[]
     */
    public function getSubscriptions($userId): array;
}
