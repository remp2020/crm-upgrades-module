<?php

namespace Crm\UpgradesModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;

class UpgradeOptionsRepository extends Repository
{
    protected $tableName = 'upgrade_options';

    final public function add(ActiveRow $upgradeSchema, string $type, array $config, ?ActiveRow $subscriptionType = null)
    {
        return $this->insert([
            'upgrade_schema_id' => $upgradeSchema->id,
            'type' => $type,
            'config' => Json::encode($config),
            'subscription_type_id' => $subscriptionType->id ?? null,
        ]);
    }

    final public function findForSchema($upgradeSchemaId, $type, $config = null, $subscriptionTypeId = null)
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
