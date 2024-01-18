<?php

namespace Crm\UpgradesModule\Models\Upgrade;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Tomaj\Hermes\Emitter;

class PaidExtendUpgrade implements UpgraderInterface, SubsequentUpgradeInterface
{
    public const TYPE = 'paid_extend';

    public const PAYMENT_META_UPGRADED_SUBSCRIPTION_ID = 'upgraded_subscription_id';
    public const PAYMENT_META_UPGRADE_CONFIG = 'upgrade_config';

    use UpgraderTrait;
    use ShortenSubscriptionTrait;

    private $monthlyFix;

    private $alteredEndTime;

    private $gateway;

    public function __construct(
        private PaymentsRepository $paymentsRepository,
        private RecurrentPaymentsRepository $recurrentPaymentsRepository,
        private PaymentGatewaysRepository $paymentGatewaysRepository,
        private SubscriptionsRepository $subscriptionsRepository,
        private SubscriptionTypesRepository $subscriptionTypesRepository,
        private Emitter $hermesEmitter,
        private DataProviderManager $dataProviderManager,
        private UpgraderFactory $upgraderFactory,
    ) {
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
                throw new \Exception('Invalid value provided in PaidExtendUpgrade config "monthly_fix": ' . $config['monthly_fix']);
            }
            $this->monthlyFix = $monthlyFix;
        }
        $this->config = $config;
        return $this;
    }

    public function setGateway(ActiveRow $gateway): UpgraderInterface
    {
        $this->gateway = $gateway;
        return $this;
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
            subscriptionTypeId: $this->targetSubscriptionType->id,
            name: $upgradedItem->name,
            price: $chargePrice,
            vat: $upgradedItem->vat,
            subscriptionTypeItemId: $upgradedItem->id
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

        $paymentMeta = [
            self::PAYMENT_META_UPGRADED_SUBSCRIPTION_ID => $this->getBaseSubscription()->id,
            self::PAYMENT_META_UPGRADE_CONFIG => Json::encode($this->config),
        ];
        $trackerParams = $this->getTrackerParams();
        $paymentMeta = array_merge($paymentMeta, $trackerParams['source'] ?? [], $trackerParams);
        unset($paymentMeta['source']);

        $this->paymentsRepository->addMeta($newPayment, $paymentMeta);

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
            ),
            HermesMessage::PRIORITY_DEFAULT
        );

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

        if ($subscriptionDays > 0) {
            $dayPrice = $totalSubscriptionAmount / $subscriptionDays;
            $saveFromActual = $this->now()->diff($this->baseSubscription->end_time)->days * $dayPrice;
            $saveFromActual = round($saveFromActual, 2);
        } else {
            $saveFromActual = 0;
        }

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
