<?php

namespace Crm\UpgradesModule\Models\Upgrade;

use Nette\Database\Table\ActiveRow;

class SpecificUserSubscriptions implements UpgradeableSubscriptionsInterface
{
    private $subscriptions;

    public function setSpecificSubscriptions($userId, ActiveRow ...$subscriptions): self
    {
        $this->subscriptions[$userId] = $subscriptions;
        return $this;
    }

    public function getSubscriptions($userId): array
    {
        return $this->subscriptions[$userId] ?? [];
    }
}
