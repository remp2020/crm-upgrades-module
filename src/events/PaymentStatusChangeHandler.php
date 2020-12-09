<?php

namespace Crm\UpgradesModule\Events;

use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UpgradesModule\Repository\SubscriptionUpgradesRepository;
use Crm\UpgradesModule\Upgrade\PaidRecurrentUpgrade;
use League\Event\AbstractListener;
use League\Event\Emitter;
use League\Event\EventInterface;
use Tracy\Debugger;
use Tracy\ILogger;

class PaymentStatusChangeHandler extends AbstractListener
{
    private $subscriptionsRepository;

    private $paymentsRepository;

    private $subscriptionUpgradesRepository;

    private $paymentMetaRepository;

    private $emitter;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionUpgradesRepository $subscriptionUpgradesRepository,
        PaymentMetaRepository $paymentMetaRepository,
        Emitter $emitter
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionUpgradesRepository = $subscriptionUpgradesRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->emitter = $emitter;
    }

    public function handle(EventInterface $event)
    {
        $payment = $event->getPayment();

        if ($payment->subscription_id) {
            return;
        }

        if (!$payment->subscription_type_id) {
            return;
        }

        if ($payment->subscription_type->no_subscription) {
            return;
        }

        if (!in_array($payment->status, [PaymentsRepository::STATUS_PAID, PaymentsRepository::STATUS_PREPAID])) {
            return;
        }

        // if it's not payment generated by upgrade, halt execution (e.g. paid recurrent upgrade, paid extend upgrade)
        if (!$payment->upgrade_type) {
            return;
        }

        $this->upgradeSubscriptionFromPayment($payment, $event);
    }

    public function upgradeSubscriptionFromPayment($payment, $event)
    {
        $upgradedSubscription = null;

        // find upgraded subscription based on the meta value stored during upgrade
        $upgradedSubscriptionMeta = $this->paymentMetaRepository->findByPaymentAndKey($payment, 'upgraded_subscription_id');
        if ($upgradedSubscriptionMeta) {
            $upgradedSubscription = $this->subscriptionsRepository->find($upgradedSubscriptionMeta->value);
        }

        if (!$upgradedSubscription) {
            // fallback in case meta value was not set by upgrader; use actual subscription
            Debugger::log(
                'Upgrade payment without meta information about upgraded subscription: ' . $payment->id . '. ' .
                'Did you set "upgraded_subscription_id" payment meta tag in your upgrader implementation?',
                ILogger::WARNING
            );
            $upgradedSubscription = $this->subscriptionsRepository->actualUserSubscription($payment->user->id);
        }

        if (!$upgradedSubscription) {
            // do nothing here and let PaymentModule handler to create a new subscription
            return;
        }

        $changeTime = new \DateTime();
        $newSubscriptionEndTime = null;

        if ($payment->upgrade_type === PaidRecurrentUpgrade::TYPE) {
            // Paid recurrent lets you pay the amount for upgrade against current subscription. In this case the upgraded
            // subscription should not have standard length, but it should end at the end time of original subscription.
            $newSubscriptionEndTime = $upgradedSubscription->end_time;
        }

        $newSubscription = $this->subscriptionsRepository->add(
            $payment->subscription_type,
            $payment->payment_gateway->is_recurrent,
            true,
            $payment->user,
            SubscriptionsRepository::TYPE_UPGRADE,
            $changeTime,
            $newSubscriptionEndTime,
            "Upgrade from {$upgradedSubscription->subscription_type->name} to {$payment->subscription_type->name}"
        );
        $this->paymentsRepository->update($payment, ['subscription_id' => $newSubscription]);
        $this->subscriptionsRepository->update($upgradedSubscription, ['next_subscription_id' => $newSubscription->id]);

        // First create new subscription and then expire the former subscription
        // E.g. in case bonus subscription is added after each finished subscription, the bonus subscription won't detect there is an extending subscription
        // In such case, we do not want to add a bonus subscription
        $this->subscriptionsRepository->update($upgradedSubscription, [
            'end_time' => $changeTime,
            'note' => '[upgrade] Previously ended on ' . $upgradedSubscription->end_time
        ]);
        $upgradedSubscription = $this->subscriptionsRepository->find($upgradedSubscription->id);

        $this->subscriptionUpgradesRepository->add(
            $upgradedSubscription,
            $newSubscription,
            $payment->upgrade_type
        );
    }
}
