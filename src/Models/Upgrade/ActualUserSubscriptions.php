<?php

namespace Crm\UpgradesModule\Models\Upgrade;

use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;

class ActualUserSubscriptions implements UpgradeableSubscriptionsInterface
{
    private $subscriptionsRepository;

    public function __construct(SubscriptionsRepository $subscriptionsRepository)
    {
        $this->subscriptionsRepository = $subscriptionsRepository;
    }

    public function getSubscriptions($userId): array
    {
        return $this->subscriptionsRepository->actualUserSubscriptions($userId)->fetchAll();
    }
}
