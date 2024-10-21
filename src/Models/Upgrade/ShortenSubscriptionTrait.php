<?php


namespace Crm\UpgradesModule\Models\Upgrade;

use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use DateTime;

trait ShortenSubscriptionTrait
{
    /**
     * calculateShortenedEndTime calculates remaining number of days for $targetSubscriptionType based on
     * $baseSubscriptions's remaining days and its price.
     *
     * @return DateTime
     * @throws \Exception
     */
    public function calculateShortenedEndTime(): DateTime
    {
        if ($this->getBaseSubscription()->end_time < $this->now()) {
            return $this->getBaseSubscription()->end_time;
        }

        $subscriptionPaymentItems = $this->getBasePayment()
            ->related('payment_items')
            ->where('type = ?', SubscriptionTypePaymentItem::TYPE);

        // try to use subscription payment items if possible
        $subscriptionAmount = 0;
        foreach ($subscriptionPaymentItems as $subscriptionPaymentItem) {
            $subscriptionAmount += $subscriptionPaymentItem->count * $subscriptionPaymentItem->amount;
        }

        // if there were none, let's use whole payment amount as a base
        if ($subscriptionAmount === 0) {
            $subscriptionAmount = $this->getBasePayment()->amount;
        }

        // We're intentionally not using DateTime::diff()->days, because it would report incorrect number of days
        // if the subscription is affected by the daylight savings time.
        // We can go back to DateTime::diff()->days once SubscriptionsRepository::moveSubscription uses day-based
        // interval instead of seconds-based interval. That caused issues in PHP 8.0.
        $subscriptionSeconds = $this->getBaseSubscription()->end_time->getTimestamp() - $this->getBaseSubscription()->start_time->getTimestamp();
        $subscriptionDays = intdiv($subscriptionSeconds, 60 * 60 * 24);
        if ($subscriptionDays === 0) {
            return $this->getBaseSubscription()->end_time;
        }

        $dayPrice = $subscriptionAmount / $subscriptionDays;
        $upgradedSubscriptionStart = $this->calculateShortenedStartTime();
        $remainingSeconds = $this->getBaseSubscription()->end_time->getTimestamp() - $upgradedSubscriptionStart->getTimestamp();
        $savedFromActual = $remainingSeconds / 60 / 60 / 24 * $dayPrice;

        // calculate daily price of target subscription type
        if (isset($this->monthlyFix)) {
            $toSubscriptionPrice = $this->getBaseSubscription()->subscription_type->price;
            $newDayPrice = $toSubscriptionPrice / $this->targetSubscriptionType->length + ($this->monthlyFix / 31);
        } else {
            $toSubscriptionPrice = $this->targetSubscriptionType->price;
            $newDayPrice = $toSubscriptionPrice / $this->targetSubscriptionType->length;
        }

        // determine how many days of new subscription type we can "buy" with what we "saved" from remaining days of current subscription
        $lengthInSeconds = ceil($savedFromActual / $newDayPrice * 24 * 60 * 60);
        return $upgradedSubscriptionStart->add(new \DateInterval("PT{$lengthInSeconds}S"));
    }

    public function calculateShortenedStartTime(): DateTime
    {
        return max($this->now(), clone $this->getBaseSubscription()->start_time);
    }
}
