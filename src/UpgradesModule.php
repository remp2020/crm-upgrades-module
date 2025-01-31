<?php

namespace Crm\UpgradesModule;

use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Models\Menu\MenuContainerInterface;
use Crm\ApplicationModule\Models\Menu\MenuItem;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManagerInterface;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\SubscriptionsModule\Events\SubscriptionEndsEvent;
use Crm\UpgradesModule\Components\AvailableUpgradesForSubscriptionTypeWidget\AvailableUpgradesForSubscriptionTypeWidget;
use Crm\UpgradesModule\Components\FreeRecurrentWidget\FreeRecurrentWidget;
use Crm\UpgradesModule\Components\PaidExtendWidget\PaidExtendWidget;
use Crm\UpgradesModule\Components\PaidRecurrentWidget\PaidRecurrentWidget;
use Crm\UpgradesModule\Components\ShortWidget\ShortWidget;
use Crm\UpgradesModule\Components\UserPaymentsListingBadge\UserPaymentsListingBadge;
use Crm\UpgradesModule\DataProviders\BaseSubscriptionDataProvider;
use Crm\UpgradesModule\Events\PaymentStatusChangeHandler;
use Crm\UpgradesModule\Events\TrialSubscriptionEndsEventHandler;

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

        $widgetManager->registerWidget(
            'subscription_types_admin.show.right',
            AvailableUpgradesForSubscriptionTypeWidget::class,
            500,
        );
    }

    public function registerLazyEventHandlers(LazyEventEmitter $emitter)
    {
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            PaymentStatusChangeHandler::class,
            1000 // we need to have this executed before \Crm\PaymentsModule\Events\PaymentStatusChangeHandler
        );

        $emitter->addListener(
            SubscriptionEndsEvent::class,
            TrialSubscriptionEndsEventHandler::class,
            LazyEventEmitter::P_LOW
        );
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'payments.dataprovider.base_subscription',
            $this->getInstance(BaseSubscriptionDataProvider::class)
        );
    }
}
