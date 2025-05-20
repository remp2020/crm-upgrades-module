<?php
declare(strict_types=1);

namespace Crm\UpgradesModule\DataProviders;

use Crm\PaymentsModule\DataProviders\BaseSubscriptionDataProviderInterface;
use Crm\UpgradesModule\Repositories\SubscriptionUpgradesRepository;
use Nette\Database\Table\ActiveRow;

final class BaseSubscriptionDataProvider implements BaseSubscriptionDataProviderInterface
{
    public function __construct(
        private readonly SubscriptionUpgradesRepository $subscriptionUpgradesRepository,
    ) {
    }

    public function getPeriodBaseSubscription(ActiveRow $subscription): ?ActiveRow
    {
        return $this->subscriptionUpgradesRepository
            ->findBy('upgraded_subscription_id', $subscription->id)
            ?->base_subscription;
    }
}
