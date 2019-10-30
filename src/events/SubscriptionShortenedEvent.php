<?php

namespace Crm\UpgradesModule\Events;

use League\Event\AbstractEvent;
use Nette\Database\Table\ActiveRow;

class SubscriptionShortenedEvent extends AbstractEvent
{
    private $subscription;

    private $originalEndTime;

    public function __construct(ActiveRow $subscription, \DateTime $originalEndTime)
    {
        $this->subscription = $subscription;
        $this->originalEndTime = clone $originalEndTime;
    }

    public function getSubscription(): ActiveRow
    {
        return $this->subscription;
    }

    public function getOriginalEndTime(): \DateTime
    {
        return $this->originalEndTime;
    }
}
