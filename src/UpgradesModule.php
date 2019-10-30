<?php

namespace Crm\UpgradesModule;

use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Menu\MenuContainerInterface;
use Crm\ApplicationModule\Menu\MenuItem;
use Crm\ApplicationModule\Widget\WidgetManagerInterface;
use Crm\UpgradesModule\Components\FreeRecurrentWidget;
use Crm\UpgradesModule\Components\PaidExtendWidget;
use Crm\UpgradesModule\Components\PaidRecurrentWidget;
use Crm\UpgradesModule\Components\ShortWidget;
use Crm\UpgradesModule\Components\UserPaymentsListingBadge;
use League\Event\Emitter;

class UpgradesModule extends CrmModule
{
    public function registerAdminMenuItems(MenuContainerInterface $menuContainer)
    {
        $menuItem = new MenuItem($this->translator->translate('upgrades.menu.upgrades'), ':Upgrades:UpgradesAdmin:', 'fa fa-arrow-alt-circle-up', 700);
        $menuContainer->attachMenuItemToForeignModule('#payments', $menuItem, $menuItem);
    }

    public function registerWidgets(WidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'frontend.upgrades.subscription',
            $this->getInstance(PaidRecurrentWidget::class)
        );
        $widgetManager->registerWidget(
            'frontend.upgrades.subscription',
            $this->getInstance(PaidExtendWidget::class)
        );
        $widgetManager->registerWidget(
            'frontend.upgrades.subscription',
            $this->getInstance(ShortWidget::class)
        );
        $widgetManager->registerWidget(
            'frontend.upgrades.subscription',
            $this->getInstance(FreeRecurrentWidget::class)
        );

        $widgetManager->registerWidget(
            'admin.payments.user_payments_listing.badge',
            $this->getInstance(UserPaymentsListingBadge::class)
        );
    }

    public function registerEventHandlers(Emitter $emitter)
    {
        $emitter->addListener(
            \Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class,
            $this->getInstance(\Crm\UpgradesModule\Events\PaymentStatusChangeHandler::class),
            1000 // we need to have this executed before \Crm\PaymentsModule\Events\PaymentStatusChangeHandler
        );

        $emitter->addListener(
            \Crm\UpgradesModule\Events\SubscriptionShortenedEvent::class,
            $this->getInstance(\Crm\UpgradesModule\Events\SubscriptionShortenedHandler::class)
        );
    }
}
