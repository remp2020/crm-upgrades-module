<?php


namespace Crm\UpgradesModule\Upgrade;

use Crm\SubscriptionsModule\Events\SubscriptionStartsEvent;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Utils\DateTime;

trait SplitSubscriptionTrait
{
    private function splitSubscription(DateTime $newSubscriptionEndTime)
    {
        $changeTime = new DateTime();

        // create new upgraded subscription starting now ending at provided end time
        $newSubscription = $this->subscriptionsRepository->add(
            $this->targetSubscriptionType,
            $this->basePayment->payment_gateway->is_recurrent,
            $this->basePayment->user,
            SubscriptionsRepository::TYPE_UPGRADE,
            $changeTime,
            $newSubscriptionEndTime,
            "Split upgrade from subscription type {$this->baseSubscription->subscription_type->name} to {$this->targetSubscriptionType->name}",
            $this->baseSubscription->address,
            false
        );

        // stop old subscription immediately (order is important, new subscription has to be running before we stop this)
        $this->subscriptionsRepository->setExpired(
            $this->baseSubscription,
            $changeTime,
            '[upgrade] Original end_time ' . $this->baseSubscription->end_time
        );

        $this->baseSubscription = $this->subscriptionsRepository->find(($this->baseSubscription->id));

        $this->subscriptionsRepository->update($newSubscription, [
            'internal_status' => SubscriptionsRepository::INTERNAL_STATUS_ACTIVE,
        ]);

        $this->emitter->emit(new SubscriptionStartsEvent($newSubscription));

        return $newSubscription;
    }
}
