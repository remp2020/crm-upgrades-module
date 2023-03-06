<?php

namespace Crm\UpgradesModule\Upgrade;

use Crm\ApplicationModule\NowTrait;
use Crm\SubscriptionsModule\Repository\ContentAccessRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;

class UpgraderFactory
{
    use NowTrait;

    private const CACHE_KEY = 'upgrader_content_access_pairs';

    /** @var UpgraderInterface[] */
    private array $upgraders = [];

    private ?UpgraderInterface $subsequentUpgrader = null;

    public function __construct(
        private SubscriptionTypesRepository $subscriptionTypesRepository,
        private Storage $cacheStorage,
        private ContentAccessRepository $contentAccessRepository
    ) {
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

            $subscriptionType = $this->resolveDefaultSubscriptionType($currentContent, $baseSubscriptionType->length, $config);
            if (!$subscriptionType) {
                // If baseSubscriptionType uses nonstandard initial length, this might happen. Let's try to use extending_length
                // (if it's set) to search for default subscription type.
                if ($baseSubscriptionType->extending_length) {
                    $subscriptionType = $this->resolveDefaultSubscriptionType($currentContent, $baseSubscriptionType->extending_length, $config);
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

    private function resolveDefaultSubscriptionType(array $currentContentAccess, int $length, \stdClass $config): ?ActiveRow
    {
        $subscriptionType = $this->subscriptionTypesRepository->getTable()
            ->where([
                'active' => true,
                'default' => true,
                'length' => $length,
            ])
            ->order('price')
            ->limit(1);

        $contentAccessPairs = $this->cacheStorage->read(self::CACHE_KEY);
        if (!$contentAccessPairs) {
            $contentAccessPairs = $this->contentAccessRepository->getTable()->fetchPairs('name', 'id');
            $this->cacheStorage->write(self::CACHE_KEY, $contentAccessPairs, [Cache::EXPIRE => 300]);
        }

        $requiredContentAccess = array_unique(array_merge($currentContentAccess, $config->require_content));
        sort($requiredContentAccess);

        // add conditions to filter only subscription types with all the current and target contents
        foreach ($requiredContentAccess as $content) {
            if (isset($config->omit_content) && in_array($content, $config->omit_content, true)) {
                continue;
            }

            $stcaAlias = "stca_$content";
            $subscriptionType
                ->alias(":subscription_type_content_access", $stcaAlias)
                ->joinWhere($stcaAlias, "{$stcaAlias}.content_access_id = ?", $contentAccessPairs[$content])
                ->where("{$stcaAlias}.id IS NOT NULL");
        }

        // add conditions to exclude all the content accesses defined within omit_content configuration
        foreach ($config->omit_content ?? [] as $forbiddenContent) {
            $stcaAlias = "stca_$forbiddenContent";
            $subscriptionType
                ->alias(":subscription_type_content_access", $stcaAlias)
                ->joinWhere($stcaAlias, "{$stcaAlias}.content_access_id = ?", $contentAccessPairs[$forbiddenContent])
                ->where("{$stcaAlias}.id IS NULL");
        }

        return $subscriptionType->fetch();
    }
}
