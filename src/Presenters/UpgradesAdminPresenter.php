<?php

namespace Crm\UpgradesModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Components\Graphs\GoogleLineGraphGroup\GoogleLineGraphGroupControlFactoryInterface;
use Crm\ApplicationModule\Components\PreviousNextPaginator\PreviousNextPaginator;
use Crm\ApplicationModule\Models\Graphs\Criteria;
use Crm\ApplicationModule\Models\Graphs\GraphDataItem;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\UpgradesModule\Models\Upgrade\UpgraderFactory;
use Nette\DI\Attributes\Inject;

class UpgradesAdminPresenter extends AdminPresenter
{
    #[Inject]
    public PaymentsRepository $paymentsRepository;

    #[Inject]
    public UpgraderFactory $upgraderFactory;

    /**
     * @admin-access-level read
     */
    public function renderDefault($type = null)
    {
        $where = ['upgrade_type IS NOT NULL'];
        if ($type) {
            $where['upgrade_type'] = $type;
        }

        $availableTypes = [];
        foreach ($this->upgraderFactory->getUpgraders() as $upgraderType => $upgrader) {
            $availableTypes[] = $upgraderType;
        }

        $payments = $this->paymentsRepository->all()->where($where)->order('modified_at DESC');

        $pnp = new PreviousNextPaginator();
        $this->addComponent($pnp, 'paginator');
        $paginator = $pnp->getPaginator();
        $paginator->setItemsPerPage($this->onPage);

        $payments = $payments->limit($paginator->getLength(), $paginator->getOffset())->fetchAll();
        $pnp->setActualItemCount(count($payments));

        $this->template->payments = $payments;
        $this->template->type = $type;
        $this->template->availableTypes = $availableTypes;
    }

    protected function createComponentUpgradesGraph(GoogleLineGraphGroupControlFactoryInterface $factory)
    {
        $graphDataItem1 = new GraphDataItem();
        $graphDataItem2 = new GraphDataItem();
        $upgradeTypeWhere = 'AND upgrade_type IS NOT NULL';
        if (isset($this->params['type'])) {
            $upgradeTypeWhere = "AND upgrade_type = '" . addslashes($this->params['type']) . "'";
        }
        $graphDataItem1->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('modified_at')
            ->setWhere($upgradeTypeWhere)
            ->setValueField('COUNT(*)')
            ->setStart('-1 month'))
            ->setName($this->translator->translate('upgrades.admin.upgrades.all_upgrades'));

        $graphDataItem2->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('modified_at')
            ->setWhere($upgradeTypeWhere . ' AND payments.status = \'paid\'')
            ->setValueField('COUNT(*)')
            ->setStart('-1 month'))
            ->setName($this->translator->translate('upgrades.admin.upgrades.paid_upgrades'));

        $control = $factory->create()
            ->setGraphTitle($this->translator->translate('upgrades.admin.upgrades.title'))
            ->setGraphHelp($this->translator->translate('upgrades.admin.upgrades.upgrades_in_time'))
            ->addGraphDataItem($graphDataItem1)
            ->addGraphDataItem($graphDataItem2);

        return $control;
    }
}
