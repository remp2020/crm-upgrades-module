<?php

namespace Crm\UpgradesModule;

use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Event\LazyEventEmitter;
use Crm\ApplicationModule\Menu\MenuContainerInterface;
use Crm\ApplicationModule\Menu\MenuItem;
use Crm\ApplicationModule\Widget\LazyWidgetManagerInterface;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\UpgradesModule\Components\FreeRecurrentWidget;
use Crm\UpgradesModule\Components\PaidExtendWidget;
use Crm\UpgradesModule\Components\PaidRecurrentWidget;
use Crm\UpgradesModule\Components\ShortWidget;
use Crm\UpgradesModule\Components\UserPaymentsListingBadge;
use Crm\UpgradesModule\Events\PaymentStatusChangeHandler;

class UpgradesModule extends CrmModule
{
    public const SUBSCRIPTION_TYPE_UPGRADE = 'upgrade';

    public function registerAdminMenuItems(MenuContainerInterface $menuContainer)
    {
        $menuItem = new MenuItem($this->translator->translate('upgrades.menu.upgrades'), ':Upgrades:UpgradesAdmin:', 'fa fa-arrow-alt-circle-up', 700);
        $menuContainer->attachMenuItemToForeignModule('#payments', $menuItem, $menuItem);
    }

    public function registerLazyWidgets(LazyWidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'frontend.upgrades.subscription',
            PaidRecurrentWidget::class
        );
        $widgetManager->registerWidget(
            'frontend.upgrades.subscription',
            PaidExtendWidget::class
        );
        $widgetManager->registerWidget(
            'frontend.upgrades.subscription',
            ShortWidget::class
        );
        $widgetManager->registerWidget(
            'frontend.upgrades.subscription',
            FreeRecurrentWidget::class
        );

        $widgetManager->registerWidget(
            'admin.payments.user_payments_listing.badge',
            UserPaymentsListingBadge::class
        );
    }

    public function registerLazyEventHandlers(LazyEventEmitter $emitter)
    {
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            PaymentStatusChangeHandler::class,
            1000 // we need to have this executed before \Crm\PaymentsModule\Events\PaymentStatusChangeHandler
        );
    }
}
