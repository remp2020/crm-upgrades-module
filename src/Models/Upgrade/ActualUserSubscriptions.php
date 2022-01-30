<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;

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
