<?php

namespace Crm\UpgradesModule\Components;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\UpgradesModule\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Upgrade\PaidRecurrentUpgrade;
use Crm\UpgradesModule\Upgrade\UpgraderFactory;
use Nette\Application\UI\Form;
use Nette\Localization\ITranslator;
use Nette\Utils\Json;

/**
 * @property FrontendPresenter $presenter
 */
class PaidRecurrentWidget extends BaseWidget
{
    private $applicationConfig;

    private $upgraderFactory;

    private $translator;

    private $availableUpgraders;

    public function __construct(
        WidgetManager $widgetManager,
        ApplicationConfig $applicationConfig,
        UpgraderFactory $upgraderFactory,
        ITranslator $translator,
        AvailableUpgraders $availableUpgraders
    ) {
        parent::__construct($widgetManager);
        $this->applicationConfig = $applicationConfig;
        $this->upgraderFactory = $upgraderFactory;
        $this->translator = $translator;
        $this->availableUpgraders = $availableUpgraders;
    }

    public function identifier()
    {
        return 'paidrecurrentwidget';
    }

    public function render(array $params)
    {
        if (!$params['upgrader'] instanceof \Crm\UpgradesModule\Upgrade\PaidRecurrentUpgrade) {
            return;
        }

        $this['upgradeForm']['upgrader_idx']->setValue($params['upgrader_idx']);
        $this['upgradeForm']['serialized_tracking_params']->setValue(
            Json::encode($this->presenter->trackingParams())
        );

        $this->template->upgrader = $params['upgrader'];
        $this->template->cmsUrl = $this->applicationConfig->get('cms_url');
        $this->template->setFile(__DIR__ . '/paid_recurrent_widget.latte');
        $this->template->render();
    }

    public function createComponentUpgradeForm()
    {
        $form = new Form;
        $form->addHidden('upgrader_idx')->setRequired();
        $form->addHidden('serialized_tracking_params');
        $form->onSuccess[] = [$this, 'upgrade'];
        return $form;
    }

    public function upgrade($form, $values)
    {
        $upgraders = $this->availableUpgraders->all();
        if (!isset($upgraders[$values->upgrader_idx])) {
            throw new \Exception('attempt to upgrade with invalid upgrader index');
        }

        /** @var PaidRecurrentUpgrade $upgrader */
        $upgrader = $upgraders[$values->upgrader_idx];

        if ($values->serialized_tracking_params) {
            $upgrader->setTrackingParams(Json::decode($values->serialized_tracking_params, Json::FORCE_ARRAY));
        }

        $result = null;
        try {
            $result = $upgrader->upgrade();
        } catch (\Exception $e) {
            $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.payment_gateway_timeout'), 'error');
            $this->presenter->redirect('error');
        }

        if (!$result) {
            $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.error.message'), 'error');
            $this->presenter->redirect('error');
        }

        $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.success'));
        $this->presenter->redirect('success');
    }
}