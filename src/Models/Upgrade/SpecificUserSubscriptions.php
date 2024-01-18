<?php

namespace Crm\UpgradesModule\Models\Upgrade;

use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;

class SpecificUserSubscriptions implements UpgradeableSubscriptionsInterface
{
    private $subscriptionsRepository;

    private $subscriptions;

    public function __construct(SubscriptionsRepository $subscriptionsRepository)
    {
        $this->subscriptionsRepository = $subscriptionsRepository;
    }

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
