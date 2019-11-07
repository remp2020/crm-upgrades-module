<?php


namespace Crm\UpgradesModule\Upgrade;

use Crm\SubscriptionsModule\Events\SubscriptionUpdatedEvent;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UpgradesModule\Events\SubscriptionShortenedEvent;
use DateTime;

trait SplitSubscriptionTrait
{
    private function splitSubscription(DateTime $newSubscriptionEndTime, DateTime $newSubscriptionStartTime = null)
    {
        if (!$newSubscriptionStartTime) {
            $newSubscriptionStartTime = $this->now();
        }

        // create new upgraded subscription starting now ending at provided end time
        $newSubscription = $this->subscriptionsRepository->add(
            $this->targetSubscriptionType,
            $this->basePayment->payment_gateway->is_recurrent,
            $this->basePayment->user,
            SubscriptionsRepository::TYPE_UPGRADE,
            $newSubscriptionStartTime,
            $newSubscriptionEndTime,
            "Split upgrade from subscription type {$this->baseSubscription->subscription_type->name} to {$this->targetSubscriptionType->name}",
            $this->baseSubscription->address,
            false
        );

        // stop old subscription immediately (order is important, new subscription has to be running before we stop this)
        $originalEndTime = $this->baseSubscription->end_time;
        $this->subscriptionsRepository->update($this->baseSubscription, [
            'end_time' => $newSubscriptionStartTime,
            'note' => '[upgrade] Original end_time ' . $originalEndTime,
        ]);

        $this->emitter->emit(new SubscriptionShortenedEvent($this->getBaseSubscription(), $originalEndTime, $newSubscription));
        $this->emitter->emit(new SubscriptionUpdatedEvent($this->baseSubscription));

        if ($this->now() <= new DateTime()) {
            $this->subscriptionsRepository->setExpired($this->baseSubscription);
            $this->subscriptionsRepository->setStarted($newSubscription);
        }

        $this->baseSubscription = $this->subscriptionsRepository->find(($this->baseSubscription->id));
        return $newSubscription;
    }
}
