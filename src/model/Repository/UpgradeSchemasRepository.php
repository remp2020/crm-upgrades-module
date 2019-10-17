<?php

namespace Crm\UpgradesModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;

class UpgradeSchemasRepository extends Repository
{
    protected $tableName = 'upgrade_schemas';

    public function add($name)
    {
        return $this->insert([
            'name' => $name,
        ]);
    }

    public function all()
    {
        return $this->getTable();
    }

    public function findByName($name)
    {
        return $this->getTable()->where(['name' => $name])->fetch();
    }

    public function allForSubscriptionType(IRow $subscriptionType)
    {
        return $this->getTable()->where([
            ':subscription_type_upgrade_schemas.subscription_type_id' => $subscriptionType->id
        ]);
    }
}
