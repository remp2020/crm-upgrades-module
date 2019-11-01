<?php

namespace Crm\UpgradesModule\Events;

use League\Event\AbstractEvent;
use Nette\Database\Table\ActiveRow;

class SubscriptionShortenedEvent extends AbstractEvent
{
    private $baseSubscription;

    private $upgradedSubscription;

    private $originalEndTime;

    public function __construct(ActiveRow $baseSubscription, \DateTime $originalEndTime, ActiveRow $upgradedSubscription)
    {
        $this->baseSubscription = $baseSubscription;
        $this->upgradedSubscription = $upgradedSubscription;
        $this->originalEndTime = clone $originalEndTime;
    }

    public function getBaseSubscription(): ActiveRow
    {
        return $this->baseSubscription;
    }

    public function getUpgradedSubscription(): ActiveRow
    {
        return $this->upgradedSubscription;
    }

    public function getOriginalEndTime(): \DateTime
    {
        return $this->originalEndTime;
    }
}
