<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\NowTrait;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;

class UpgraderFactory
{
    use NowTrait;

    /** @var UpgraderInterface[] */
    private $upgraders = [];

    /** @var UpgraderInterface|null */
    private $subsequentUpgrader;

    private $subscriptionTypesRepository;

    public function __construct(
        SubscriptionTypesRepository $subscriptionTypesRepository
    ) {
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
    }

    public function registerUpgrader(UpgraderInterface $upgrader)
    {
        $this->upgraders[$upgrader->getType()] = $upgrader;
    }

    public function setSubsequentUpgrader(?UpgraderInterface $subsequentUpgrader)
    {
        $this->subsequentUpgrader = $subsequentUpgrader;
    }

    /**
     * @return UpgraderInterface[]
     */
    public function getUpgraders(): array
    {
        $result = [];
        foreach ($this->upgraders as $type => $upgrader) {
            $u = clone $upgrader;
            $u->setNow($this->getNow());
            if ($u instanceof SubsequentUpgradeInterface) {
                $u->setSubsequentUpgrader($this->subsequentUpgrader);
            }
            $result[$type] = $u;
        }
        return $result;
    }

    public function fromUpgradeOption(ActiveRow $upgradeOption, ActiveRow $baseSubscriptionType): ?UpgraderInterface
    {
        if (!isset($this->upgraders[$upgradeOption->type])) {
            throw new \Exception('Upgrader with given type is not registered: ' . $upgradeOption->type);
        }
        $upgrader = clone $this->upgraders[$upgradeOption->type];
        $upgrader->setNow($this->getNow());
        if ($this->subsequentUpgrader && $upgrader instanceof SubsequentUpgradeInterface) {
            $upgrader->setSubsequentUpgrader($this->subsequentUpgrader);
        }

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

            $subscriptionType = $this->getDefaultSubscriptionType($baseSubscriptionType, $currentContent, $baseSubscriptionType->length, $config);
            if (!$subscriptionType) {
                // If baseSubscriptionType uses nonstandard initial length, this might happen. Let's try to use extending_length
                // (if it's set) to search for default subscription type.
                if ($baseSubscriptionType->extending_length) {
                    $subscriptionType = $this->getDefaultSubscriptionType($baseSubscriptionType, $currentContent, $baseSubscriptionType->extending_length, $config);
                }

                if (!$subscriptionType) {
                    throw new NoDefaultSubscriptionTypeException(
                        "Cannot determine target subscription type for upgrade option [{$upgradeOption->id}], default subscription type not set.",
                        [
                            'target_content' => array_unique(array_merge($currentContent, $config->require_content)),
                        ]
                    );
                }
            }

            $upgrader->setTargetSubscriptionType($subscriptionType);
        }

        return $upgrader;
    }

    private function getDefaultSubscriptionType(ActiveRow $baseSubscriptionType, $currentContent, $length, $config)
    {
        $subscriptionType = $this->subscriptionTypesRepository->getTable()
            ->where([
                'active' => true,
                'default' => true,
                'length' => $length,
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

        return $subscriptionType->fetch();
    }
}
