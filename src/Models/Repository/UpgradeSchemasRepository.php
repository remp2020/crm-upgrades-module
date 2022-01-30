<?php

namespace Crm\UpgradesModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\ActiveRow;

class UpgradeSchemasRepository extends Repository
{
    protected $tableName = 'upgrade_schemas';

    final public function add($name)
    {
        return $this->insert([
            'name' => $name,
        ]);
    }

    final public function all()
    {
        return $this->getTable();
    }

    final public function findByName($name)
    {
        return $this->getTable()->where(['name' => $name])->fetch();
    }

    final public function allForSubscriptionType(ActiveRow $subscriptionType)
    {
        return $this->getTable()->where([
            ':subscription_type_upgrade_schemas.subscription_type_id' => $subscriptionType->id
        ]);
    }
}
