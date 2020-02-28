<?php

use Phinx\Migration\AbstractMigration;

class SubscriptionUpgrades extends AbstractMigration
{
    public function up()
    {
        $this->table('subscription_upgrades')
            ->addColumn('base_subscription_id', 'integer', ['null' => false])
            ->addColumn('upgraded_subscription_id', 'integer', ['null' => false])
            ->addColumn('type', 'string', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addForeignKey('base_subscription_id', 'subscriptions')
            ->addForeignKey('upgraded_subscription_id', 'subscriptions')
            ->create();

        $upgradedSubscriptionsSql = <<<SQL
SELECT id
FROM subscriptions
WHERE subscriptions.type = 'upgrade'
SQL;
        $upgradedSubscriptions = $this->fetchAll($upgradedSubscriptionsSql);

        // iterating one by one for the sake of db performance during joins
        foreach ($upgradedSubscriptions as $upgradedSubscription) {
            $migrateSql = <<<SQL
INSERT INTO subscription_upgrades (base_subscription_id, upgraded_subscription_id, type, created_at)
SELECT
  base_subscription.id,
  subscriptions.id,
--  subscriptions.user_id,
--  base_payment.id as base_payment_id,
--  upgrade_payment.id as upgrade_payment_id,
--  recurrent_upgrade_payment.id as recurrent_payment_id,
--  base_payment.upgrade_type,
  CASE
    WHEN base_payment.upgrade_type = 'free_recurrent' THEN 'free_recurrent'
    WHEN base_payment.upgrade_type = 'short' THEN 'short'
    WHEN recurrent_upgrade_payment.id IS NOT NULL THEN 'paid_recurrent'
    WHEN upgrade_payment.id IS NOT NULL then 'paid_extend'
    ELSE 'short' -- if no payment was matched, it had to be short
  END as resolved_upgrade_type,
  subscriptions.created_at

FROM subscriptions
INNER JOIN subscriptions base_subscription
  ON base_subscription.user_id = subscriptions.user_id
  AND base_subscription.end_time = subscriptions.start_time
  AND base_subscription.type = 'regular'
INNER JOIN payments base_payment
  ON base_payment.subscription_id = base_subscription.id
LEFT JOIN payments upgrade_payment
  ON upgrade_payment.subscription_id = subscriptions.id
LEFT JOIN recurrent_payments recurrent_upgrade_payment
  ON recurrent_upgrade_payment.parent_payment_id = upgrade_payment.id
WHERE subscriptions.id = {$upgradedSubscription['id']}
SQL;
            $this->execute($migrateSql);
        }
    }

    public function down()
    {
        $this->table('subscription_upgrades')->drop()->update();
    }
}
