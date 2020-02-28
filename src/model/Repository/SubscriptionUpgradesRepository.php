<?php

namespace Crm\UpgradesModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;

class SubscriptionUpgradesRepository extends Repository
{
    protected $tableName = 'subscription_upgrades';

    public function add(IRow $baseSubscription, IRow $upgradedSubscription, string $type)
    {
        return $this->insert([
            'base_subscription_id' => $baseSubscription,
            'upgraded_subscription_id' => $upgradedSubscription,
            'type' => $type,
            'created_at' => new \DateTime(),
        ]);
    }
}
