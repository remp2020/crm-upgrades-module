<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use League\Event\Emitter;
use Nette\Utils\DateTime;

class ShortUpgrade implements UpgraderInterface
{
    const TYPE = 'short';

    use UpgraderTrait;
    use ShortenSubscriptionTrait;
    use SplitSubscriptionTrait;

    private $paymentsRepository;

    private $subscriptionsRepository;

    private $emitter;

    private $hermesEmitter;

    private $monthlyFix;

    public function __construct(
        PaymentsRepository $paymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter
    ) {
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->emitter = $emitter;
        $this->hermesEmitter = $hermesEmitter;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function isUsable(): bool
    {
        if ($this->basePayment->payment_gateway->is_recurrent) {
            return false;
        }
        $shortenedEndTime = $this->calculateShortenedEndTime();
        if ((new DateTime())->diff($shortenedEndTime)->days < 14) {
            return false;
        }

        return true;
    }

    public function applyConfig(array $config): UpgraderInterface
    {
        $clone = (clone $this);
        if (isset($config['monthly_fix'])) {
            $monthlyFix = filter_var($config['monthly_fix'], FILTER_VALIDATE_FLOAT);
            if ($monthlyFix === false) {
                throw new \Exception('Invalid value provided in ShortUpgrade config "monthly_fix": ' . $config['monthly_fix']);
            }
            $this->monthlyFix = $monthlyFix;
        }

        return $clone;
    }

    public function profitability(): float
    {
        return $this->calculateShortenedEndTime()->getTimestamp() - (new DateTime())->getTimestamp();
    }

    public function upgrade(): bool
    {
        $eventParams = [
            'user_id' => $this->basePayment->user_id,
            'browser_id' => $this->browserId,
            'source' => $this->trackingParams,
            'sales_funnel_id' => 'upgrade',
            'transaction_id' => self::TYPE,
            'product_ids' => [strval($this->basePayment->subscription_type_id)],
            'revenue' => 0,
        ];
        $this->hermesEmitter->emit(new HermesMessage('sales-funnel', array_merge(['type' => 'payment'], $eventParams)));

        $endTime = $this->calculateShortenedEndTime();

        $this->splitSubscription($endTime);

        $this->paymentsRepository->update($this->basePayment, [
            'upgrade_type' => self::TYPE,
            'modified_at' => new DateTime(),
        ]);

        $this->hermesEmitter->emit(new HermesMessage('subscription-split', $eventParams));
        return true;
    }
}
