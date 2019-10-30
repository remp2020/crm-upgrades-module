<?php


namespace Crm\UpgradesModule\Upgrade;

use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
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

        $subscriptionPaymentItems = $this->getBasePayment()
            ->related('payment_items')
            ->where('type = ?', SubscriptionTypePaymentItem::TYPE);

        $subscriptionAmount = 0;
        foreach ($subscriptionPaymentItems as $subscriptionPaymentItem) {
            $subscriptionAmount += $subscriptionPaymentItem->count * $subscriptionPaymentItem->amount;
        }

        $subscriptionDays = $this->baseSubscription->start_time->diff($this->baseSubscription->end_time)->days;
        $dayPrice = $subscriptionAmount / $subscriptionDays;
        $remainingSeconds = $this->baseSubscription->end_time->getTimestamp() - $this->now()->getTimestamp();
        $savedFromActual = $remainingSeconds / 60 / 60 / 24 * $dayPrice;

        // calculate daily price of target subscription type
        if ($this->monthlyFix) {
            $toSubscriptionPrice = $this->baseSubscription->subscription_type->price;
            $newDayPrice = $toSubscriptionPrice / $this->targetSubscriptionType->length + ($this->monthlyFix / 31);
        } else {
            $toSubscriptionPrice = $this->targetSubscriptionType->price;
            $newDayPrice = $toSubscriptionPrice / $this->targetSubscriptionType->length;
        }

        // determine how many days of new subscription type we can "buy" with what we "saved" from remaining days of current subscription
        $lengthInSeconds = ceil($savedFromActual / $newDayPrice * 24 * 60 * 60);
        return $this->now()->add(new \DateInterval("PT{$lengthInSeconds}S"));
    }
}
