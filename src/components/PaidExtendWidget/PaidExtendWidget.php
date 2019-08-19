<?php

namespace Crm\UpgradesModule\Components;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\PaymentsModule\CannotProcessPayment;
use Crm\PaymentsModule\PaymentProcessor;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\UpgradesModule\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Upgrade\PaidExtendUpgrade;
use Crm\UpgradesModule\Upgrade\UpgraderFactory;
use Nette\Application\UI\Form;
use Nette\Localization\ITranslator;
use Nette\Utils\ArrayHash;
use Nette\Utils\Json;

/**
 * @property FrontendPresenter $presenter
 */
class PaidExtendWidget extends BaseWidget
{
    private $applicationConfig;

    private $upgraderFactory;

    private $translator;

    private $paymentProcessor;

    private $gateways;

    private $paymentGatewaysRepository;

    private $availableUpgraders;

    public function __construct(
        WidgetManager $widgetManager,
        ApplicationConfig $applicationConfig,
        UpgraderFactory $upgraderFactory,
        ITranslator $translator,
        PaymentProcessor $paymentProcessor,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        AvailableUpgraders $availableUpgraders
    ) {
        parent::__construct($widgetManager);
        $this->applicationConfig = $applicationConfig;
        $this->upgraderFactory = $upgraderFactory;
        $this->translator = $translator;
        $this->paymentProcessor = $paymentProcessor;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->availableUpgraders = $availableUpgraders;
    }

    public function identifier()
    {
        return 'paidextendwidget';
    }

    public function addPaymentGateway($code, $description)
    {
        $this->gateways[$code] = $description;
    }

    public function paymentGateways()
    {
        $gateways = $this->paymentGatewaysRepository->getAllVisible()
            ->where([
                'code' => array_keys($this->gateways),
            ])
            ->fetchAll();

        $wrapper = [];
        foreach ($gateways as $gateway) {
            $wrapper[] = [
                'gateway' => $gateway,
                'description' => $this->gateways[$gateway->code]
            ];
        }
        return ArrayHash::from($wrapper);
    }

    public function render(array $params)
    {
        if (!$params['upgrader'] instanceof \Crm\UpgradesModule\Upgrade\PaidExtendUpgrade) {
            return;
        }

        $this['upgradeForm']['upgrader_idx']->setValue($params['upgrader_idx']);
        $this['upgradeForm']['serialized_tracking_params']->setValue(
            Json::encode($this->presenter->trackingParams())
        );

        $this->template->upgrader = $params['upgrader'];
        $this->template->cmsUrl = $this->applicationConfig->get('cms_url');
        $this->template->upgradeGateways = $this->paymentGateways();
        $this->template->setFile(__DIR__ . '/paid_extend_widget.latte');
        $this->template->render();
    }

    public function createComponentUpgradeForm()
    {
        $form = new Form;
        $form->getElementPrototype()->target = '_top';
        $form->addHidden('upgrader_idx')->setRequired();
        $form->addHidden('serialized_tracking_params')->setRequired();
        $form->addHidden('payment_gateway_id')->setHtmlId('payment_gateway_id')->setRequired();
        $form->onSuccess[] = [$this, 'upgrade'];
        return $form;
    }

    public function upgrade($form, $values)
    {
        $upgraders = $this->availableUpgraders->all();
        if (!isset($upgraders[$values->upgrader_idx])) {
            throw new \Exception('attempt to upgrade with invalid upgrader index');
        }

        /** @var PaidExtendUpgrade $upgrader */
        $upgrader = $upgraders[$values->upgrader_idx];

        if ($values->serialized_tracking_params) {
            $upgrader->setTrackingParams(Json::decode($values->serialized_tracking_params, Json::FORCE_ARRAY));
        }

        $gateway = $this->paymentGatewaysRepository->find($values->payment_gateway_id);

        $result = null;
        try {
            $result = $upgrader
                ->setGateway($gateway)
                ->upgrade();
        } catch (\Exception $e) {
            $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.payment_gateway_timeout'), 'error');
            $this->presenter->redirect('error');
        }

        if ($result) {
            try {
                $url = $this->paymentProcessor->begin($result);
                $this->presenter->redirectUrl($url);
            } catch (CannotProcessPayment $err) {
                $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.error.message'));
                $this->presenter->redirect('error');
            }
        }

        $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.error.message'), 'error');
        $this->presenter->redirect('error');
    }
}