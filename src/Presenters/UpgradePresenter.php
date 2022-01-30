<?php

namespace Crm\UpgradesModule\Presenters;

use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\UpgradesModule\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Upgrade\UpgradeException;
use Nette\Application\BadRequestException;
use Nette\Http\Url;
use Nette\Utils\Strings;
use Tomaj\Hermes\Emitter;
use Tracy\Debugger;

class UpgradePresenter extends FrontendPresenter
{
    const SALES_FUNNEL_UPGRADE = 'upgrade';

    /** @var Emitter @inject */
    public $hermesEmitter;

    /** @var AvailableUpgraders @inject */
    public $availableUpgraders;

    /** @persistent */
    public $contentAccess = [];

    /** @persistent */
    public $limit;

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
        $cmsUrl = $this->applicationConfig->get('cms_url');
        $referer = $this->getReferer();
        if ($cmsUrl && $referer) {
            try {
                $urlReferer = new Url($referer);
                $urlCms = new Url($cmsUrl);

                // Protection against open-redirect vulnerability (only domains and subdomains of $cmsUrl are allowed)
                $isDomainOrSubdomain = Strings::endsWith($urlReferer->host, $urlCms->host);
                if ($isDomainOrSubdomain) {
                    $section = $this->getSession('upgrade');
                    $section->referer = $referer;
                }
            } catch (\InvalidArgumentException $e) {
                // do nothing in case of invalid URL
            }
        }

        $this->template->queryString = $_SERVER['QUERY_STRING'];

        $this->setLayout($this->getLayoutName());
    }

    public function renderSelect()
    {
        $this->onlyLoggedIn();

        $upgraders = [];
        try {
            $upgraders = $this->availableUpgraders->all($this->user->getId(), $this->contentAccess);
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
            $upgraders = $this->availableUpgraders->all($this->user->getId(), $this->contentAccess);
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
        if ($section->referer) {
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
        $this->template->user = $this->usersRepository->find($this->getUser()->getId());
        $this->template->error = $error;
    }

    public function renderError()
    {
        $this->setLayout($this->getLayoutName());
    }
}
