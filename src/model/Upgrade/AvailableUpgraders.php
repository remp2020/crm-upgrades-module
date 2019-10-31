<?php


namespace Crm\UpgradesModule\Upgrade;

use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Repository\ContentAccessRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UpgradesModule\Repository\UpgradeSchemasRepository;
use Crm\UsersModule\Repository\UserActionsLogRepository;
use Nette\Utils\Json;

class AvailableUpgraders
{
    const ERROR_NO_BASE_PAYMENT = 'no_base_payment';

    const ERROR_NO_SUBSCRIPTION = 'no_subscription';

    const ERROR_NOT_LOGGED_IN = 'not_logged_in';

    private $subscriptionsRepository;

    private $paymentsRepository;

    private $userActionsLogRepository;

    private $upgradeSchemasRepository;

    private $upgraderFactory;

    private $contentAccessRepository;

    private $error;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository,
        PaymentsRepository $paymentsRepository,
        UserActionsLogRepository $userActionsLogRepository,
        UpgradeSchemasRepository $upgradeSchemasRepository,
        UpgraderFactory $upgraderFactory,
        ContentAccessRepository $contentAccessRepository
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->userActionsLogRepository = $userActionsLogRepository;
        $this->upgradeSchemasRepository = $upgradeSchemasRepository;
        $this->upgraderFactory = $upgraderFactory;
        $this->contentAccessRepository = $contentAccessRepository;
    }

    public function all($userId, array $contentTypeNames = [], array $requiredTags = [])
    {
        $this->error = null;
        if (!$userId) {
            $this->error = self::ERROR_NOT_LOGGED_IN;
            return [];
        }

        $actualSubscriptions = $this->subscriptionsRepository->actualUserSubscriptions($userId)->fetchAll();
        if (count($actualSubscriptions) === 0) {
            $this->error = self::ERROR_NO_SUBSCRIPTION;
            return [];
        }

        $basePayment = null;
        $actualUserSubscription = null;

        foreach ($actualSubscriptions as $subscription) {
            $actualUserSubscription = $subscription;
            $basePayment = $this->paymentsRepository->subscriptionPayment($subscription);
            if ($basePayment) {
                // TODO: handle two subscriptions with base payment
                // What if there are two subscriptions with base payment? This one might not be upgradeable but the
                // other one could be.
                break;
            }
        }
        if (!$basePayment) {
            $this->userActionsLogRepository->add($userId, 'upgrade.cannot_upgrade', [
                'subscription_id' => $actualUserSubscription->id,
                'subscription_type_id' => $actualUserSubscription->subscription_type_id,
            ]);
            $this->error = self::ERROR_NO_BASE_PAYMENT;
            return [];
        }

        $schemas = $this->upgradeSchemasRepository->allForSubscriptionType($actualUserSubscription->subscription_type);
        $availableOptions = [];
        foreach ($schemas as $schema) {
            $availableOptions += $schema->related('upgrade_options')->fetchAll();
        }

        $upgraders = [];
        $profitabilities = [];

        $missingDefaultSubscriptionTypes = [];

        foreach ($availableOptions as $option) {
            $upgrader = null;
            try {
                $upgrader = $this->upgraderFactory->fromUpgradeOption($option, $actualUserSubscription->subscription_type, $requiredTags);
            } catch (NoDefaultSubscriptionTypeException $e) {
                $missingDefaultSubscriptionTypes[] = $e->getContext();
                continue;
            }

            if (!$upgrader) {
                // it wouldn't be an upgrade if we used this option
                continue;
            }

            $upgrader
                ->setBaseSubscription($actualUserSubscription)
                ->setBasePayment($basePayment)
                ->applyConfig(Json::decode($option->config, Json::FORCE_ARRAY));

            // skip upgrader if it's not usable (upgraders know when they can be used)
            if (!$upgrader->isUsable()) {
                continue;
            }

            // if we aim for specific content access, check if it's supported by target subscription type
            if (!empty($contentTypeNames)) {
                $hasAccess = $this->contentAccessRepository->hasAccess($upgrader->getTargetSubscriptionType(), $contentTypeNames);
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
            $params['subscription_id'] = $actualUserSubscription->id;
            $params['subscription_type_id'] = $actualUserSubscription->subscription_type_id;
            $this->userActionsLogRepository->add($userId, 'upgrade.missing_default_target_subscription_type', $params);
        }

        return array_values($upgraders);
    }

    public function getError()
    {
        return $this->error;
    }
}
