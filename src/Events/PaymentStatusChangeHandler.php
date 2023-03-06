<?php

namespace Crm\UpgradesModule\Events;

use Crm\ApplicationModule\NowTrait;
use Crm\PaymentsModule\Events\PaymentEventInterface;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UpgradesModule\Repository\SubscriptionUpgradesRepository;
use Crm\UpgradesModule\Upgrade\PaidExtendUpgrade;
use Crm\UpgradesModule\Upgrade\PaidRecurrentUpgrade;
use Crm\UpgradesModule\Upgrade\SubsequentUpgradeInterface;
use Crm\UpgradesModule\Upgrade\UpgraderFactory;
use Crm\UpgradesModule\UpgradesModule;
use League\Event\AbstractListener;
use League\Event\Emitter;
use League\Event\EventInterface;
use Tracy\Debugger;
use Tracy\ILogger;

class PaymentStatusChangeHandler extends AbstractListener
{
    use NowTrait;

    private $subscriptionsRepository;

    private $paymentsRepository;

    private $subscriptionUpgradesRepository;

    private $paymentMetaRepository;

    private $upgraderFactory;

    private $recurrentPaymentsRepository;

    private $emitter;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionUpgradesRepository $subscriptionUpgradesRepository,
        PaymentMetaRepository $paymentMetaRepository,
        UpgraderFactory $upgraderFactory,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        Emitter $emitter
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionUpgradesRepository = $subscriptionUpgradesRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->upgraderFactory = $upgraderFactory;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->emitter = $emitter;
    }

    public function handle(EventInterface $event)
    {
        if (!$event instanceof PaymentEventInterface) {
            throw new \Exception("Invalid type of event received, 'PaymentEventInterface' expected: " . get_class($event));
        }

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

        if (!in_array($payment->status, [PaymentsRepository::STATUS_PAID, PaymentsRepository::STATUS_PREPAID], true)) {
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

        $changeTime = $this->getNow();

        // check if the subscription we upgrade has any following subscriptions; we might alter them here too
        $followingSubscriptions = $this->paymentsRepository->followingSubscriptions(
            $upgradedSubscription,
            [$payment->subscription_type_id] // we want to include following subscriptions also with upgraded subscription type
        );
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
            UpgradesModule::SUBSCRIPTION_TYPE_UPGRADE,
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

        if ($payment->upgrade_type === PaidExtendUpgrade::TYPE) {
            $basePayment = $this->paymentsRepository->subscriptionPayment($upgradedSubscription);
            $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($basePayment);
            if ($recurrentPayment && $recurrentPayment->state === RecurrentPaymentsRepository::STATE_USER_STOP) {
                $this->recurrentPaymentsRepository->stoppedBySystem($recurrentPayment->id);
            }

            // If this is a "paid extend" upgrade, move following subscriptions of upgraded subscription to the future,
            // so they don't overlap with newly created subscription.
            $endTime = clone $newSubscription->end_time;
            foreach ($followingSubscriptions as $followingSubscription) {
                $movedSubscription = $this->subscriptionsRepository->moveSubscription($followingSubscription, $endTime);
                $endTime = clone $movedSubscription->end_time;
            }

            $this->subsequentUpgrade($upgradedSubscription, $payment->subscription_type);
        }
    }

    protected function subsequentUpgrade($upgradedSubscription, $targetSubscriptionType)
    {
        // PaidExtend upgrade is special, because it redirects user away and then back to confirm the payment.
        // Because of that, we need to prepare upgrader again, so we can do subsequent upgrades.
        $basePayment = $this->paymentsRepository->subscriptionPayment($upgradedSubscription);
        if (!$basePayment) {
            Debugger::log(
                "Unable to upgrade subsequent subscriptions, could not find base payment for subscription: " . $upgradedSubscription->id,
                Debugger::ERROR
            );
            return;
        }

        $upgrader = $this->upgraderFactory->getUpgraders()[PaidExtendUpgrade::TYPE] ?? null;
        if (!($upgrader instanceof SubsequentUpgradeInterface)) {
            return;
        }

        $upgrader
            ->setTargetSubscriptionType($targetSubscriptionType)
            ->setBaseSubscription($upgradedSubscription)
            ->setBasePayment($basePayment)
            ->applyConfig([]); // TODO: pass and apply config from the original paid_extend upgrade

        $upgrader->subsequentUpgrade();
    }
}
