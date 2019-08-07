<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Nette\Database\IRow;
use Nette\Utils\Json;

class UpgraderFactory
{
    /** @var UpgraderInterface[] */
    private $upgraders = [];

    private $subscriptionTypesRepository;

    private $paymentsRepository;

    private $subscriptionsRepository;

    public function __construct(
        SubscriptionTypesRepository $subscriptionTypesRepository,
        PaymentsRepository $paymentsRepository,
        SubscriptionsRepository $subscriptionsRepository
    ) {
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
    }

    public function registerUpgrader(UpgraderInterface $upgrader)
    {
        $this->upgraders[$upgrader->getType()] = $upgrader;
    }

    public function getUpgraders()
    {
        return $this->upgraders;
    }

    public function fromUpgradeOption(IRow $upgradeOption, IRow $baseSubscriptionType): ?UpgraderInterface
    {
        $upgrader = clone $this->upgraders[$upgradeOption->type];

        if ($upgradeOption->subscription_type_id) {
            $subscriptionType = $this->subscriptionTypesRepository->find($upgradeOption->subscription_type_id);
            $upgrader->setTargetSubscriptionType($subscriptionType);
        } else {
            // determine subscription type based on target content type(s) defined in config json
            $config = Json::decode($upgradeOption->config);
            if (!isset($config->require_content)) {
                throw new \Exception("Cannot determine target subscription type for upgrade option [{$upgradeOption->id}], config missing");
            }

            // target subscription type should also include all current contents
            $currentContent = [];
            foreach ($baseSubscriptionType->related('subscription_type_content_access') as $subscriptionTypeContentAccess) {
                $currentContent[] = $subscriptionTypeContentAccess->content_access->name;
            }

            // check if the upgrade option actually gives us any extra content
            if (empty(array_diff($config->require_content, $currentContent))) {
                return null;
            }

            $subscriptionType = $this->subscriptionTypesRepository->getTable()
                ->where([
                    'active' => true,
                    'default' => true,
                    'length' => $baseSubscriptionType->length,
                ])
                ->order('price')
                ->limit(1);

            // query to filter only subscription types with all the current and target contents
            foreach (array_unique(array_merge($currentContent, $config->require_content)) as $content) {
                if (isset($config->omit_content) && in_array($content, $config->omit_content)) {
                    continue;
                }

                $typesWithContent = $this->subscriptionTypesRepository->getTable()
                    ->select('subscription_types.id')
                    ->where([
                        ':subscription_type_content_access.content_access.name' => $content,
                    ])
                    ->fetchAll();

                $subscriptionType->where([
                    'id' => $typesWithContent,
                ]);
            }

            $subscriptionType = $subscriptionType->fetch();
            if (!$subscriptionType) {
                throw new NoDefaultSubscriptionTypeException(
                    "Cannot determine target subscription type for upgrade option [{$upgradeOption->id}], default subscription type not set.",
                    [
                        'target_content' => array_unique(array_merge($currentContent, $config->require_content)),
                    ]
                );
            }

            $upgrader->setTargetSubscriptionType($subscriptionType);
        }

        return $upgrader;
    }
}
