<?php

namespace Crm\UpgradesModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Symfony\Component\Console\Output\OutputInterface;

class TestSeeder implements ISeeder
{
    private $subscriptionTypesRepository;

    private $subscriptionTypeBuilder;

    public function __construct(
        SubscriptionTypesRepository $subscriptionTypesRepository,
        SubscriptionTypeBuilder $subscriptionTypeBuilder
    ) {
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->subscriptionTypeBuilder = $subscriptionTypeBuilder;
    }

    public function seed(OutputInterface $output)
    {
        $subscriptionTypeCode = 'upgrade_tests_yearly';
        if (!$this->subscriptionTypesRepository->exists($subscriptionTypeCode)) {
            $subscriptionType = $this->subscriptionTypeBuilder->createNew()
                ->setNameAndUserLabel('Upgrade tests yearly')
                ->setCode($subscriptionTypeCode)
                ->setPrice(123.45)
                ->setLength(365)
                ->setSorting(20)
                ->setActive(true)
                ->setVisible(true)
                ->setContentAccessOption('web')
                ->save();
            $output->writeln("  <comment>* subscription type <info>{$subscriptionTypeCode}</info> created</comment>");
        } else {
            $output->writeln("  * subscription type <info>{$subscriptionTypeCode}</info> exists");
        }

        $subscriptionTypeCode = 'upgrade_tests_monthly';
        if (!$this->subscriptionTypesRepository->exists($subscriptionTypeCode)) {
            $subscriptionType = $this->subscriptionTypeBuilder->createNew()
                ->setNameAndUserLabel('Upgrade tests yearly')
                ->setCode($subscriptionTypeCode)
                ->setPrice(12.34)
                ->setLength(31)
                ->setSorting(20)
                ->setActive(true)
                ->setVisible(true)
                ->setContentAccessOption('web')
                ->save();
            $output->writeln("  <comment>* subscription type <info>{$subscriptionTypeCode}</info> created</comment>");
        } else {
            $output->writeln("  * subscription type <info>{$subscriptionTypeCode}</info> exists");
        }
    }
}
