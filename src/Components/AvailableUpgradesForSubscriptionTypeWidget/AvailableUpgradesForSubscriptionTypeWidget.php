<?php

declare(strict_types=1);

namespace Crm\UpgradesModule\Components\AvailableUpgradesForSubscriptionTypeWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\UpgradesModule\Models\Upgrade\NoDefaultSubscriptionTypeException;
use Crm\UpgradesModule\Models\Upgrade\UpgraderFactory;
use Crm\UpgradesModule\Repositories\UpgradeSchemasRepository;
use Nette\Database\Table\ActiveRow;

class AvailableUpgradesForSubscriptionTypeWidget extends BaseLazyWidget
{
    private $templateName = 'available_upgrades_for_subscription_type_widget.latte';

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        private UpgraderFactory $upgraderFactory,
        private UpgradeSchemasRepository $upgradeSchemasRepository,
    ) {
        parent::__construct($lazyWidgetManager);
    }

    public function header($id = '')
    {
        return 'Available upgrades for subscription type';
    }

    public function identifier()
    {
        return 'availableupgradesforsubscriptiontypewidget';
    }

    public function render($subscriptionType)
    {
        $this->template->availableUpgrades = $this->getAvailableUpgradesForSubscriptionType($subscriptionType);

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }

    /**
     * @internal Whole upgrade process depends on upgrade options. This method just lists subscription types
     *           which have upgrade options set for provided subscription type. But it doesn't guarantee
     *           that user will get these subscription types as upgrade option.
     */
    public function getAvailableUpgradesForSubscriptionType(ActiveRow $subscriptionType): array
    {
        $availableOptions = [];

        $schemas = $this->upgradeSchemasRepository->allForSubscriptionType($subscriptionType);
        if ($schemas->count()) {
            foreach ($schemas as $schema) {
                $availableOptions += $schema->related('upgrade_options')->fetchAll();
            }
        }

        $upgraders = [];
        foreach ($availableOptions as $option) {
            $upgrader = null;
            try {
                $upgrader = $this->upgraderFactory->fromUpgradeOption($option, $subscriptionType);
            } catch (NoDefaultSubscriptionTypeException $e) {
                // No need to process or log this. These exceptions are logged into user actions log
                // from within AvailableUpgraders. See upgrade.missing_default_target_subscription_type.
                continue;
            }

            if (!$upgrader) {
                // it wouldn't be an upgrade if we used this option
                continue;
            }

            $upgraders[] = $upgrader;
        }

        $targetSubscriptionTypes = [];
        foreach ($upgraders as $upgrader) {
            $targetSubscriptionTypes[] = $upgrader->getTargetSubscriptionType();
        }

        return array_unique($targetSubscriptionTypes);
    }
}
