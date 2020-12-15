<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\GatewayFactory;
use Crm\PaymentsModule\Gateways\PaymentInterface;
use Crm\PaymentsModule\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentLogsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Exception;
use League\Event\Emitter;

class PaidRecurrentUpgrade implements UpgraderInterface
{
    const TYPE = 'paid_recurrent';

    use UpgraderTrait;

    private $subscriptionsRepository;

    private $subscriptionTypesRepository;

    private $recurrentPaymentsRepository;

    private $emitter;

    private $gatewayFactory;

    private $hermesEmitter;

    private $paymentLogsRepository;

    private $paymentsRepository;

    private $dataProviderManager;

    /** @var float
     *
     * Fix upgrade price which will alter the calculation of charge price. Instead of standard calculation based on
     * target's subscription price, the new price is calculated as monthly price of current subscription + monthly fix.
     */
    private $monthlyFix;

    /**
     * @var float custom charge price
     *
     * Variable should be set only when the future charge price differs from standard subscription price
     * we're upgrading to. It's meant to be used as custom_amount field of the recurrent payment instance.
     */
    protected $futureChargePrice;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        PaymentsRepository $paymentsRepository,
        GatewayFactory $gatewayFactory,
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter,
        PaymentLogsRepository $paymentLogsRepository,
        DataProviderManager $dataProviderManager
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->gatewayFactory = $gatewayFactory;
        $this->emitter = $emitter;
        $this->hermesEmitter = $hermesEmitter;
        $this->paymentLogsRepository = $paymentLogsRepository;
        $this->dataProviderManager = $dataProviderManager;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function isUsable(): bool
    {
        if (!$this->basePayment->payment_gateway->is_recurrent) {
            return false;
        }

        $recurrent = $this->recurrentPaymentsRepository->recurrent($this->basePayment);
        if (!$recurrent) {
            return false;
        }
        if ($recurrent->subscription_type_id != $this->baseSubscription->subscription_type_id) {
            return false;
        }
        if ($recurrent->expires_at !== null && $recurrent->expires_at < $this->now()->modify('+31 days')) {
            return false;
        }
        if ($this->recurrentPaymentsRepository->isStopped($recurrent)) {
            return false;
        }

        $remainingDiff = $this->now()->diff($this->baseSubscription->end_time);
        if ($remainingDiff->days < 5) {
            return false;
        }

        return true;
    }

    public function applyConfig(array $config): UpgraderInterface
    {
        if (isset($config['monthly_fix'])) {
            $monthlyFix = filter_var($config['monthly_fix'], FILTER_VALIDATE_FLOAT);
            if ($monthlyFix === false) {
                throw new \Exception('Invalid value provided in ShortUpgrade config "monthly_fix": ' . $config['monthly_fix']);
            }
            $this->monthlyFix = $monthlyFix;
        }
        return $this;
    }

    public function profitability(): float
    {
        return 1 / $this->calculateChargePrice();
    }

    public function upgrade(): bool
    {
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($this->basePayment);
        if (!$recurrentPayment) {
            throw new Exception('Attempt to use PaidRecurrent upgrade for non-recurrent payment: ' . $this->basePayment->id);
        }

        $upgradedItem = $this->getTargetSubscriptionTypeItem();

        $chargePrice = $this->calculateChargePrice();
        $item = new SubscriptionTypePaymentItem(
            $this->targetSubscriptionType->id,
            $upgradedItem->name,
            $chargePrice,
            $upgradedItem->vat
        );
        $paymentItemContainer = (new PaymentItemContainer())->addItem($item);

        // create new payment and charge it right away
        $newPayment = $this->paymentsRepository->add(
            $this->targetSubscriptionType,
            $this->basePayment->payment_gateway,
            $this->basePayment->user,
            $paymentItemContainer,
            '',
            null,
            null,
            null,
            "Payment for upgrade from {$this->baseSubscription->subscription_type->name} to {$this->targetSubscriptionType->name}"
        );

        $this->paymentsRepository->update($newPayment, [
            'upgrade_type' => $this->getType(),
        ]);

        $trackerParams = $this->getTrackerParams();

        $paymentMetaData = ['upgraded_subscription_id' => $this->getBaseSubscription()->id];
        $paymentMetaData = array_merge($paymentMetaData, $trackerParams['source'] ?? [], $trackerParams);
        unset($paymentMetaData['source']);

        $this->paymentsRepository->addMeta($newPayment, $paymentMetaData);

        $newPayment = $this->paymentsRepository->find($newPayment->id);

        $eventParams = [
            'type' => 'payment',
            'user_id' => $newPayment->user_id,
            'sales_funnel_id' => 'upgrade',
            'transaction_id' => $newPayment->variable_symbol,
            'product_ids' => [(string)$newPayment->subscription_type_id],
            'payment_id' => $newPayment->id,
            'revenue' => $newPayment->amount,
        ];

        $this->hermesEmitter->emit(
            new HermesMessage(
                'sales-funnel',
                array_merge($eventParams, $trackerParams)
            )
        );

        /** @var PaymentInterface|RecurrentPaymentInterface $gateway */
        $gateway = $this->gatewayFactory->getGateway($newPayment->payment_gateway->code);

        try {
            $gateway->charge($newPayment, $recurrentPayment->cid);
        } catch (Exception $e) {
            $this->paymentsRepository->updateStatus(
                $newPayment,
                PaymentsRepository::STATUS_FAIL,
                false,
                $newPayment->note . '; failed: ' . $gateway->getResultCode()
            );
        }

        $this->paymentLogsRepository->add(
            $gateway->isSuccessful() ? 'OK' : 'ERROR',
            json_encode($gateway->getResponseData()),
            'recurring-payment-manual-charge',
            $newPayment->id
        );
        if (!$gateway->isSuccessful()) {
            return false;
        }

        $this->paymentsRepository->updateStatus($newPayment, PaymentsRepository::STATUS_PAID);

        // TODO: move this to some event handler; if someone confirmed the $newPayment via admin, this step wouldn't happen
        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'next_subscription_type_id' => $this->targetSubscriptionType->id,
            'custom_amount' => $this->futureChargePrice,
            'parent_payment_id' => $newPayment->id,
            'note' => "Paid recurrent upgrade from subscription type {$this->baseSubscription->subscription_type->name} to {$this->targetSubscriptionType->name}\n(" . time() . ')',
        ]);

        return true;
    }

    public function calculateChargePrice()
    {
        // zistime kolko penazi usetril
        $subscriptionDays = $this->baseSubscription->start_time->diff($this->baseSubscription->end_time)->days;

        $totalSubscriptionAmount = 0;
        foreach ($this->paymentsRepository->getPaymentItemsByType($this->basePayment, SubscriptionTypePaymentItem::TYPE) as $paymentItem) {
            $totalSubscriptionAmount += SubscriptionTypePaymentItem::fromPaymentItem($paymentItem)->totalPrice();
        }
        $dayPrice = $totalSubscriptionAmount / $subscriptionDays;
        $saveFromActual = $this->now()->diff($this->baseSubscription->end_time)->days * $dayPrice;
        $saveFromActual = round($saveFromActual, 2);

        // vypocitame kolko stoji do konca stareho predplatneho novy typ predplatneho
        if (isset($this->monthlyFix)) {
            $subscriptionType = $this->baseSubscription->subscription_type;
            if ($subscriptionType->next_subscription_type_id) {
                $subscriptionType = $subscriptionType->next_subscription_type;
            }

            $newDayPrice = ($subscriptionType->price / $this->targetSubscriptionType->length) + ($this->monthlyFix / 31);
            $this->futureChargePrice = round($newDayPrice * $this->targetSubscriptionType->length, 2);
        } else {
            $newDayPrice = $this->targetSubscriptionType->price / $this->targetSubscriptionType->length;
        }

        $newPrice = $this->now()->diff($this->baseSubscription->end_time)->days * $newDayPrice;
        $newPrice = round($newPrice, 2);

        $chargePrice = $newPrice - $saveFromActual;
        if ($chargePrice <= 0) {
            $chargePrice = 0.01;
        }

        return $chargePrice;
    }

    /**
     * If the $customAmount is set by upgrader implementation, this amount is returned and used by recurrent payment.
     * Otherwise the future charge price is deducted from subscription type we're upgrading to.
     *
     * @return float getFutureChargePrice returns amount of money to be charged in the next recurring payment.
     */
    public function getFutureChargePrice(): float
    {
        if (isset($this->futureChargePrice)) {
            return $this->futureChargePrice;
        }

        $subscriptionType = $this->getTargetSubscriptionType();
        if ($subscriptionType->next_subscription_type_id) {
            $subscriptionType = $this->subscriptionTypesRepository->find($subscriptionType->next_subscription_type_id);
        }
        return $subscriptionType->price;
    }
}
