<?php

namespace Crm\UpgradesModule\Tests;

use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\SubscriptionMovedHandler;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\RecurrentPaymentsResolver;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Seeders\PaymentGatewaysSeeder;
use Crm\PaymentsModule\Seeders\TestPaymentGatewaysSeeder;
use Crm\PaymentsModule\Tests\Gateways\TestRecurrentGateway;
use Crm\PaymentsModule\Tests\Gateways\TestSingleGateway;
use Crm\SubscriptionsModule\Events\SubscriptionMovedEvent;
use Crm\SubscriptionsModule\Events\SubscriptionShortenedEvent;
use Crm\SubscriptionsModule\Events\SubscriptionShortenedHandler;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Models\Extension\ExtendActualExtension;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\ContentAccessRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeContentAccessRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UpgradesModule\Events\PaymentStatusChangeHandler;
use Crm\UpgradesModule\Models\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Models\Upgrade\FreeRecurrentUpgrade;
use Crm\UpgradesModule\Models\Upgrade\PaidExtendUpgrade;
use Crm\UpgradesModule\Models\Upgrade\PaidRecurrentUpgrade;
use Crm\UpgradesModule\Models\Upgrade\ShortUpgrade;
use Crm\UpgradesModule\Models\Upgrade\SpecificUserSubscriptions;
use Crm\UpgradesModule\Models\Upgrade\UpgraderFactory;
use Crm\UpgradesModule\Repositories\SubscriptionTypeUpgradeSchemasRepository;
use Crm\UpgradesModule\Repositories\SubscriptionUpgradesRepository;
use Crm\UpgradesModule\Repositories\UpgradeOptionsRepository;
use Crm\UpgradesModule\Repositories\UpgradeSchemasRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;

class SubsequentShortUpgradeTest extends DatabaseTestCase
{
    private const GATEWAY_RECURRENT = TestRecurrentGateway::GATEWAY_CODE;
    private const GATEWAY_NON_RECURRENT = TestSingleGateway::GATEWAY_CODE;

    private const SUBSCRIPTION_TYPE_BASIC = 'st_basic';
    private const SUBSCRIPTION_TYPE_BASIC_LONG = 'st_basic_long';
    private const SUBSCRIPTION_TYPE_PREMIUM = 'st_premium';
    private const SUBSCRIPTION_TYPE_PREMIUM_LONG = 'st_premium_long';

    private const CONTENT_ACCESS_BASIC = 'basic';
    private const CONTENT_ACCESS_PREMIUM = 'premium';

    private bool $initialized = false;

    /** @var AvailableUpgraders */
    private $availableUpgraders;

    /** @var UserManager */
    private $userManager;

    /** @var UsersRepository  */
    private $usersRepository;

    /** @var SubscriptionsRepository */
    private $subscriptionsRepository;

    /** @var SubscriptionTypesRepository */
    private $subscriptionTypesRepository;

    /** @var PaymentsRepository  */
    private $paymentsRepository;

    /** @var PaymentGatewaysRepository */
    private $paymentGatewaysRepository;

    /** @var RecurrentPaymentsRepository */
    private $recurrentPaymentsRepository;

    /** @var SpecificUserSubscriptions */
    private $upgradeableSubscriptions;

    public function setUp(): void
    {
        parent::setUp();

        $this->availableUpgraders = $this->inject(AvailableUpgraders::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);
        $this->upgradeableSubscriptions = $this->inject(SpecificUserSubscriptions::class);

        /** @var LazyEventEmitter $lazyEventEmitter */
        $lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        /** @var PaymentStatusChangeHandler $upgradeStatusChangeHandler */
        $upgradeStatusChangeHandler = $this->inject(PaymentStatusChangeHandler::class);
        /** @var UpgraderFactory $upgraderFactory */
        $upgraderFactory = $this->inject(UpgraderFactory::class);

        $shortUpgrade = $upgraderFactory->getUpgraders()[ShortUpgrade::TYPE];
        $upgraderFactory->setSubsequentUpgrader($shortUpgrade);

        $now = DateTime::from('2021-04-05');
        $this->availableUpgraders->setNow($now);
        $this->subscriptionsRepository->setNow($now);
        $this->recurrentPaymentsRepository->setNow($now);
        $extension = $this->inject(ExtendActualExtension::class);
        $extension->setNow($now);
        $upgradeStatusChangeHandler->setNow($now);
        $upgraderFactory->setNow($now);

        // initialize subscription types
        $basic = $this->getSubscriptionType(self::SUBSCRIPTION_TYPE_BASIC, [self::CONTENT_ACCESS_BASIC], 31, 5);
        $basicLong = $this->getSubscriptionType(self::SUBSCRIPTION_TYPE_BASIC_LONG, [self::CONTENT_ACCESS_BASIC], 365, 50);
        $premium = $this->getSubscriptionType(self::SUBSCRIPTION_TYPE_PREMIUM, [self::CONTENT_ACCESS_PREMIUM], 31, 10);
        $premiumLong = $this->getSubscriptionType(self::SUBSCRIPTION_TYPE_PREMIUM_LONG, [self::CONTENT_ACCESS_PREMIUM], 365, 100);

        $this->configureUpgradeOption(
            schema: 'default',
            baseSubscriptionType: $basic,
            requireContent: [self::CONTENT_ACCESS_PREMIUM],
            omitContent: [self::CONTENT_ACCESS_BASIC],
        );
        $this->configureUpgradeOption(
            schema: 'default',
            baseSubscriptionType: $basicLong,
            requireContent: [self::CONTENT_ACCESS_PREMIUM],
            omitContent: [self::CONTENT_ACCESS_BASIC],
        );

        if (!$this->initialized) {
            // clear initialized handlers (we do not want duplicated listeners)
            $lazyEventEmitter->removeAllListeners(PaymentChangeStatusEvent::class);
            $lazyEventEmitter->removeAllListeners(SubscriptionShortenedEvent::class);
            $lazyEventEmitter->removeAllListeners(SubscriptionMovedEvent::class);

            // bind necessary event handlers
            $lazyEventEmitter->addListener(
                PaymentChangeStatusEvent::class,
                $upgradeStatusChangeHandler,
                1000 // we need to have this executed before \Crm\PaymentsModule\Events\PaymentStatusChangeHandler
            );
            $lazyEventEmitter->addListener(
                PaymentChangeStatusEvent::class,
                $this->inject(\Crm\PaymentsModule\Events\PaymentStatusChangeHandler::class),
                500
            );

            $lazyEventEmitter->addListener(
                SubscriptionShortenedEvent::class,
                $this->inject(SubscriptionShortenedHandler::class),
            );

            $lazyEventEmitter->addListener(
                SubscriptionMovedEvent::class,
                $this->inject(SubscriptionMovedHandler::class),
            );

            $this->initialized = true;
        }
    }

    public function tearDown(): void
    {
        $this->availableUpgraders->setNow(null);
        $this->subscriptionsRepository->setNow(null);
        $this->recurrentPaymentsRepository->setNow(null);

        $this->inject(ExtendActualExtension::class)->setNow(null);
        $this->inject(PaymentStatusChangeHandler::class)->setNow(null);
        $this->inject(UpgraderFactory::class)->setNow(null);

        /** @var LazyEventEmitter $lazyEventEmitter */
        $lazyEventEmitter = $this->inject(LazyEventEmitter::class);

        $lazyEventEmitter->removeAllListeners(PaymentChangeStatusEvent::class);
        $lazyEventEmitter->removeAllListeners(SubscriptionShortenedEvent::class);
        $lazyEventEmitter->removeAllListeners(SubscriptionMovedEvent::class);

        parent::tearDown();
    }

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
            UserMetaRepository::class,
            SubscriptionsRepository::class,
            PaymentsRepository::class,
            PaymentMetaRepository::class,
            PaymentItemsRepository::class,
            PaymentItemMetaRepository::class,
            PaymentGatewaysRepository::class,
            SubscriptionTypesRepository::class,
            SubscriptionTypeItemsRepository::class,
            SubscriptionTypesMetaRepository::class,
            SubscriptionTypeUpgradeSchemasRepository::class,
            SubscriptionUpgradesRepository::class,
            UpgradeSchemasRepository::class,
            RecurrentPaymentsRepository::class,
            UpgradeOptionsRepository::class,
            SubscriptionTypeContentAccessRepository::class,
            ContentAccessRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            PaymentGatewaysSeeder::class,
            TestPaymentGatewaysSeeder::class,
            SubscriptionTypeNamesSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
        ];
    }

    public static function shortUpgradeDataProvider()
    {
        return [
            'Short_NoneFollowing_ShouldShortenOne' => [
                'upgradeType' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                ],
            ],
            'Short_OneFollowingSubscription_ShouldShortenBoth' => [
                'upgradeType' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                ],
            ],
            'Short_TwoFollowingSubscription_ShouldShortenAll' => [
                'upgradeType' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-06-05',
                        'end' => '2021-07-06',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-05 12:00:00', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-05-05 12:00:00', 'end' => '2021-05-21'],
                ],
            ],
            'Short_OneFollowingOneOverlapping_ShouldShortenOnlyFollowing' => [
                'upgradeType' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-04 12:00:00',
                        'end' => '2021-05-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-04 12:00:00', 'end' => '2021-05-05'], // untouched
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                ],
            ],
            'Short_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndMoveSecond' => [
                'upgradeType' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_PREMIUM,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-21'], // only moved
                ],
            ],
            'Short_BaseHasStoppedRecurrent_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndMoveSecond' => [
                'upgradeType' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                        'rp_state' => RecurrentPaymentsRepository::STATE_USER_STOP,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_PREMIUM,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '2222',
                        'rp_charge_at' => '2021-06-03',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-21'], // only moved
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'state' => RecurrentPaymentsRepository::STATE_SYSTEM_STOP],
                    ['cid' => '2222', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-05-19'], // keep "2 days before"
                ],
            ],
            'Short_FollowingOfDifferentLengths_ShouldUpgradeBothOfFollowing' => [
                'upgradeType' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC_LONG,
                        'start' => '2021-05-05',
                        'end' => '2022-05-05',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2022-05-03', // 2 days before end
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2022-05-05',
                        'end' => '2022-06-05',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                // now - 2021-04-05
                // basic - 5 eur / mesiac; 50 eur / rok
                // premium - 10 eur / mesiac; 100 eur / rok
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_LONG, 'start' => '2021-04-20', 'end' => '2021-10-19 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-10-19 12:00:00', 'end' => '2021-10-19 12:00:00'],
                    // 31 days -> 15.5 days, minus one hour for daylight savings happening at the end of October.
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-10-19 12:00:00', 'end' => '2021-11-03 23:00:00'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-10-17 12:00:00'], // keep "2 days before" end of "premium long"
                ],
            ],
        ];
    }

    public static function freeRecurrentUpgradeDataProvider()
    {
        return [
            'FreeRecurrent_NoneFollowing_ShouldUpgradeOne' => [
                'upgradeType' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-04-05',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-04-05'],
                ],
            ],
            'FreeRecurrent_OneFollowingSubscription_ShouldShortenSecond' => [
                'upgradeType' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-07',
                        'end' => '2021-05-08',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-06',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-07', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-07', 'end' => '2021-04-22 12:00:00'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-04-20 12:00:00'],
                ],
            ],
            'FreeRecurrent_TwoFollowingSubscriptions_ShouldShortenSecondAndThird' => [
                'upgradeType' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-07',
                        'end' => '2021-05-08',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-05-08',
                        'end' => '2021-06-08',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-06-06',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-07', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-07', 'end' => '2021-04-22 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-22 12:00:00', 'end' => '2021-04-22 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-22 12:00:00', 'end' => '2021-05-08'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-05-06'],
                ],
            ],
            'FreeRecurrent_OneFollowingOneOverlapping_ShouldShortenOnlyFollowing' => [
                'upgradeType' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-07',
                        'end' => '2021-05-08',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-06',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-06',
                        'end' => '2022-04-06',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '2222',
                        'rp_charge_at' => '2022-04-04',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-07', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-07', 'end' => '2021-04-22 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-06', 'end' => '2022-04-06'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-04-20 12:00:00'],
                    ['cid' => '2222', 'type' => self::SUBSCRIPTION_TYPE_BASIC, 'charge_at' => '2022-04-04'],
                ],
            ],
            'FreeRecurrent_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndKeepSecondUntouched' => [
                'upgradeType' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_PREMIUM,
                        'start' => '2021-04-07',
                        'end' => '2021-05-08',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-06',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-07', 'end' => '2021-05-08'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-05-06'],
                ],
            ],
            'FreeRecurrent_FollowingOfDifferentLengths_ShouldUpgradeBothOfFollowing' => [
                'upgradeType' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC_LONG,
                        'start' => '2021-04-07',
                        'end' => '2022-04-07',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2022-04-07',
                        'end' => '2022-05-08',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                // now - 2021-04-05
                // basic - 5 eur / mesiac; 50 eur / rok
                // premium - 10 eur / mesiac; 100 eur / rok
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-04-07', 'end' => '2021-04-07'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_LONG, 'start' => '2021-04-07', 'end' => '2021-10-06 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-10-06 12:00:00', 'end' => '2021-10-06 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-10-06 12:00:00', 'end' => '2021-10-22'],
                ],
            ],
        ];
    }

    public static function paidRecurrentUpgradeDataProvider()
    {
        return [
            'PaidRecurrent_NoneFollowing_ShouldUpgradeOne' => [
                'upgradeType' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-04-18',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-04-18', 'amount' => 10.0, 'custom_amount' => null],
                ],
            ],
            'PaidRecurrent_OneFollowingSubscription_ShouldShortenSecond' => [
                'upgradeType' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-19',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-05-03 12:00:00'],
                ],
            ],
            'PaidRecurrent_TwoFollowingSubscriptions_ShouldShortenSecondAndThird' => [
                'upgradeType' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-05-21',
                        'end' => '2021-06-21',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-06-19',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-05 12:00:00', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-05-05 12:00:00', 'end' => '2021-05-21'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-05-19'],
                ],
            ],
            'PaidRecurrent_OneFollowingOneOverlapping_ShouldShortenOnlyFollowing' => [
                'upgradeType' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-19',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-05-01',
                        'end' => '2021-05-21',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '2222',
                        'rp_charge_at' => '2021-05-19',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-01', 'end' => '2021-05-21'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-05-03 12:00:00'],
                    ['cid' => '2222', 'type' => self::SUBSCRIPTION_TYPE_BASIC, 'charge_at' => '2021-05-19'],
                ],
            ],
            'PaidRecurrent_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndKeepSecondUntouched' => [
                'upgradeType' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_PREMIUM,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-19',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-21'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-05-19'],
                ],
            ],
            'PaidRecurrent_FollowingOfDifferentLengths_ShouldUpgradeBothOfFollowing' => [
                'upgradeType' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC_LONG,
                        'start' => '2021-04-20',
                        'end' => '2022-04-20',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2022-04-20',
                        'end' => '2022-05-21',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                // now - 2021-04-05
                // basic - 5 eur / mesiac; 50 eur / rok
                // premium - 10 eur / mesiac; 100 eur / rok
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_LONG, 'start' => '2021-04-20', 'end' => '2021-10-19 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-10-19 12:00:00', 'end' => '2021-10-19 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-10-19 12:00:00', 'end' => '2021-11-03 23:00:00'], // daylight savings
                ],
            ],
        ];
    }

    public static function paidExtendUpgradeDataProvider()
    {
        return [
            'PaidExtend_NoneFollowing_ShouldUpgradeOne' => [
                'upgradeType' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                ],
            ],
            'PaidExtend_OneFollowingSubscription_ShouldShortenSecond' => [
                'upgradeType' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-06', 'end' => '2021-05-06'], // start was moved before shortened
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-05-21 12:00:00'],
                ],
            ],
            'PaidExtend_TwoFollowingSubscriptions_ShouldShortenSecondAndThird' => [
                'upgradeType' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-05-21',
                        'end' => '2021-06-21',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-06', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-05-21 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-21 12:00:00', 'end' => '2021-05-21 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-05-21 12:00:00', 'end' => '2021-06-06'],
                ],
            ],
            'PaidExtend_OneFollowingOneOverlapping_ShouldShortenOnlyFollowing' => [
                'upgradeType' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-05-01',
                        'end' => '2021-06-01',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-06', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-05-21 12:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-01', 'end' => '2021-06-01'],
                ],
            ],
            'PaidExtend_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndKeepSecondUntouched' => [
                'upgradeType' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_PREMIUM,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-06-06'],
                ],
            ],
            'PaidExtend_BaseHasStoppedRecurrent_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndKeepSecondUntouched' => [
                'upgradeType' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'upgradeable' => true,
                        'rp_state' => RecurrentPaymentsRepository::STATE_USER_STOP,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_PREMIUM,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '2222',
                        'rp_charge_at' => '2021-05-19',
                    ],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-06-06'],
                ],
                'recurrentResult' => [
                    ['cid' => '1111', 'state' => RecurrentPaymentsRepository::STATE_SYSTEM_STOP],
                    ['cid' => '2222', 'type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'charge_at' => '2021-06-04'], // keep "2 days before"
                ],
            ],
            'PaidExtend_FollowingOfDifferentLengths_ShouldUpgradeBothOfFollowing' => [
                'upgradeType' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC_LONG,
                        'start' => '2021-04-20',
                        'end' => '2022-04-20',
                        'gateway' => TestRecurrentGateway::GATEWAY_CODE,
                        'cid' => '1111',
                        'rp_charge_at' => '2022-04-18', // 2 days before end
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC,
                        'start' => '2022-04-20',
                        'end' => '2022-05-21',
                        'gateway' => self::GATEWAY_NON_RECURRENT,
                    ],
                ],
                // now - 2021-04-05
                // basic - 5 eur / mesiac; 50 eur / rok
                // premium - 10 eur / mesiac; 100 eur / rok
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-05-06', 'end' => '2021-05-06'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_LONG, 'start' => '2021-05-06', 'end' => '2021-11-04 11:00:00'], // would expect 12:00:00, but daylight savings time kicked in
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-11-04 11:00:00', 'end' => '2021-11-04 11:00:00'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM, 'start' => '2021-11-04 11:00:00', 'end' => '2021-11-19 23:00:00'], // 15.5 days of subscription
                ],
            ],
        ];
    }

    #[DataProvider('paidExtendUpgradeDataProvider')]
    #[DataProvider('shortUpgradeDataProvider')]
    #[DataProvider('paidRecurrentUpgradeDataProvider')]
    #[DataProvider('freeRecurrentUpgradeDataProvider')]
    public function testSubsequentUpgrade($upgradeType, $payments, $subscriptionResult, $recurrentResult = [])
    {
        $user = $this->getUser('user@example.com');

        foreach ($payments as $payment) {
            $p = $this->createAndConfirmPayment(
                $user,
                $payment['type'],
                DateTime::from($payment['start']),
                DateTime::from($payment['end']),
                $payment['gateway'],
                $payment['cid'] ?? null,
                $payment['rp_state'] ?? null,
                $payment['rp_charge_at'] ?? null,
            );

            // set currently created subscription as upgradeable if possible
            if (isset($payment['upgradeable'])) {
                $this->upgradeableSubscriptions->setSpecificSubscriptions($user->id, $p->subscription);
            }
        }

        // tell upgrader component which subscriptions should be considered upgradeable
        $this->availableUpgraders->setUpgradeableSubscriptions($this->upgradeableSubscriptions);

        $upgraders = $this->availableUpgraders->all($user->id);
        $this->assertCount(1, $upgraders);
        $this->assertEquals($upgradeType, $upgraders[0]->getType());

        $upgrader = $upgraders[0];
        if ($upgrader instanceof PaidExtendUpgrade) {
            $upgrader->setGateway($this->paymentGatewaysRepository->findByCode(self::GATEWAY_NON_RECURRENT));
        }
        $result = $upgrader->upgrade();
        if ($upgrader instanceof PaidExtendUpgrade) {
            $this->paymentsRepository->updateStatus($result, PaymentsRepository::STATUS_PAID);
        }

        $subscriptions = [];
        foreach ($this->subscriptionsRepository->userSubscriptions($user->id)->fetchAll() as $s) {
            $subscriptions[] = $s;
        }
        $subscriptions = array_reverse($subscriptions);

        $this->assertCount(count($subscriptionResult), $subscriptions);

        foreach ($subscriptionResult as $i => $expectedSubscription) {
            $this->assertEquals(
                $expectedSubscription['type'],
                $subscriptions[$i]->subscription_type->code,
                'The ' . ($i+1) . '. subscription doesn\'t match the expected subscription type.',
            );
            $this->assertEquals(DateTime::from($expectedSubscription['start']), $subscriptions[$i]->start_time);
            $this->assertEquals(DateTime::from($expectedSubscription['end']), $subscriptions[$i]->end_time);
        }

        foreach ($recurrentResult as $expectedRecurrent) {
            $rp = $this->recurrentPaymentsRepository->userRecurrentPayments($user->id)
                ->where(['payment_method.external_token' => $expectedRecurrent['cid']])
                ->order('charge_at DESC')
                ->fetch();

            if (isset($expectedRecurrent['state'])) {
                $this->assertEquals($expectedRecurrent['state'], $rp->state);
                continue;
            }

            /** @var RecurrentPaymentsResolver $rpResolver */
            $rpResolver = $this->inject(RecurrentPaymentsResolver::class);
            $paymentData = $rpResolver->resolvePaymentData($rp);
            $this->assertEquals($expectedRecurrent['type'], $paymentData->subscriptionType->code);
            $this->assertEquals(DateTime::from($expectedRecurrent['charge_at']), $rp->charge_at);
            if (array_key_exists('amount', $expectedRecurrent)) {
                $this->assertEquals($expectedRecurrent['amount'], $paymentData->paymentItemContainer->totalPrice());
            }
            if (array_key_exists('custom_amount', $expectedRecurrent)) {
                $this->assertEquals($expectedRecurrent['custom_amount'], $paymentData->customChargeAmount);
            }
        }
    }

    private function getUser($email) : ActiveRow
    {
        $user = $this->userManager->loadUserByEmail($email);
        if (!$user) {
            $user = $this->usersRepository->add($email, 'secret');
        }
        return $user;
    }

    private function getSubscriptionType(string $code, array $contentAccess, int $length, $price = null)
    {
        /** @var ContentAccessRepository $contentAccessRepository */
        $contentAccessRepository = $this->inject(ContentAccessRepository::class);
        foreach ($contentAccess as $caCode) {
            if (!$contentAccessRepository->exists($caCode)) {
                $contentAccessRepository->add($caCode, $caCode);
            }
        }

        $subscriptionType = $this->subscriptionTypesRepository->findByCode($code);
        if (!$subscriptionType && $price) {
            /** @var SubscriptionTypeBuilder $builder */
            $builder = $this->inject(SubscriptionTypeBuilder::class);
            $subscriptionType = $builder->createNew()
                ->setName($code)
                ->setUserLabel($code)
                ->setPrice($price)
                ->setCode($code)
                ->setLength($length)
                ->setActive(true)
                ->setDefault(true)
                ->setContentAccessOption(...$contentAccess)
                ->save();
        }

        return $subscriptionType;
    }

    private function configureUpgradeOption(
        string $schema,
        ActiveRow $baseSubscriptionType,
        array $requireContent,
        array $omitContent
    ) {
        /** @var UpgradeSchemasRepository $upgradeSchemasRepository */
        $upgradeSchemasRepository = $this->inject(UpgradeSchemasRepository::class);
        $schemaRow = $upgradeSchemasRepository->findByName($schema);
        if (!$schemaRow) {
            $schemaRow = $upgradeSchemasRepository->add($schema);
        }

        /** @var SubscriptionTypeUpgradeSchemasRepository $subscriptionTypeUpgradeSchemasRepository */
        $subscriptionTypeUpgradeSchemasRepository = $this->inject(SubscriptionTypeUpgradeSchemasRepository::class);
        $subscriptionTypeUpgradeSchemasRepository->add($baseSubscriptionType, $schemaRow);

        /** @var UpgradeOptionsRepository $upgradeOptionsRepository */
        $upgradeOptionsRepository = $this->inject(UpgradeOptionsRepository::class);
        $testedUpgradeTypes = [
            ShortUpgrade::TYPE,
            PaidExtendUpgrade::TYPE,
            FreeRecurrentUpgrade::TYPE,
            PaidRecurrentUpgrade::TYPE,
        ];

        foreach ($testedUpgradeTypes as $upgradeType) {
            $option = $upgradeOptionsRepository->findForSchema(
                $schemaRow->id,
                $upgradeType,
                [
                    'require_content' => $requireContent,
                    'omit_content' => $omitContent,
                ]
            );

            if (!$option) {
                $upgradeOptionsRepository->add(
                    $schemaRow,
                    $upgradeType,
                    [
                        'require_content' => $requireContent,
                        'omit_content' => $omitContent,
                    ]
                );
            }
        }
    }

    private function createAndConfirmPayment(
        $user,
        $subscriptionTypeCode,
        $startTime,
        $endTime,
        $gatewayCode,
        $cid,
        $rpState,
        $rpChargeAt
    ) {
        $gateway = $this->paymentGatewaysRepository->findByCode($gatewayCode);
        $subscriptionType = $this->subscriptionTypesRepository->findByCode($subscriptionTypeCode);

        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItems(
            SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType)
        );

        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $gateway,
            $user,
            $paymentItemContainer,
            null,
            null,
            $startTime,
            $endTime,
        );

        $this->paymentsRepository->update($payment, [
            'paid_at' => $startTime,
        ]);
        $payment = $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PAID);

        if ($gateway->is_recurrent) {
            $rp = $this->recurrentPaymentsRepository->createFromPayment($payment, $cid);
            $previousRp = $this->recurrentPaymentsRepository->getTable()
                ->where('payment_method.external_token = ?', $rp->cid)
                ->where('recurrent_payments.id < ?', $rp->id)
                ->order('recurrent_payments.created_at DESC')
                ->fetch();
            if ($previousRp) {
                $this->recurrentPaymentsRepository->update($previousRp, [
                    'payment_id' => $payment->id,
                    'state' => RecurrentPaymentsRepository::STATE_CHARGED,
                ]);
            }

            $data = [];
            if ($rpState) {
                $data['state'] = $rpState;
            }
            if ($rpChargeAt) {
                $data['charge_at'] = DateTime::from($rpChargeAt);
            }
            $this->recurrentPaymentsRepository->update($rp, $data);
        }
        return $payment;
    }
}
