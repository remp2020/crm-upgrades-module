<?php

namespace Crm\UpgradesModule\Components;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\UpgradesModule\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Upgrade\FreeRecurrentUpgrade;
use Crm\UpgradesModule\Upgrade\SubsequentUpgradeInterface;
use Nette\Application\UI\Form;
use Nette\Localization\Translator;
use Nette\Security\User;
use Nette\Utils\Json;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @property FrontendPresenter $presenter
 */
class FreeRecurrentWidget extends BaseWidget
{
    private $applicationConfig;

    private $translator;

    private $availableUpgraders;

    private $user;

    public function __construct(
        WidgetManager $widgetManager,
        ApplicationConfig $applicationConfig,
        Translator $translator,
        AvailableUpgraders $availableUpgraders,
        User $user
    ) {
        parent::__construct($widgetManager);
        $this->applicationConfig = $applicationConfig;
        $this->translator = $translator;
        $this->availableUpgraders = $availableUpgraders;
        $this->user = $user;
    }

    public function identifier()
    {
        return 'freerecurrentwidget';
    }

    public function render(array $params)
    {
        if (!$this->user->isLoggedIn()) {
            return;
        }

        if (!$params['upgrader'] instanceof \Crm\UpgradesModule\Upgrade\FreeRecurrentUpgrade) {
            return;
        }

        $subsequentUpgrades = $params['upgrader'] instanceof SubsequentUpgradeInterface
            && $params['upgrader']->getSubsequentUpgrader()
            && $params['upgrader']->getFollowingSubscriptions();

        $this['upgradeForm']['upgrader_idx']->setValue($params['upgrader_idx']);
        $this['upgradeForm']['upgrade_option_tags']->setValue(Json::encode($params['upgrade_option_tags'] ?? null));
        $this['upgradeForm']['content_access']->setValue(Json::encode($params['content_access']));

        $this->template->subsequentUpgrades = $subsequentUpgrades;
        $this->template->upgrader = $params['upgrader'];
        $this->template->cmsUrl = $this->applicationConfig->get('cms_url');
        $this->template->setFile(__DIR__ . '/free_recurrent_widget.latte');
        $this->template->render();
    }

    public function createComponentUpgradeForm()
    {
        $form = new Form;
        $form->addHidden('upgrader_idx')->setRequired();
        $form->addHidden('content_access');
        $form->addHidden('upgrade_option_tags');
        $form->onSuccess[] = [$this, 'upgrade'];
        return $form;
    }

    public function upgrade($form, $values)
    {
        $targetContentAccess = Json::decode($values->content_access);
        $requiredTags = Json::decode($values->upgrade_option_tags) ?? [];

        $upgraders = $this->availableUpgraders->all($this->user->getId(), $targetContentAccess, $requiredTags);
        if (!isset($upgraders[$values->upgrader_idx])) {
            Debugger::log('attempt to upgrade with invalid upgrader index', ILogger::INFO);
            $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.error.message'), 'error');
            $this->presenter->redirect('error');
        }

        $upgrader = $upgraders[$values->upgrader_idx];
        if (!$upgrader instanceof FreeRecurrentUpgrade) {
            Debugger::log('attempt to use invalid upgrader in FreeRecurrentWidget: ' . get_class($upgrader), ILogger::INFO);
            $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.error.message'), 'error');
            $this->presenter->redirect('error');
        }

        $result = null;
        try {
            $result = $upgrader->upgrade();
        } catch (\Exception $e) {
            Debugger::log($e->getMessage(), ILogger::EXCEPTION);
            $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.payment_gateway_timeout'), 'error');
            $this->presenter->redirect('error');
        }

        if (!$result) {
            $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.error.message'), 'error');
            $this->presenter->redirect('error');
        }

        $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.success'));

        $subscriptionUpgradeRow = $upgrader->getBaseSubscription()
            ->related('subscription_upgrades', 'base_subscription_id')
            ->fetch();

        $this->presenter->redirect('success', [
            'subscriptionId' => $subscriptionUpgradeRow->upgraded_subscription_id,
        ]);
    }
}
