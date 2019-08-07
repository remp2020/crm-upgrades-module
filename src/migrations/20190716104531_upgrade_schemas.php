<?php

use Phinx\Migration\AbstractMigration;

class UpgradeSchemas extends AbstractMigration
{
    public function change()
    {
        $this->table("upgrade_schemas")
            ->addColumn("name", "string", ["null" => false])
            ->create();

        $this->table("upgrade_options")
            ->addColumn("upgrade_schema_id", "integer", ["null" => false])
            ->addColumn("subscription_type_id", "integer", ["null" => true])
            ->addColumn("type", "string", ["null" => false])
            ->addColumn("config", "json", ["null" => false])
            ->addForeignKey("upgrade_schema_id", "upgrade_schemas")
            ->addForeignKey("subscription_type_id", "subscription_types")
            ->create();

        $this->table("subscription_type_upgrade_schemas")
            ->addColumn("subscription_type_id", "integer")
            ->addColumn("upgrade_schema_id", "integer")
            ->addForeignKey("subscription_type_id", "subscription_types")
            ->addForeignKey("upgrade_schema_id", "upgrade_schemas")
            ->addIndex(['subscription_type_id', 'upgrade_schema_id'], ['unique' => true])
            ->create();

        // match upgrade types with types within upgrader classes (other two were fine)
        $sql = <<<SQL
UPDATE payments SET upgrade_type = 'paid_recurrent' WHERE upgrade_type = 'recurrent';
UPDATE payments SET upgrade_type = 'free_recurrent' WHERE upgrade_type = 'recurrent_free';
SQL;

        $this->execute($sql);

        // add upgrade_type to payments
        if(!$this->table('payments')->hasColumn('upgrade_type')) {
            $this->table('payments')
                ->addColumn('upgrade_type', 'string', ['null' => true])
                ->update();
        }

        // remove subscription_type_upgrades for cases where it was created by subscriptions module init migration
        if ($this->table("subscription_types_upgrades")->exists()) {
            $this->table("subscription_types_upgrades")->drop()->update();
        }
    }
}
