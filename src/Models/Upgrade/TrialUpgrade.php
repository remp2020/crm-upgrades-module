<?php

namespace Crm\UpgradesModule\Models\Upgrade;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repositories\ContentAccessRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UpgradesModule\Repositories\SubscriptionUpgradesRepository;
use Crm\UpgradesModule\UpgradesModule;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Tomaj\Hermes\Emitter as HermesEmitter;
use Tracy\Debugger;
use Tracy\ILogger;

class TrialUpgrade implements UpgraderInterface, SubsequentUpgradeInterface
{
    public const TYPE = 'trial';
    public const SUBSCRIPTION_META_TRIAL_ACCEPTED = 'trial_accepted';
    public const SUBSCRIPTION_META_TRIAL_LATEST_END_TIME = 'trial_latest_end_time';
    public const SUBSCRIPTION_META_TRIAL_EXPIRED = 'trial_expired';
    public const SUBSCRIPTION_META_TRIAL_UPGRADE_CONFIG = 'trial_upgrade_config';
    public const SUBSCRIPTION_META_TRIAL_FINALIZED = 'trial_finalized';
    public const UPGRADE_OPTION_CONFIG_ELIGIBLE_CONTENT_ACCESS = 'eligible_content';
    public const UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE = 'trial_subscription_type_code';
    public const UPGRADE_OPTION_CONFIG_SALES_FUNNEL_ID = 'sales_funnel_id';

    use UpgraderTrait;
    use ShortenSubscriptionTrait;
    use SplitSubscriptionTrait;

    private ActiveRow $trialSubscriptionType;
    private array $eligibleContentAccess;
    private int $trialPeriodDays;
    private string $salesFunnelId = 'trial_upgrade';

    private bool $configured = false;

    public function __construct(
        private readonly PaymentsRepository $paymentsRepository,
        private readonly RecurrentPaymentsRepository $recurrentPaymentsRepository,
        private readonly SubscriptionsRepository $subscriptionsRepository,
        private readonly SubscriptionMetaRepository $subscriptionMetaRepository,
        private readonly SubscriptionUpgradesRepository $subscriptionUpgradesRepository,
        private readonly Emitter $emitter,
        private readonly HermesEmitter $hermesEmitter,
        private readonly DataProviderManager $dataProviderManager,
        private readonly UpgraderFactory $upgraderFactory,
        private readonly SubscriptionTypesRepository $subscriptionTypesRepository,
        private readonly ContentAccessRepository $contentAccessRepository,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function isUsable(): bool
    {
        if (!$this->configured) {
            return false;
        }

        $trialAlreadyExpired = $this->subscriptionMetaRepository->getTable()
            ->where('subscription.user_id = ?', $this->baseSubscription->user_id)
            ->where('subscription.subscription_type_id = ?', $this->trialSubscriptionType->id)
            ->where('key = ?', self::SUBSCRIPTION_META_TRIAL_EXPIRED)
            ->fetch();

        if ($trialAlreadyExpired) {
            return false;
        }

        return true;
    }

    public function applyConfig(array $config): UpgraderInterface
    {
        $this->configured = false;

        if (!isset($config[self::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE])) {
            Debugger::log(
                sprintf(
                    'Trial upgrade used without configuring %s. Add %s into upgrade_options.config',
                    self::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE,
                    self::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE,
                ),
                ILogger::ERROR,
            );
            return $this;
        }

        if (isset($config[self::UPGRADE_OPTION_CONFIG_ELIGIBLE_CONTENT_ACCESS])) {
            $this->eligibleContentAccess = $config[self::UPGRADE_OPTION_CONFIG_ELIGIBLE_CONTENT_ACCESS];
        }

        $subscriptionType = $this->subscriptionTypesRepository->findByCode($config[self::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE]);
        if (!$subscriptionType) {
            Debugger::log("Subscription type for trial upgrade doesn't exist: {$config[self::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE]}");
            return $this;
        }

        $this->trialSubscriptionType = $subscriptionType;
        $this->trialPeriodDays = $subscriptionType->length;
        $this->salesFunnelId ??= $config[self::UPGRADE_OPTION_CONFIG_SALES_FUNNEL_ID];

        $this->config = $config;

        $this->configured = true;
        return $this;
    }

    public function profitability(): float
    {
        return $this->trialPeriodDays;
    }

    public function upgrade(): bool
    {
        $eventParams = [
            'user_id' => $this->basePayment->user_id,
            'sales_funnel_id' => $this->salesFunnelId,
            'transaction_id' => self::TYPE,
            'product_ids' => [(string)$this->basePayment->subscription_type_id],
            'revenue' => 0,
        ];
        $this->hermesEmitter->emit(
            new HermesMessage(
                'sales-funnel',
                array_merge(['type' => 'payment'], $eventParams, $this->getTrackerParams()),
            ),
            HermesMessage::PRIORITY_DEFAULT,
        );

        $trialPeriodEnd = $this->getTrialPeriodEnd();
        $selectedTrialEndTime = $this->baseSubscription->end_time;

        if ($selectedTrialEndTime > $trialPeriodEnd) {
            $selectedTrialEndTime = clone $trialPeriodEnd;
        } else {
            $latestWebSubscription = $this->getLatestEligibleSubscription($this->baseSubscription->user_id)
                ->where('end_time > ?', $selectedTrialEndTime)
                ->fetch();

            if ($latestWebSubscription && $latestWebSubscription->end_time > $selectedTrialEndTime) {
                $selectedTrialEndTime = min($trialPeriodEnd, $latestWebSubscription->end_time);
            }
        }

        $trialSubscription = $this->subscriptionsRepository->add(
            subscriptionType: $this->trialSubscriptionType,
            isRecurrent: $this->baseSubscription->is_recurrent,
            isPaid: false,
            user: $this->baseSubscription->user,
            type: UpgradesModule::SUBSCRIPTION_TYPE_UPGRADE,
            startTime: $this->now(),
            endTime: $selectedTrialEndTime,
        );

        $this->subscriptionMetaRepository->add(
            subscription: $trialSubscription,
            key: self::SUBSCRIPTION_META_TRIAL_ACCEPTED,
            value: $this->now()->format(DATE_RFC3339),
        );
        $this->subscriptionMetaRepository->add(
            subscription: $trialSubscription,
            key: self::SUBSCRIPTION_META_TRIAL_LATEST_END_TIME,
            value: $trialPeriodEnd->format(DATE_RFC3339),
        );
        $this->subscriptionMetaRepository->add(
            subscription: $trialSubscription,
            key: self::SUBSCRIPTION_META_TRIAL_UPGRADE_CONFIG,
            value: Json::encode($this->config),
        );

        return true;
    }

    public function expire(ActiveRow $trialSubscription)
    {
        $this->subscriptionMetaRepository->setMeta(
            subscription: $trialSubscription,
            key: TrialUpgrade::SUBSCRIPTION_META_TRIAL_EXPIRED,
            value: 1,
        );
        $this->subscriptionsRepository->update($trialSubscription, [
            'end_time' => $this->now(),
        ]);
    }

    public function finalize(ActiveRow $trialSubscription)
    {
        $trialFinalized = $this->subscriptionMetaRepository
            ->getMeta($trialSubscription, TrialUpgrade::SUBSCRIPTION_META_TRIAL_FINALIZED)
            ->fetch();

        if ($trialFinalized) {
            return true;
        }

        $baseSubscription = $this->getActiveEligibleSubscription($trialSubscription->user_id)->fetch();
        $originalEndTime = $baseSubscription->end_time;

        $latestEndTime = $this->subscriptionMetaRepository
            ->getMeta($trialSubscription, TrialUpgrade::SUBSCRIPTION_META_TRIAL_LATEST_END_TIME)
            ->fetch();
        if ($this->now() < DateTime::from($latestEndTime->value)) {
            // We are attempting to finalize upgrade before end of the trial period. This can only happen after the trial
            // period ended, otherwise user would lose an option to cancel trial within the allowed period.
            throw new \Exception("Unable to finalize trial subscription '{$trialSubscription->id}', trial period is still ongoing.");
        }

        $basePayment = $this->paymentsRepository->subscriptionPayment($baseSubscription);

        $trialContentAccess = $this->contentAccessRepository->allForSubscriptionType($trialSubscription->subscription_type);
        $targetContentAccess = [];
        foreach ($trialContentAccess as $contentAccess) {
            $targetContentAccess[] = $contentAccess->name;
        }

        $upgradeConfig = Json::decode(
            json: $this->subscriptionMetaRepository->getMeta(
                subscription: $trialSubscription,
                key: self::SUBSCRIPTION_META_TRIAL_UPGRADE_CONFIG,
            )->fetch()->value,
            forceArrays: true,
        );

        $targetSubscriptionType = $this->upgraderFactory->resolveTargetSubscriptionType(
            baseSubscriptionType: $basePayment->subscription_type,
            config: [
                'require_content' => $targetContentAccess,
                'omit_content' => $upgradeConfig['omit_content'] ?? [],
            ],
        );
        if (!$targetSubscriptionType) {
            Debugger::log("Unable to finalize trial upgrade, subscription '{$baseSubscription->id} can't resolve target subscription type for '{$basePayment->subscription_type->code}'.");
            return false;
        }

        $upgrader = $this->getSubsequentUpgrader()
            ->setTargetSubscriptionType($targetSubscriptionType)
            ->setBaseSubscription($baseSubscription)
            ->setBasePayment($basePayment)
            ->applyConfig($upgradeConfig);

        if ($upgrader instanceof SubsequentUpgradeInterface) {
            $upgrader->setSubsequentUpgrader($this->getSubsequentUpgrader());
        }

        $upgrader->upgrade();

        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($basePayment);
        $statesToHandle = [
            RecurrentPaymentStateEnum::Active->value,
            RecurrentPaymentStateEnum::UserStop->value,
        ];

        if ($recurrentPayment && in_array($recurrentPayment->state, $statesToHandle, true)) {
            // determine how far to move recurrent charge, and move it
            $baseSubscription = $this->subscriptionsRepository->find($baseSubscription->id);
            $upgradedSubscription = $this->subscriptionUpgradesRepository->getTable()
                ->where('base_subscription_id = ?', $baseSubscription->id)
                ->fetch()
                ->upgraded_subscription;

            $newEndTime = $upgradedSubscription->end_time;
            $diff = $originalEndTime->diff($newEndTime);

            $this->recurrentPaymentsRepository->update($recurrentPayment, [
                'charge_at' => (clone $recurrentPayment->charge_at)->add($diff),
                'next_subscription_type_id' => $targetSubscriptionType->id,
            ]);
        }

        $this->subscriptionMetaRepository->add(
            subscription: $trialSubscription,
            key: TrialUpgrade::SUBSCRIPTION_META_TRIAL_FINALIZED,
            value: true,
        );

        return true;
    }

    public function getActiveEligibleSubscription(int $userId): Selection
    {
        $contentAccesss = $this->eligibleContentAccess ?? ['web'];
        return $this->subscriptionsRepository
            ->actualUserSubscriptionsByContentAccess(
                $this->now(),
                $userId,
                ...$contentAccesss,
            )
            ->order('end_time DESC')
            ->limit(1);
    }

    public function getLatestEligibleSubscription(int $userId): Selection
    {
        $contentAccesss = $this->eligibleContentAccess ?? ['web'];
        return $this->subscriptionsRepository
            ->latestSubscriptionsByContentAccess(...$contentAccesss)
            ->where('subscriptions.user_id = ?', $userId)
            ->order('end_time DESC')
            ->limit(1);
    }

    public function getTrialPeriodEnd(): \DateTime
    {
        $trialInterval = new \DateInterval('P' . $this->trialPeriodDays . 'D');
        return $this->now()->add($trialInterval);
    }
}
