<?php

namespace Crm\UpgradesModule\Components;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\PaymentsModule\CannotProcessPayment;
use Crm\PaymentsModule\PaymentProcessor;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\UpgradesModule\Repository\UpgradeOptionsRepository;
use Crm\UpgradesModule\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Upgrade\PaidExtendUpgrade;
use Crm\UpgradesModule\Upgrade\UpgraderFactory;
use Nette\Application\UI\Form;
use Nette\Localization\ITranslator;
use Nette\Security\User;
use Nette\Utils\ArrayHash;
use Nette\Utils\Json;
use Tracy\Debugger;
use Tracy\ILogger;

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

    private $user;

    private $upgradeOptionsRepository;

    private $subscriptionTypesRepository;

    public function __construct(
        WidgetManager $widgetManager,
        ApplicationConfig $applicationConfig,
        UpgraderFactory $upgraderFactory,
        ITranslator $translator,
        PaymentProcessor $paymentProcessor,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        AvailableUpgraders $availableUpgraders,
        User $user,
        UpgradeOptionsRepository $upgradeOptionsRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository
    ) {
        parent::__construct($widgetManager);
        $this->applicationConfig = $applicationConfig;
        $this->upgraderFactory = $upgraderFactory;
        $this->translator = $translator;
        $this->paymentProcessor = $paymentProcessor;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->availableUpgraders = $availableUpgraders;
        $this->user = $user;
        $this->upgradeOptionsRepository = $upgradeOptionsRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
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
        if (!$this->user->isLoggedIn()) {
            return;
        }

        if (!$params['upgrader'] instanceof \Crm\UpgradesModule\Upgrade\PaidExtendUpgrade) {
            return;
        }

        $this['upgradeForm']['upgrader_idx']->setValue($params['upgrader_idx']);
        $this['upgradeForm']['upgrade_option_tags']->setValue(Json::encode($params['upgrade_option_tags'] ?? null));
        $this['upgradeForm']['content_access']->setValue(Json::encode($params['content_access']));

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
        $form->addHidden('content_access');
        $form->addHidden('upgrade_option_tags');
        $form->addHidden('payment_gateway_id')->setHtmlId('payment_gateway_id')->setRequired();
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
        if (!$upgrader instanceof PaidExtendUpgrade) {
            Debugger::log('attempt to use invalid upgrader in PaidExtendWidget: ' . get_class($upgrader), ILogger::INFO);
            $this->presenter->flashMessage($this->translator->translate('upgrades.frontend.upgrade.error.message'), 'error');
            $this->presenter->redirect('error');
        }

        $gateway = $this->paymentGatewaysRepository->find($values->payment_gateway_id);

        $result = null;
        try {
            $result = $upgrader
                ->setGateway($gateway)
                ->upgrade();
        } catch (\Exception $e) {
            Debugger::log($e->getMessage(), ILogger::EXCEPTION);
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
