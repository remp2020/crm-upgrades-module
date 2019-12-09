<?php

namespace Crm\UpgradesModule\Presenters;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\UpgradesModule\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Upgrade\UpgradeException;
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
                $this->redirect('select');
            }
            $this->template->upgrader = $upgraders[$upgraderId];
        }

        $user = $this->getUser();
        $this->hermesEmitter->emit(new HermesMessage('sales-funnel', [
            'type' => 'checkout',
            'user_id' => $user->id,
            'browser_id' => (isset($_COOKIE['browser_id']) ? $_COOKIE['browser_id'] : null),
            'source' => $this->trackingParams(),
            'sales_funnel_id' => self::SALES_FUNNEL_UPGRADE,
        ]));

        $this->template->upgraderId = $upgraderId ?? 0;
    }

    public function renderSuccess()
    {
        $section = $this->getSession('upgrade');
        if ($section->referer) {
            $this->template->redirect = $section->referer;
        }
    }

    public function renderNotAvailable($error)
    {
        $this->template->user = $this->usersRepository->find($this->getUser()->getId());
        $this->template->error = $error;
    }

    public function renderError()
    {
    }
}
