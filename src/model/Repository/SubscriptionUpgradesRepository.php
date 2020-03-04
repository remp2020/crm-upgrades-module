<?php

namespace Crm\UpgradesModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;

class SubscriptionUpgradesRepository extends Repository
{
    protected $tableName = 'subscription_upgrades';

    final public function add(IRow $baseSubscription, IRow $upgradedSubscription, string $type)
    {
        return $this->insert([
            'base_subscription_id' => $baseSubscription->id,
            'upgraded_subscription_id' => $upgradedSubscription->id,
            'type' => $type,
            'created_at' => new \DateTime(),
        ]);
    }
}
