<?php

namespace Crm\UpgradesModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;

class SubscriptionUpgradesRepository extends Repository
{
    protected $tableName = 'subscription_upgrades';

    final public function add(ActiveRow $baseSubscription, ActiveRow $upgradedSubscription, string $type)
    {
        return $this->insert([
            'base_subscription_id' => $baseSubscription->id,
            'upgraded_subscription_id' => $upgradedSubscription->id,
            'type' => $type,
            'created_at' => new \DateTime(),
        ]);
    }
}
