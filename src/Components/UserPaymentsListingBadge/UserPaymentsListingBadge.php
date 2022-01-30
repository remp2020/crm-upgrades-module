<?php

namespace Crm\UpgradesModule\Components;

use Crm\ApplicationModule\Widget\BaseWidget;
use Nette\Database\Table\ActiveRow;

class UserPaymentsListingBadge extends BaseWidget
{
    public function identifier()
    {
        return 'upgradesuserpaymentslistingbadge';
    }

    public function render(ActiveRow $payment)
    {
        if (!$payment->upgrade_type) {
            return;
        }

        $this->template->payment = $payment;
        $this->template->setFile(__DIR__ . '/user_payments_listing_badge.latte');
        $this->template->render();
    }
}
