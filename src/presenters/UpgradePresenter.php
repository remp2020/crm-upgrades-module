<?php

namespace Crm\UpgradesModule\Presenters;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\UpgradesModule\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Upgrade\UpgradeException;
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
