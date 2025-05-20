<?php

namespace Crm\UpgradesModule\Presenters;

use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\ApplicationModule\Router\RedirectValidator;
use Crm\UpgradesModule\Models\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Models\Upgrade\UpgradeException;
use Nette\Application\Attributes\Persistent;
use Nette\Application\BadRequestException;
use Tracy\Debugger;

class UpgradePresenter extends FrontendPresenter
{
    const SALES_FUNNEL_UPGRADE = 'upgrade';

    #[Persistent]
    public $contentAccess = [];

    #[Persistent]
    public $limit;


    public function __construct(
        private AvailableUpgraders $availableUpgraders,
        private RedirectValidator $redirectValidator,
    ) {
        parent::__construct();
    }

    public function startup()
    {
        parent::startup();

        if ($this->layoutManager->exists($this->getLayoutName() . '_plain')) {
            $this->setLayout($this->getLayoutName() . '_plain');
        } else {
            $this->setLayout('sales_funnel_plain');
        }
    }

    public function renderDefault()
    {
        $this->onlyLoggedIn();

        // Remember referer for future redirect if provided
        $referer = $this->getReferer();
        if ($referer) {
            $section = $this->getSession('upgrade');
            $section->referer = $referer;
        }

        $this->setLayout($this->getLayoutName());
    }

    public function renderSelect()
    {
        $this->onlyLoggedIn();

        $upgraders = [];
        try {
            $upgraders = $this->availableUpgraders->all(
                userId: $this->user->getId(),
                targetContentAccessNames: $this->contentAccess,
                enforceUpgradeOptionRequireContent: ((int) $this->limit === 1),
            );
        } catch (UpgradeException $e) {
            Debugger::log($e);
        }

        if ($this->limit) {
            $upgraders = array_slice($upgraders, 0, $this->limit);
        }

        if (count($upgraders) === 0) {
            $this->redirect('notAvailable', $this->availableUpgraders->getError());
        }
        if (count($upgraders) === 1) {
            $this->redirect('subscription');
        }
        $this->template->upgraders = $upgraders;
        $this->template->queryString = $_SERVER['QUERY_STRING'] ? "&{$_SERVER['QUERY_STRING']}" : '';
    }

    public function renderSubscription($upgraderId = null)
    {
        $this->onlyLoggedIn();

        $upgraders = [];
        try {
            $upgraders = $this->availableUpgraders->all(
                userId: $this->user->getId(),
                targetContentAccessNames: $this->contentAccess,
                enforceUpgradeOptionRequireContent: ((int) $this->limit === 1),
            );
        } catch (UpgradeException $e) {
            Debugger::log($e);
        }

        if (count($upgraders) === 0) {
            $this->redirect('notAvailable', $this->availableUpgraders->getError());
        }

        if ($this->limit) {
            $upgraders = array_slice($upgraders, 0, $this->limit);
        }

        if (count($upgraders) === 1) {
            $this->template->upgrader = $upgraders[0];
        }

        if (count($upgraders) > 1) {
            if ($upgraderId === null) {
                $this->redirect('select', $this->getHttpRequest()->getQuery());
            }
            $this->template->upgrader = $upgraders[$upgraderId];
        }

        $this->template->upgraderId = $upgraderId ?? 0;
        $this->template->contentAccess = $this->contentAccess;
    }

    public function renderSuccess(int $subscriptionId, bool $embedded = true)
    {
        $this->onlyLoggedIn();

        $section = $this->getSession('upgrade');
        if ($section->referer && $this->redirectValidator->isAllowed($section->referer)) {
            $this->template->redirect = $section->referer;
        }

        if (!$embedded) {
            $this->setLayout($this->getLayoutName());
        }

        $subscriptionRow = $this->subscriptionsRepository->getTable()
            ->where('id', $subscriptionId)
            ->where('user_id', $this->getUser()->getId())
            ->fetch();

        if (!$subscriptionRow) {
            throw new BadRequestException("No subscription found ({$subscriptionId}) for user: {$this->getUser()->getId()}");
        }

        $this->template->subscription = $subscriptionRow;
    }

    public function renderNotAvailable($error)
    {
        $this->template->contactEmail = $this->applicationConfig->get('contact_email');
        $this->template->userRow = $this->usersRepository->find($this->getUser()->getId());
        $this->template->error = $error;
    }

    public function renderError()
    {
        $this->setLayout($this->getLayoutName());
    }
}
