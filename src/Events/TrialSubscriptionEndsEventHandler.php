<?php

declare(strict_types=1);

namespace Crm\UpgradesModule\Events;

use Crm\ApplicationModule\Models\NowTrait;
use Crm\SubscriptionsModule\Events\SubscriptionEndsEvent;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UpgradesModule\Models\Upgrade\TrialUpgrade;
use Crm\UpgradesModule\Models\Upgrade\UpgraderFactory;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Nette\Utils\DateTime;
use Nette\Utils\Json;

class TrialSubscriptionEndsEventHandler extends AbstractListener
{
    use NowTrait;

    public function __construct(
        private readonly SubscriptionsRepository $subscriptionsRepository,
        private readonly UpgraderFactory $upgraderFactory,
        private readonly SubscriptionMetaRepository $subscriptionMetaRepository,
    ) {
    }

    public function handle(EventInterface $event)
    {
        if (!$event instanceof SubscriptionEndsEvent) {
            throw new \Exception("Invalid type of event received, 'SubscriptionEndsEvent' expected: " . get_class($event));
        }

        $trialSubscription = $event->getSubscription();

        $trialAccepted = $this->subscriptionMetaRepository
            ->getMeta($trialSubscription, TrialUpgrade::SUBSCRIPTION_META_TRIAL_ACCEPTED)
            ->fetch();
        if (!isset($trialAccepted)) {
            // not a trial, we can ignore it
            return;
        }

        $trialExpired = $this->subscriptionMetaRepository
            ->getMeta($trialSubscription, TrialUpgrade::SUBSCRIPTION_META_TRIAL_EXPIRED)
            ->fetch();
        if (isset($trialExpired)) {
            // already expired, we can ignore it
            return;
        }

        // attempt to extend the trial subscription

        /** @var TrialUpgrade $trialUpgrade */
        $trialUpgrade = $this->upgraderFactory->getUpgraders()[TrialUpgrade::TYPE];

        $upgradeConfig = Json::decode(
            json: $this->subscriptionMetaRepository->getMeta(
                subscription: $trialSubscription,
                key: TrialUpgrade::SUBSCRIPTION_META_TRIAL_UPGRADE_CONFIG,
            )->fetch()->value,
            forceArrays: true,
        );
        $trialUpgrade->applyConfig($upgradeConfig);

        $latestEligibleSubscription = $trialUpgrade->getLatestEligibleSubscription($trialSubscription->user_id)->fetch();
        if (!$latestEligibleSubscription) {
            // no renewal, we expire the trial right here, so it can't be resurrected later
            $this->subscriptionMetaRepository->setMeta(
                subscription: $trialSubscription,
                key: TrialUpgrade::SUBSCRIPTION_META_TRIAL_EXPIRED,
                value: $this->getNow()->format(DATE_RFC3339),
            );

            return;
        }

        // determine extended end of the trial subscription
        $latestTrialEndMeta = $this->subscriptionMetaRepository
            ->getMeta($trialSubscription, TrialUpgrade::SUBSCRIPTION_META_TRIAL_LATEST_END_TIME)
            ->fetch();
        $latestTrialEnd = DateTime::from($latestTrialEndMeta->value);

        if ($trialSubscription->end_time < $latestTrialEnd) {
            $newTrialEnd = $latestTrialEnd;
            if ($latestEligibleSubscription->end_time < $newTrialEnd) {
                $newTrialEnd = $latestEligibleSubscription->end_time;
            }

            $this->subscriptionsRepository->update($trialSubscription, [
                'end_time' => $newTrialEnd,
            ]);
        } else {
            // We are past the point of trial period. Since the trial wasn't cancelled, we're going to finalize
            // the upgrade.
            $trialUpgrade->finalize($trialSubscription);
        }
    }
}
