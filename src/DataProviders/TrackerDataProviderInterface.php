<?php

namespace Crm\UpgradesModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderInterface;

interface TrackerDataProviderInterface extends DataProviderInterface
{
    public function provide(?array $params = []): array;
}
