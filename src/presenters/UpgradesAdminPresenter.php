<?php

namespace Crm\UpgradesModule\Presenters;

use Crm\ApplicationModule\Components\Graphs\GoogleLineGraphGroupControlFactoryInterface;
use Crm\ApplicationModule\Components\VisualPaginator;
use Crm\ApplicationModule\Graphs\Criteria;
use Crm\ApplicationModule\Graphs\GraphDataItem;
use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\UpgradesModule\Upgrade\UpgraderFactory;

class UpgradesAdminPresenter extends AdminPresenter
{
    /** @var PaymentsRepository @inject */
    public $paymentsRepository;

    /** @var UpgraderFactory @inject */
    public $upgraderFactory;

    public function renderDefault($type = null)
    {
        $where = ['upgrade_type IS NOT NULL'];
        $totalCount = $this->paymentsRepository->all()->where($where)->count('*');
        if ($type) {
            $where['upgrade_type'] = $type;
        }

        $typesCounts = [];
        foreach ($this->upgraderFactory->getUpgraders() as $upgraderType => $upgrader) {
            $typesCounts[$upgraderType] = $this->paymentsRepository->all()->where(['upgrade_type' => $upgraderType])->count('*');
        }
        $this->template->typesCounts = $typesCounts;

        $payments = $this->paymentsRepository->all()->where($where)->order('modified_at DESC');
        $filteredCount = $payments->count('*');

        $vp = new VisualPaginator();
        $this->addComponent($vp, 'vp');
        $paginator = $vp->getPaginator();
        $paginator->setItemCount($filteredCount);
        $paginator->setItemsPerPage($this->onPage);
        $this->template->vp = $vp;
        $this->template->payments = $payments->limit($paginator->getLength(), $paginator->getOffset());
        $this->template->totalPayments = $totalCount;
        $this->template->filteredCount = $filteredCount;
        $this->template->type = $type;
        $this->template->availableTypes = array_keys($typesCounts);
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
