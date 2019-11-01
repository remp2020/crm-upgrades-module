<?php

namespace Crm\UpgradesModule\Tests;

use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\UpgradesModule\Events\SubscriptionShortenedEvent;
use Crm\UpgradesModule\Events\SubscriptionShortenedHandler;
use Crm\UpgradesModule\Seeders\TestSeeder;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\IRow;

class SubscriptionShortenedHandlerTest extends DatabaseTestCase
{
    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    /** @var SubscriptionTypesRepository */
    private $subscriptionTypesRepository;

    /** @var UserManager */
    private $userManager;

    /** @var SubscriptionShortenedHandler */
    private $subscriptionShortenedHandler;

    protected function requiredRepositories(): array
    {
        return [
            SubscriptionsRepository::class,
            SubscriptionTypesRepository::class,
            UsersRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            TestSeeder::class,
        ];
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->subscriptionShortenedHandler = $this->inject(SubscriptionShortenedHandler::class);
    }

    public function testNoAction()
    {
        $user = $this->loadUser('admin@example.com');
        $baseSubscription = $this->createSubscription($user, 'upgrade_tests_yearly', new \DateTime('2019-01-01'));

        $endTime = new \DateTime('2019-07-01');
        $upgradedSubscription = $this->createSubscription($user, 'upgrade_tests_monthly', $endTime);
        $this->subscriptionsRepository->update($baseSubscription, [
            'end_time' => $endTime,
        ]);
        $this->subscriptionShortenedHandler->handle(
            new SubscriptionShortenedEvent($baseSubscription, new \DateTime('2020-01-01'), $upgradedSubscription)
        );

        $subscriptions = [];
        foreach ($this->subscriptionsRepository->userSubscriptions($user->id) as $s) {
            $subscriptions[] = $s;
        }
        $this->assertCount(2, $subscriptions);
        $this->assertEquals(new \DateTime('2019-01-01'), $subscriptions[1]->start_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[1]->end_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[0]->start_time);
        $this->assertEquals(new \DateTime('2019-08-01'), $subscriptions[0]->end_time);
    }

    public function testShortFirstHandleMoveOfSecond()
    {
        $user = $this->loadUser('admin@example.com');
        $subscription1 = $this->createSubscription($user, 'upgrade_tests_yearly', new \DateTime('2019-01-01'));
        $subscription2 = $this->createSubscription($user, 'upgrade_tests_yearly', $subscription1->end_time);

        $endTime = new \DateTime('2019-07-01');
        $upgradedSubscription = $this->createSubscription($user, 'upgrade_tests_monthly', $endTime);
        $this->subscriptionsRepository->update($subscription1, [
            'end_time' => $endTime,
        ]);
        $this->subscriptionShortenedHandler->handle(
            new SubscriptionShortenedEvent($subscription1, new \DateTime('2020-01-01'), $upgradedSubscription)
        );

        $subscriptions = [];
        foreach ($this->subscriptionsRepository->userSubscriptions($user->id) as $s) {
            $subscriptions[] = $s;
        }
        $this->assertCount(3, $subscriptions);
        $this->assertEquals(new \DateTime('2019-01-01'), $subscriptions[2]->start_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[2]->end_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[1]->start_time);
        $this->assertEquals(new \DateTime('2019-08-01'), $subscriptions[1]->end_time);
        $this->assertEquals(new \DateTime('2019-08-01'), $subscriptions[0]->start_time);
        $this->assertEquals(new \DateTime('2020-07-31'), $subscriptions[0]->end_time); // leap year
    }

    public function testShortFirstHandleMoveOfSecondAndThird()
    {
        $user = $this->loadUser('admin@example.com');
        $subscription1 = $this->createSubscription($user, 'upgrade_tests_yearly', new \DateTime('2019-01-01'));
        $subscription2 = $this->createSubscription($user, 'upgrade_tests_yearly', $subscription1->end_time);
        $subscription3 = $this->createSubscription($user, 'upgrade_tests_yearly', $subscription2->end_time);

        $endTime = new \DateTime('2019-07-01');
        $upgradedSubscription = $this->createSubscription($user, 'upgrade_tests_monthly', $endTime);
        $this->subscriptionsRepository->update($subscription1, [
            'end_time' => $endTime,
        ]);
        $this->subscriptionShortenedHandler->handle(
            new SubscriptionShortenedEvent($subscription1, new \DateTime('2020-01-01'), $upgradedSubscription)
        );

        $subscriptions = [];
        foreach ($this->subscriptionsRepository->userSubscriptions($user->id) as $s) {
            $subscriptions[] = $s;
        }
        $this->assertCount(4, $subscriptions);
        $this->assertEquals(new \DateTime('2019-01-01'), $subscriptions[3]->start_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[3]->end_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[2]->start_time);
        $this->assertEquals(new \DateTime('2019-08-01'), $subscriptions[2]->end_time);
        $this->assertEquals(new \DateTime('2019-08-01'), $subscriptions[1]->start_time);
        $this->assertEquals(new \DateTime('2020-07-31'), $subscriptions[1]->end_time); // 2020 is leap year
        $this->assertEquals(new \DateTime('2020-07-31'), $subscriptions[0]->start_time);
        $this->assertEquals(new \DateTime('2021-07-31'), $subscriptions[0]->end_time);
    }

    public function testIgnoreOfParallelSubscription()
    {
        $user = $this->loadUser('admin@example.com');
        $subscription1 = $this->createSubscription($user, 'upgrade_tests_yearly', new \DateTime('2019-01-01'));
        $parallelSubscription = $this->createSubscription($user, 'upgrade_tests_yearly', new \DateTime('2019-06-15'), new \DateTime('2019-07-15'));
        $subscription2 = $this->createSubscription($user, 'upgrade_tests_yearly', $subscription1->end_time);

        $endTime = new \DateTime('2019-07-01');
        $upgradedSubscription = $this->createSubscription($user, 'upgrade_tests_monthly', $endTime);
        $this->subscriptionsRepository->update($subscription1, [
            'end_time' => $endTime,
        ]);
        $this->subscriptionShortenedHandler->handle(
            new SubscriptionShortenedEvent($subscription1, new \DateTime('2020-01-01'), $upgradedSubscription)
        );

        $subscriptions = [];
        foreach ($this->subscriptionsRepository->userSubscriptions($user->id) as $s) {
            $subscriptions[] = $s;
        }
        $this->assertCount(4, $subscriptions);
        $this->assertEquals(new \DateTime('2019-01-01'), $subscriptions[3]->start_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[3]->end_time);
        $this->assertEquals(new \DateTime('2019-06-15'), $subscriptions[2]->start_time);
        $this->assertEquals(new \DateTime('2019-07-15'), $subscriptions[2]->end_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[1]->start_time);
        $this->assertEquals(new \DateTime('2019-08-01'), $subscriptions[1]->end_time);
        $this->assertEquals(new \DateTime('2019-08-01'), $subscriptions[0]->start_time);
        $this->assertEquals(new \DateTime('2020-07-31'), $subscriptions[0]->end_time); // 2020 is leap year
    }

    public function testDifferentUserNotAffected()
    {
        $user1 = $this->loadUser('admin@example.com');
        $user2 = $this->loadUser('user@example.com');

        $subscription1 = $this->createSubscription($user1, 'upgrade_tests_yearly', new \DateTime('2019-01-01'));
        $subscription2 = $this->createSubscription($user2, 'upgrade_tests_yearly', $subscription1->end_time);

        $endTime = new \DateTime('2019-07-01');
        $upgradedSubscription = $this->createSubscription($user1, 'upgrade_tests_monthly', $endTime);
        $this->subscriptionsRepository->update($subscription1, [
            'end_time' => $endTime,
        ]);
        $this->subscriptionShortenedHandler->handle(
            new SubscriptionShortenedEvent($subscription1, new \DateTime('2020-01-01'), $upgradedSubscription)
        );

        $subscriptions = [];
        foreach ($this->subscriptionsRepository->userSubscriptions($user1->id) as $s) {
            $subscriptions[] = $s;
        }
        $this->assertCount(2, $subscriptions);
        $this->assertEquals(new \DateTime('2019-01-01'), $subscriptions[1]->start_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[1]->end_time);
        $this->assertEquals(new \DateTime('2019-07-01'), $subscriptions[0]->start_time);
        $this->assertEquals(new \DateTime('2019-08-01'), $subscriptions[0]->end_time);

        $subscriptions = [];
        foreach ($this->subscriptionsRepository->userSubscriptions($user2->id) as $s) {
            $subscriptions[] = $s;
        }
        $this->assertEquals(new \DateTime('2020-01-01'), $subscriptions[0]->start_time);
        $this->assertEquals(new \DateTime('2020-12-31'), $subscriptions[0]->end_time); // 2020 is leap year
    }

    private function createSubscription(ActiveRow $user, string $code, \DateTime $startTime, \DateTime $endTime = null)
    {
        $st = $this->subscriptionTypesRepository->findByCode($code);
        return $this->subscriptionsRepository->add(
            $st,
            false,
            $user,
            SubscriptionsRepository::TYPE_REGULAR,
            $startTime,
            $endTime
        );
    }

    private function loadUser($email) : IRow
    {
        $user = $this->userManager->loadUserByEmail($email);
        if (!$user) {
            $user = $this->userManager->addNewUser($email);
        }
        return $user;
    }
}
