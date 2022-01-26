<?php

namespace Crm\UpgradesModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;

class SubscriptionTypeUpgradeSchemasRepository extends Repository
{
    protected $tableName = 'subscription_type_upgrade_schemas';

    final public function add(IRow $subscriptionType, IRow $upgradeSchema)
    {
        $data = [
            'subscription_type_id' => $subscriptionType->id,
            'upgrade_schema_id' => $upgradeSchema->id,
        ];

        $row = $this->getTable()->where($data)->fetch();
        if (!$row) {
            $row = $this->getTable()->insert($data);
        }

        return $row;
    }
}
