<?php


namespace Crm\UpgradesModule\Upgrade;

use Crm\SubscriptionsModule\Events\SubscriptionUpdatedEvent;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use DateTime;

trait SplitSubscriptionTrait
{
    private function splitSubscription(DateTime $newSubscriptionEndTime)
    {
        // create new upgraded subscription starting now ending at provided end time
        $newSubscription = $this->subscriptionsRepository->add(
            $this->targetSubscriptionType,
            $this->basePayment->payment_gateway->is_recurrent,
            $this->basePayment->user,
            SubscriptionsRepository::TYPE_UPGRADE,
            $this->now(),
            $newSubscriptionEndTime,
            "Split upgrade from subscription type {$this->baseSubscription->subscription_type->name} to {$this->targetSubscriptionType->name}",
            $this->baseSubscription->address,
            false
        );

        // stop old subscription immediately (order is important, new subscription has to be running before we stop this)
        $this->subscriptionsRepository->update($this->baseSubscription, [
            'end_time' => $this->now(),
            'note' => '[upgrade] Original end_time ' . $this->baseSubscription->end_time,
        ]);
        $this->emitter->emit(new SubscriptionUpdatedEvent($this->baseSubscription));

        if ($this->now() <= new DateTime()) {
            $this->subscriptionsRepository->setExpired($this->baseSubscription);
            $this->subscriptionsRepository->setStarted($newSubscription);
        }

        $this->baseSubscription = $this->subscriptionsRepository->find(($this->baseSubscription->id));
        return $newSubscription;
    }
}
