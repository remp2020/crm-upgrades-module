<?php

namespace Crm\UpgradesModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;
use Nette\Utils\Json;

class UpgradeOptionsRepository extends Repository
{
    protected $tableName = 'upgrade_options';

    public function add(IRow $upgradeSchema, string $type, ?array $config = [], ?IRow $subscriptionType = null)
    {
        return $this->insert([
            'upgrade_schema_id' => $upgradeSchema->id,
            'type' => $type,
            'config' => $config ? Json::encode($config) : null,
            'subscription_type_id' => $subscriptionType->id ?? null,
        ]);
    }

    public function findForSchema($upgradeSchemaId, $type, $config = null, $subscriptionTypeId = null)
    {
        $option = $this->getTable()
            ->where([
                'upgrade_schema_id' => $upgradeSchemaId,
                'type' => $type,
            ]);

        if ($config) {
            $option->where('JSON_CONTAINS(config, ?)', Json::encode($config));
        }
        if ($subscriptionTypeId) {
            $option->where(['subscription_type_id' => $subscriptionTypeId]);
        }

        return $option->fetch();
    }
}
