<?php


namespace Crm\UpgradesModule\Upgrade;

use Nette\Utils\DateTime;

trait ShortenSubscriptionTrait
{
    /**
     * calculateShortenedEndTime calculates remaining number of days for $targetSubscriptionType based on
     * $baseSubscriptions's remaining days and its price.
     *
     * @return DateTime
     * @throws \Exception
     */
    public function calculateShortenedEndTime()
    {
        if ($this->baseSubscription->end_time < $this->now()) {
            return $this->baseSubscription->end_time;
        }

        $subscriptionDays = $this->baseSubscription->start_time->diff($this->baseSubscription->end_time)->days;
        $dayPrice = $this->basePayment->amount / $subscriptionDays;
        $saveFromActual = $this->now()->diff($this->baseSubscription->end_time)->days * $dayPrice;
        $saveFromActual = round($saveFromActual, 2);

        // calculate daily price of target subscription type
        if ($this->monthlyFix) {
            $toSubscriptionPrice = $this->baseSubscription->subscription_type->price;
            $newDayPrice = $toSubscriptionPrice / $this->targetSubscriptionType->length + ($this->monthlyFix / 31);
        } else {
            $toSubscriptionPrice = $this->targetSubscriptionType->price;
            $newDayPrice = $toSubscriptionPrice / $this->targetSubscriptionType->length;
        }

        // determine how many days of new subscription type we can "buy" with what we "saved" from remaining days of current subscription
        $length = ceil($saveFromActual / $newDayPrice);
        return $this->now()->add(new \DateInterval("P{$length}D"));
    }
}
