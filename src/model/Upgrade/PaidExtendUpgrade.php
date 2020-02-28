<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;
use Tomaj\Hermes\Emitter;

class PaidExtendUpgrade implements UpgraderInterface
{
    const TYPE = 'paid_extend';

    use UpgraderTrait;
    use ShortenSubscriptionTrait;

    private $subscriptionsRepository;

    private $subscriptionTypesRepository;

    private $paymentGatewaysRepository;

    private $paymentsRepository;

    private $recurrentPaymentsRepository;

    private $hermesEmitter;

    private $monthlyFix;

    private $alteredEndTime;

    private $gateway;

    private $trackingParams = [];

    public function __construct(
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        Emitter $hermesEmitter
    ) {
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->hermesEmitter = $hermesEmitter;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function isUsable(): bool
    {
        if ($this->basePayment->payment_gateway->is_recurrent) {
            $recurrent = $this->recurrentPaymentsRepository->recurrent($this->basePayment);
            if (!$this->recurrentPaymentsRepository->isStopped($recurrent)) {
                return false;
            }
        }
        $shortenedEndTime = $this->calculateShortenedEndTime();
        if ($this->now()->diff($shortenedEndTime)->days >= 14) {
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

    public function setGateway(IRow $gateway): UpgraderInterface
    {
        $this->gateway = $gateway;
        return $this;
    }

    public function setTrackingParams($trackingParams)
    {
        $this->trackingParams = $trackingParams;
    }

    public function profitability(): float
    {
        return 1 / $this->calculateChargePrice();
    }

    public function upgrade(): ActiveRow
    {
        $upgradedItem = $this->getTargetSubscriptionTypeItem();

        $chargePrice = $this->calculateChargePrice();
        $item = new SubscriptionTypePaymentItem(
            $this->targetSubscriptionType->id,
            $upgradedItem->name,
            $chargePrice,
            $upgradedItem->vat
        );
        $paymentItemContainer = (new PaymentItemContainer())->addItem($item);

        // create new payment instance
        $newPayment = $this->paymentsRepository->add(
            $this->targetSubscriptionType,
            $this->gateway,
            $this->basePayment->user,
            $paymentItemContainer,
            ''
        );

        $this->paymentsRepository->update($newPayment, [
            'upgrade_type' => $this->getType(),
            'note' => "Paid extend upgrade from subscription type '{$this->baseSubscription->subscription_type->name}' to '{$this->targetSubscriptionType->name}'",
            'modified_at' => new DateTime(),
        ]);
        $this->paymentsRepository->addMeta($newPayment, $this->trackingParams);

        $this->hermesEmitter->emit(new HermesMessage('sales-funnel', [
            'type' => 'payment',
            'user_id' => $newPayment->user_id,
            'browser_id' => $this->browserId,
            'source' => $this->trackingParams,
            'sales_funnel_id' => 'upgrade',
            'transaction_id' => $newPayment->variable_symbol,
            'product_ids' => [(string)$newPayment->subscription_type_id],
            'payment_id' => $newPayment->id,
            'revenue' => $newPayment->amount,
        ]));

        return $newPayment;
    }

    /**
     * calculateChargePrice calculates price based on $toSubscriptionType's price discounted by
     * $actualUserSubscription's remaining amount of days.
     *
     * @return float
     */
    public function calculateChargePrice()
    {
        // calculate the amount of money "not spent" from actual subscription
        $subscriptionDays = $this->baseSubscription->start_time->diff($this->baseSubscription->end_time)->days;

        $totalSubscriptionAmount = 0;
        foreach ($this->paymentsRepository->getPaymentItemsByType($this->basePayment, SubscriptionTypePaymentItem::TYPE) as $paymentItem) {
            $totalSubscriptionAmount += SubscriptionTypePaymentItem::fromPaymentItem($paymentItem)->totalPrice();
        }
        $dayPrice = $totalSubscriptionAmount / $subscriptionDays;
        $saveFromActual = $this->now()->diff($this->baseSubscription->end_time)->days * $dayPrice;
        $saveFromActual = round($saveFromActual, 2);

        // get full price of upgraded subscription
        if ($this->monthlyFix) {
            $newDayPrice = ($this->baseSubscription->subscription_type->price / $this->baseSubscription->subscription_type->length) + ($this->monthlyFix / 31);
            $newPrice = $this->targetSubscriptionType->length * $newDayPrice;
            $newPrice = round($newPrice, 2);
        } else {
            $newPrice = $this->targetSubscriptionType->price;
        }

        // subtract "not spent" money from new price and charge
        $chargePrice = $newPrice - $saveFromActual;
        if ($chargePrice <= 0) {
            $chargePrice = 0.01;
        }
        return $chargePrice;
    }

    public function calculateUpgradedEndTime()
    {
        return $this->now()->add(new \DateInterval("P{$this->targetSubscriptionType->length}D"));
    }
}
