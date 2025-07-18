<?php


namespace Crm\UpgradesModule\Models\Upgrade;

use Crm\ApplicationModule\Models\NowTrait;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\SubscriptionsModule\Repositories\ContentAccessRepository;
use Crm\UpgradesModule\Repositories\UpgradeSchemasRepository;
use Crm\UsersModule\Repositories\UserActionsLogRepository;
use Nette\Utils\Json;
use Nette\Utils\JsonException;

class AvailableUpgraders
{
    use NowTrait;

    const ERROR_NO_BASE_PAYMENT = 'no_base_payment';

    const ERROR_NO_SUBSCRIPTION = 'no_subscription';

    const ERROR_NOT_LOGGED_IN = 'not_logged_in';

    private $paymentsRepository;

    private $userActionsLogRepository;

    private $upgradeSchemasRepository;

    private $upgraderFactory;

    private $contentAccessRepository;

    private $upgradeableSubscriptions;

    private $error;

    public function __construct(
        PaymentsRepository $paymentsRepository,
        UserActionsLogRepository $userActionsLogRepository,
        UpgradeSchemasRepository $upgradeSchemasRepository,
        UpgraderFactory $upgraderFactory,
        ContentAccessRepository $contentAccessRepository,
        ActualUserSubscriptions $upgradeableSubscriptions,
    ) {
        $this->paymentsRepository = $paymentsRepository;
        $this->userActionsLogRepository = $userActionsLogRepository;
        $this->upgradeSchemasRepository = $upgradeSchemasRepository;
        $this->upgraderFactory = $upgraderFactory;
        $this->contentAccessRepository = $contentAccessRepository;
        $this->upgradeableSubscriptions = $upgradeableSubscriptions;
    }

    /**
     * all() returns list of available upgraders for given user and contentAccess you're trying to upgrade user to.
     *
     * By default the method checks upgrade availability against active user's subscriptions with payment. You can
     * change the "active subscription" filter by calling setUpgradeableSubscriptions() prior to this call and passing
     * other implementation of ActualUserSubscriptions interface.
     *
     * If user already has access to the content access of subscription type of possible upgrade option, the upgrader
     * is not included within the final result set.
     *
     * If upgrader is not usable - upgraders can check usability themselves based on base/target subscriptions and
     * payments - it's not included within the final result set.
     *
     * List of upgraders is sorted based on profitability (best value for money) starting with the most profitable.
     *
     * @param int $userId
     * @param array $targetContentAccessNames Filters only upgrade options with target subscription types which give
     * access to all specified content access names.
     * @param array $requiredUpgradeOptionTags Filters only upgrade options with specific tags within their config
     * @param bool $enforceUpgradeOptionRequireContent Filters only upgrade options with $targetContentAccessNames in
     * config's require_content. Some upgrade options could offer subscription types with targeted content access
     * but are not primarily targeted at it. This forces return of these upgraders.
     * object.
     * @return UpgraderInterface[]
     * @throws JsonException
     */
    public function all(
        int $userId,
        array $targetContentAccessNames = [],
        array $requiredUpgradeOptionTags = [],
        bool $enforceUpgradeOptionRequireContent = false,
    ) {
        $this->error = null;
        if (!$userId) {
            $this->error = self::ERROR_NOT_LOGGED_IN;
            return [];
        }

        $subscriptions = $this->upgradeableSubscriptions->getSubscriptions($userId);
        if (count($subscriptions) === 0) {
            $this->error = self::ERROR_NO_SUBSCRIPTION;
            return [];
        }

        // Store all possible candidates (subscriptions with payment) for later evaluation. Some of the candidate
        // subscriptions might not be upgradeable yet, so we want to evaluate other options if possible.
        $candidates = [];
        $lastCheckedSubscription = null;

        foreach ($subscriptions as $subscription) {
            $subscriptionToUpgrade = $lastCheckedSubscription = $subscription;

            // Cannot upgrade subscription ending in the past
            if ($subscription->end_time < $this->getNow()) {
                continue;
            }

            $basePayment = $this->paymentsRepository->subscriptionPayment($subscription);
            if ($basePayment) {
                $candidates[] = [
                    'basePayment' => $basePayment,
                    'subscriptionToUpgrade' => $subscriptionToUpgrade,
                ];
            }
        }
        if (!count($candidates)) {
            $this->userActionsLogRepository->add($userId, 'upgrade.cannot_upgrade', [
                'subscription_id' => $lastCheckedSubscription->id,
                'subscription_type_id' => $lastCheckedSubscription->subscription_type_id,
            ]);
            $this->error = self::ERROR_NO_BASE_PAYMENT;
            return [];
        }

        $basePayment = null;
        $subscriptionToUpgrade = null;

        $availableOptions = [];
        foreach ($candidates as $candidate) {
            $baseSubscriptionType = $candidate['subscriptionToUpgrade']->subscription_type;
            $schemas = $this->upgradeSchemasRepository->allForSubscriptionType($baseSubscriptionType);
            if ($schemas->count()) {
                foreach ($schemas as $schema) {
                    $availableOptions += $schema
                        ->related('upgrade_options')
                        ->where('subscription_type_id IS NULL OR subscription_type.length = ?', $baseSubscriptionType->length)
                        ->fetchAll();
                }
                if (count($availableOptions)) {
                    $basePayment = $candidate['basePayment'];
                    $subscriptionToUpgrade = $candidate['subscriptionToUpgrade'];
                    break;
                }
            }
        }

        $upgraders = [];
        $profitabilities = [];

        $missingDefaultSubscriptionTypes = [];

        foreach ($availableOptions as $option) {
            $upgrader = null;
            try {
                $upgrader = $this->upgraderFactory->fromUpgradeOption($option, $subscriptionToUpgrade->subscription_type);
            } catch (NoDefaultSubscriptionTypeException $e) {
                $missingDefaultSubscriptionTypes[] = $e->getContext();
                continue;
            }

            if (!$upgrader) {
                // it wouldn't be an upgrade if we used this option
                continue;
            }

            $config = Json::decode($option->config, Json::FORCE_ARRAY);
            $configOptionTags = $config['require_tags'] ?? [];
            if (count($requiredUpgradeOptionTags) || count($configOptionTags)) {
                if (count($configOptionTags) !== count($requiredUpgradeOptionTags) ||
                    array_diff($configOptionTags, $requiredUpgradeOptionTags) !== array_diff($requiredUpgradeOptionTags, $configOptionTags)) {
                    // required tags were not met
                    continue;
                }
            }

            $upgrader
                ->setBaseSubscription($subscriptionToUpgrade)
                ->setBasePayment($basePayment)
                ->applyConfig($config);

            if ($this->now) {
                $upgrader->setNow($this->now);
            }

            // skip upgrader if it's not usable (upgraders know when they can be used)
            if (!$upgrader->isUsable()) {
                continue;
            }

            // skip upgrade options which do not specifically target required content access
            if ($enforceUpgradeOptionRequireContent) {
                if (empty($config['require_content'])) {
                    continue;
                }

                // require_content has to contain all content accesses from $targetContentAccessNames
                $matched = array_intersect($targetContentAccessNames, $config['require_content']);
                if (!(count($matched) === count($targetContentAccessNames))) {
                    continue;
                }
            }

            // if we aim for specific content access, check if it's supported by target subscription type
            if (!empty($targetContentAccessNames)) {
                $hasAccess = $this->contentAccessRepository->hasAccess($upgrader->getTargetSubscriptionType(), $targetContentAccessNames);
                if (!$hasAccess) {
                    continue;
                }
            }

            $baseContentAccessPairs = $this->contentAccessRepository
                ->allForSubscriptionType($upgrader->getBaseSubscription()->subscription_type)
                ->fetchPairs('name', 'name');

            $targetContentAccessPairs = $this->contentAccessRepository
                ->allForSubscriptionType($upgrader->getTargetSubscriptionType())
                ->fetchPairs('name', 'name');

            $diff = array_diff($targetContentAccessPairs, $baseContentAccessPairs);
            if (empty($diff)) {
                // upgrade would make no difference
                continue;
            }

            // for each combination of upgrade type and target content access set, keep only upgrader providing the best value
            $key = sprintf("%s|%s", $upgrader->getType(), implode('_', $targetContentAccessPairs));
            $upgraderProfitability = $upgrader->profitability();

            if (!isset($profitabilities[$key]) || $upgraderProfitability > $profitabilities[$key]) {
                $profitabilities[$key] = $upgraderProfitability;
                $upgraders[$key] = $upgrader;
            }
        }

        // as we didn't find any available upgraders, let's log the case that there could be some if they had default subscriptions
        if (empty($upgraders) && !empty($missingDefaultSubscriptionTypes)) {
            $params['target_contents'] = $missingDefaultSubscriptionTypes;
            $params['subscription_id'] = $subscriptionToUpgrade->id;
            $params['subscription_type_id'] = $subscriptionToUpgrade->subscription_type_id;
            $this->userActionsLogRepository->add($userId, 'upgrade.missing_default_target_subscription_type', $params);
        }

        uksort($upgraders, function ($a, $b) use ($profitabilities) {
            return $profitabilities[$a] < $profitabilities[$b] ? 1 : -1;
        });
        return array_values($upgraders);
    }

    public function getError()
    {
        return $this->error;
    }

    public function setUpgradeableSubscriptions(UpgradeableSubscriptionsInterface $usi)
    {
        $this->upgradeableSubscriptions = clone $usi;
    }
}
