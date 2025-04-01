<?php

namespace Crm\UpgradesModule\Tests;

use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\SubscriptionMovedHandler;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
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
use Crm\SubscriptionsModule\Events\SubscriptionEndsEvent;
use Crm\SubscriptionsModule\Events\SubscriptionMovedEvent;
use Crm\SubscriptionsModule\Events\SubscriptionShortenedEvent;
use Crm\SubscriptionsModule\Events\SubscriptionShortenedHandler;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Models\Extension\ExtendActualExtension;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\ContentAccessRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeContentAccessRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UpgradesModule\Events\PaymentStatusChangeHandler;
use Crm\UpgradesModule\Events\TrialSubscriptionEndsEventHandler;
use Crm\UpgradesModule\Models\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Models\Upgrade\ShortUpgrade;
use Crm\UpgradesModule\Models\Upgrade\SpecificUserSubscriptions;
use Crm\UpgradesModule\Models\Upgrade\TrialUpgrade;
use Crm\UpgradesModule\Models\Upgrade\UpgraderFactory;
use Crm\UpgradesModule\Repositories\SubscriptionTypeUpgradeSchemasRepository;
use Crm\UpgradesModule\Repositories\SubscriptionUpgradesRepository;
use Crm\UpgradesModule\Repositories\UpgradeOptionsRepository;
use Crm\UpgradesModule\Repositories\UpgradeSchemasRepository;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Models\Builder\UserBuilder;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use PHPUnit\Framework\Attributes\DataProvider;

class TrialUpgradeTest extends DatabaseTestCase
{
    private const GATEWAY_RECURRENT = TestRecurrentGateway::GATEWAY_CODE;
    private const GATEWAY_NON_RECURRENT = TestSingleGateway::GATEWAY_CODE;

    private const SUBSCRIPTION_TYPE_BASIC = 'st_basic';
    private const SUBSCRIPTION_TYPE_BASIC_LONG = 'st_basic_long';
    private const SUBSCRIPTION_TYPE_PREMIUM = 'st_premium';
    private const SUBSCRIPTION_TYPE_PREMIUM_LONG = 'st_premium_long';
    private const SUBSCRIPTION_TYPE_PREMIUM_TRIAL = 'st_trial';

    private const CONTENT_ACCESS_BASIC = 'web';
    private const CONTENT_ACCESS_PREMIUM = 'premium';

    private bool $initialized = false;
    private DateTime $now;

    private AvailableUpgraders $availableUpgraders;
    private UserManager $userManager;
    private SubscriptionsRepository $subscriptionsRepository;
    private SubscriptionTypesRepository $subscriptionTypesRepository;
    private PaymentsRepository $paymentsRepository;
    private PaymentGatewaysRepository $paymentGatewaysRepository;
    private RecurrentPaymentsRepository $recurrentPaymentsRepository;
    private SpecificUserSubscriptions $upgradeableSubscriptions;
    private SubscriptionMetaRepository $subscriptionMetaRepository;

    public function setUp(): void
    {
        parent::setUp();

        $this->availableUpgraders = $this->inject(AvailableUpgraders::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);
        $this->upgradeableSubscriptions = $this->inject(SpecificUserSubscriptions::class);
        $this->subscriptionMetaRepository = $this->inject(SubscriptionMetaRepository::class);

        /** @var LazyEventEmitter $lazyEventEmitter */
        $lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        /** @var PaymentStatusChangeHandler $upgradeStatusChangeHandler */
        $upgradeStatusChangeHandler = $this->inject(PaymentStatusChangeHandler::class);
        /** @var UpgraderFactory $upgraderFactory */
        $upgraderFactory = $this->inject(UpgraderFactory::class);

        $shortUpgrade = $upgraderFactory->getUpgraders()[ShortUpgrade::TYPE];
        $upgraderFactory->setSubsequentUpgrader($shortUpgrade);

        $this->now = DateTime::from('2021-04-05');
        $this->setNow($this->now);

        // initialize subscription types
        $basic = $this->getSubscriptionType(
            code: self::SUBSCRIPTION_TYPE_BASIC,
            contentAccess: [self::CONTENT_ACCESS_BASIC],
            length: 31,
            price: 5,
        );
        $basicLong = $this->getSubscriptionType(
            code: self::SUBSCRIPTION_TYPE_BASIC_LONG,
            contentAccess: [self::CONTENT_ACCESS_BASIC],
            length: 365,
            price: 50,
        );
        $premium = $this->getSubscriptionType(
            code: self::SUBSCRIPTION_TYPE_PREMIUM,
            contentAccess: [self::CONTENT_ACCESS_BASIC, self::CONTENT_ACCESS_PREMIUM],
            length: 31,
            price: 10,
        );
        $premiumLong = $this->getSubscriptionType(
            code: self::SUBSCRIPTION_TYPE_PREMIUM_LONG,
            contentAccess: [self::CONTENT_ACCESS_BASIC, self::CONTENT_ACCESS_PREMIUM],
            length: 365,
            price: 100,
        );
        $trial = $this->getSubscriptionType(
            code: self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL,
            contentAccess: [self::CONTENT_ACCESS_BASIC, self::CONTENT_ACCESS_PREMIUM],
            length: 90,
            price: 1,
        );

        // configure upgrades
        $this->configureUpgradeOption(
            schema: 'default',
            baseSubscriptionType: $basic,
            requireContent: [self::CONTENT_ACCESS_PREMIUM],
        );
        $this->configureUpgradeOption(
            schema: 'default',
            baseSubscriptionType: $basicLong,
            requireContent: [self::CONTENT_ACCESS_PREMIUM],
        );

        if (!$this->initialized) {
            // clear initialized handlers (we do not want duplicated listeners)
            $lazyEventEmitter->removeAllListeners(PaymentChangeStatusEvent::class);
            $lazyEventEmitter->removeAllListeners(SubscriptionShortenedEvent::class);
            $lazyEventEmitter->removeAllListeners(SubscriptionMovedEvent::class);
            $lazyEventEmitter->removeAllListeners(SubscriptionEndsEvent::class);

            // bind necessary event handlers
            $lazyEventEmitter->addListener(
                PaymentChangeStatusEvent::class,
                $upgradeStatusChangeHandler,
                1000 // we need to have this executed before \Crm\PaymentsModule\Events\PaymentStatusChangeHandler
            );
            $lazyEventEmitter->addListener(
                PaymentChangeStatusEvent::class,
                \Crm\PaymentsModule\Events\PaymentStatusChangeHandler::class,
                500
            );
            $lazyEventEmitter->addListener(
                SubscriptionShortenedEvent::class,
                SubscriptionShortenedHandler::class,
            );
            $lazyEventEmitter->addListener(
                SubscriptionMovedEvent::class,
                SubscriptionMovedHandler::class,
            );
            $lazyEventEmitter->addListener(
                SubscriptionEndsEvent::class,
                TrialSubscriptionEndsEventHandler::class,
                LazyEventEmitter::P_LOW
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

    public static function trialConfigDataProvider()
    {
        return [
            // By default, we populate upgrade option config in setup() with valid set of required options.
            'NoConfigChanges_NotUsedBefore_ShouldBeUsable' => [
                'configChanges' => [],
                'shouldBeUsable' => true,
            ],
            'MissingTrialSubscriptionType_ShouldNotBeUsable' => [
                'configChanges' => [
                    TrialUpgrade::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE => null,
                ],
                'shouldBeUsable' => false,
            ],
            'IncorrectTrialSubscriptionType_ShouldNotBeUsable' => [
                'configChanges' => [
                    TrialUpgrade::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE => 'dummy',
                ],
                'shouldBeUsable' => false,
            ],
        ];
    }

    public static function trialStartDataProvider()
    {
        // now: 2021-04-05
        // trial length: 90 days

        return [
            'TrialStart_EndingLate_ShouldUseFullTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-02-04', 'end' => '2022-02-04'],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-02-04', 'end' => '2022-02-04'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL, 'start' => '2021-04-05', 'end' => '2021-07-04', 'accepted_at' => '2021-04-05', 'latest_end_time' => '2021-07-04'],
                ],
            ],
            'TrialStart_AlreadyPremium_ShouldNotUseTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_LONG, 'start' => '2021-02-04', 'end' => '2022-02-04'],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_LONG, 'start' => '2021-02-04', 'end' => '2022-02-04'],
                ],
            ],
            'TrialStart_EndingSoon_ShouldUsePartialTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-05-05', 'end' => '2021-05-05'],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-05-05', 'end' => '2021-05-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL, 'start' => '2021-04-05', 'end' => '2021-05-05', 'accepted_at' => '2021-04-05', 'latest_end_time' => '2021-07-04'],
                ],
            ],
            'TrialStart_AlreadyEnded_ShouldNotUseTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-03-05', 'end' => '2021-03-05'],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-03-05', 'end' => '2021-03-05'],
                ],
            ],
            'TrialStart_AlreadyRenewed_ShouldUseExtendedTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-05-21'],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-05-21'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL, 'start' => '2021-04-05', 'end' => '2021-05-21', 'accepted_at' => '2021-04-05', 'latest_end_time' => '2021-07-04'],
                ],
            ],
            'TrialStart_AlreadyRenewedLong_ShouldUseFullTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-05-05', 'end' => '2021-05-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-05-05', 'end' => '2022-05-05'],
                ],
                'subscriptionResult' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-05-05', 'end' => '2021-05-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-05-05', 'end' => '2022-05-05'],
                    ['type' => self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL, 'start' => '2021-04-05', 'end' => '2021-07-04', 'accepted_at' => '2021-04-05', 'latest_end_time' => '2021-07-04'],
                ],
            ],
        ];
    }

    public static function trialRenewalDataProvider()
    {
        // now: 2021-04-05
        // trial length: 90 days

        return [
            'TrialRenewal_EndingLate_NothingShouldHappen' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-02-04', 'end' => '2022-02-04'],
                ],
                'renewalPayment' => ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2022-02-04', 'end' => '2023-02-04'],
                'expectedTrialEndTime' => '2021-07-04',
            ],
            'TrialRenewal_EndingSoon_ShouldFullyRenewTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-05-05', 'end' => '2021-05-05'],
                ],
                'renewalPayment' => ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-05-05', 'end' => '2022-05-05'],
                'expectedTrialEndTime' => '2021-07-04',
            ],
            'TrialRenewal_EndingSoon_ShouldPartiallyRenewTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-05-21'],
                ],
                'renewalPayment' => ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-05-21', 'end' => '2021-06-21'],
                'expectedTrialEndTime' => '2021-06-21',
            ],
            'TrialRenewal_NotRenewed_ShouldNotRenewTrial' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-20'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC, 'start' => '2021-04-20', 'end' => '2021-05-21'],
                ],
                'renewalPayment' => null,
                'expectedTrialEndTime' => '2021-05-21',
            ],
        ];
    }

    public static function trialFinalizeDataProvider()
    {
        // trial start: 2021-04-05
        // trial length: 90 days
        // upgrade finalization happens at the end of trial period (2021-07-04)

        return [
            'TrialFinalize_SingleBaseSubscription' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-02-04', 'end' => '2022-02-04'],
                ],
                'expectedSubscriptions' => [
                    // original subscription, stopped at the time of upgrade finalization
                    [
                        'start_time' => '2021-02-04',
                        'end_time' => '2021-07-04',
                        'type' => 'regular',
                    ],
                    // trial subscription
                    [
                        'start_time' => '2021-04-05',
                        'end_time' => '2021-07-04',
                        'type' => 'upgrade',
                    ],
                    // upgraded subscription, shortened
                    [
                        'start_time' => '2021-07-04',
                        'end_time' => '2021-10-19 12:30:00',
                        'type' => 'upgrade',
                    ]
                ],
                'expectedRecurrent' => null,
            ],
            'TrialFinalize_RenewedManualBaseSubscriptionBeforeTrialStart' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-08-08', 'end' => '2021-08-08'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-08-08', 'end' => '2022-08-08'],
                ],
                'expectedSubscriptions' => [
                    // original subscription 1, stopped at the time of upgrade finalization
                    [
                        'start_time' => '2020-08-08',
                        'end_time' => '2021-07-04',
                        'type' => 'regular',
                    ],
                    // original subscription 2, start was moved to the end of shortened subscription 1
                    [
                        'start_time' => '2021-07-21 12:00:00',
                        'end_time' => '2021-07-21 12:00:00',
                        'type' => 'regular',
                    ],
                    // trial subscription
                    [
                        'start_time' => '2021-04-05',
                        'end_time' => '2021-07-04',
                        'type' => 'upgrade',
                    ],
                    // upgraded subscription 1, upgraded-shortened
                    [
                        'start_time' => '2021-07-04',
                        'end_time' => '2021-07-21 12:00:00',
                        'type' => 'upgrade',
                    ],
                    // upgraded subscription 2, upgraded-shortened
                    [
                        'start_time' => '2021-07-21 12:00:00',
                        'end_time' => '2022-01-19 23:00:00',
                        'type' => 'upgrade',
                    ],
                ],
                'expectedRecurrent' => null,
            ],
            'TrialFinalize_RenewedManualBaseSubscriptionAfterTrialStart' => [
                'payments' => [
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2020-08-08', 'end' => '2021-08-08'],
                    ['type' => self::SUBSCRIPTION_TYPE_BASIC_LONG, 'start' => '2021-08-08', 'end' => '2022-08-08', 'mode' => 'after_trial'],
                ],
                'expectedSubscriptions' => [
                    // original subscription 1, stopped at the time of upgrade finalization
                    [
                        'start_time' => '2020-08-08',
                        'end_time' => '2021-07-04',
                        'type' => 'regular',
                    ],
                    // trial subscription
                    [
                        'start_time' => '2021-04-05',
                        'end_time' => '2021-07-04',
                        'type' => 'upgrade',
                    ],
                    // original subscription 2, start was moved to the end of upgraded subscription 1
                    [
                        'start_time' => '2021-07-21 12:00:00',
                        'end_time' => '2021-07-21 12:00:00',
                        'type' => 'regular',
                    ],
                    // upgraded subscription 1, upgraded-shortened
                    [
                        'start_time' => '2021-07-04',
                        'end_time' => '2021-07-21 12:00:00',
                        'type' => 'upgrade',
                    ],
                    // upgraded subscription 2, upgraded-shortened
                    [
                        'start_time' => '2021-07-21 12:00:00',
                        'end_time' => '2022-01-19 23:00:00',
                        'type' => 'upgrade',
                    ],
                ],
                'expectedRecurrent' => null,
            ],
            'TrialFinalize_RenewedRecurrentBaseSubscription_ShouldMoveChargeAt' => [
                'payments' => [
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC_LONG,
                        'start' => '2020-08-08',
                        'end' => '2021-08-08',
                        'gateway' => self::GATEWAY_RECURRENT,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::SUBSCRIPTION_TYPE_BASIC_LONG,
                        'start' => '2021-08-08',
                        'end' => '2022-08-08',
                        'gateway' => self::GATEWAY_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2022-08-06', // 2 days before
                        'mode' => 'after_trial',
                    ],
                ],
                'expectedSubscriptions' => [
                    // original subscription 1, stopped at the time of upgrade finalization
                    [
                        'start_time' => '2020-08-08',
                        'end_time' => '2021-07-04',
                        'type' => 'regular',
                    ],
                    // trial subscription
                    [
                        'start_time' => '2021-04-05',
                        'end_time' => '2021-07-04',
                        'type' => 'upgrade',
                    ],
                    // original subscription 2, start was moved to the end of upgraded subscription 1
                    [
                        'start_time' => '2021-07-21 12:00:00',
                        'end_time' => '2021-07-21 12:00:00',
                        'type' => 'regular',
                    ],
                    // upgraded subscription 1, upgraded-shortened
                    [
                        'start_time' => '2021-07-04',
                        'end_time' => '2021-07-21 12:00:00',
                        'type' => 'upgrade',
                    ],
                    // upgraded subscription 2, upgraded-shortened
                    [
                        'start_time' => '2021-07-21 12:00:00',
                        'end_time' => '2022-01-19 23:00:00',
                        'type' => 'upgrade',
                    ],
                ],
                'expectedRecurrent' => [
                    'cid' => '1111',
                    'rp_charge_at' => '2022-01-17 23:00:00',
                ],
            ],
        ];
    }

    #[DataProvider('trialConfigDataProvider')]
    public function testTrialUpgradeConfiguration(array $configChanges, bool $shouldBeUsable)
    {
        $user = $this->getUser('user@example.com');

        $p = $this->createAndConfirmPayment(
            user: $user,
            subscriptionTypeCode: self::SUBSCRIPTION_TYPE_BASIC,
            startTime: DateTime::from('now'),
            endTime: DateTime::from('+365 days'),
            gatewayCode: self::GATEWAY_NON_RECURRENT,
        );

        // set currently created subscription as upgradeable if possible
        $this->upgradeableSubscriptions->setSpecificSubscriptions($user->id, $p->subscription);

        /** @var UpgradeOptionsRepository $upgradeOptionsRepository */
        $upgradeOptionsRepository = $this->getRepository(UpgradeOptionsRepository::class);
        foreach ($upgradeOptionsRepository->getTable() as $upgradeOption) {
            $json = Json::decode($upgradeOption->config, forceArrays: true);
            $json = array_merge($json, $configChanges);
            $upgradeOptionsRepository->update($upgradeOption, [
                'config' => Json::encode($json),
            ]);
        }

        // tell upgrader component which subscriptions should be considered upgradeable
        $this->availableUpgraders->setUpgradeableSubscriptions($this->upgradeableSubscriptions);
        $upgraders = $this->availableUpgraders->all($user->id);

        $this->assertEquals($shouldBeUsable, count($upgraders) > 0);
    }

    #[DataProvider('trialStartDataProvider')]
    public function testTrialStart($payments, $subscriptionResult)
    {
        $user = $this->getUser('user@example.com');

        foreach ($payments as $payment) {
            $p = $this->createAndConfirmPayment(
                $user,
                $payment['type'],
                DateTime::from($payment['start']),
                DateTime::from($payment['end']),
                self::GATEWAY_NON_RECURRENT,
            );

            if ($p->subscription->start_time < $this->now && $p->subscription->end_time > $this->now) {
                $this->upgradeableSubscriptions->setSpecificSubscriptions($user->id, $p->subscription);
            }
        }

        // tell upgrader component which subscriptions should be considered upgradeable
        $this->availableUpgraders->setUpgradeableSubscriptions($this->upgradeableSubscriptions);

        $upgraders = $this->availableUpgraders->all($user->id);

        if (count($upgraders) > 0) {
            $this->assertCount(1, $upgraders);
            $upgrader = $upgraders[0];
            $result = $upgrader->upgrade();
        }

        $subscriptions = $this->subscriptionsRepository->getTable()
            ->where('user_id = ?', $user->id)
            ->order('created_at ASC')
            ->fetchAll();
        $subscriptions = array_values($subscriptions); // remove ID-based keys

        $this->assertCount(count($subscriptionResult), $subscriptions);

        foreach ($subscriptionResult as $i => $expectedSubscription) {
            $this->assertEquals(
                $expectedSubscription['type'],
                $subscriptions[$i]->subscription_type->code,
                'The ' . ($i+1) . '. subscription doesn\'t match the expected subscription type.',
            );
            $this->assertEquals(DateTime::from($expectedSubscription['start']), $subscriptions[$i]->start_time);
            $this->assertEquals(DateTime::from($expectedSubscription['end']), $subscriptions[$i]->end_time);

            if ($subscriptions[$i]->subscription_type->code === self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL) {
                $trialAccepted = $this->subscriptionMetaRepository->getMeta(
                    subscription: $subscriptions[$i],
                    key: TrialUpgrade::SUBSCRIPTION_META_TRIAL_ACCEPTED
                )->fetch();
                $this->assertEquals(DateTime::from($expectedSubscription['accepted_at']), DateTime::from($trialAccepted->value));

                $trialLatestEndTime = $this->subscriptionMetaRepository->getMeta(
                    subscription: $subscriptions[$i],
                    key: TrialUpgrade::SUBSCRIPTION_META_TRIAL_LATEST_END_TIME
                )->fetch();
                $this->assertEquals(DateTime::from($expectedSubscription['latest_end_time']), DateTime::from($trialLatestEndTime->value));
            }
        }
    }

    #[DataProvider('trialRenewalDataProvider')]
    public function testTrialRenewal($payments, $renewalPayment, $expectedTrialEndTime)
    {
        $user = $this->initUpgradedState($payments);

        // trigger renewal
        if (isset($renewalPayment)) {
            $this->createAndConfirmPayment(
                $user,
                $renewalPayment['type'],
                DateTime::from($renewalPayment['start']),
                DateTime::from($renewalPayment['end']),
                self::GATEWAY_NON_RECURRENT,
            );
        }

        $trialSubscription = $this->subscriptionsRepository->getTable()
            ->where('user_id = ?', $user->id)
            ->where('subscription_type.code = ?', self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL)
            ->fetch();

        // move forward in time to the point where trial subscription ends to trigger refresh and possible extension
        $this->setNow($trialSubscription->end_time);

        // this happens within subscriptions:change_status, should trigger Crm\SubscriptionsModule\Events\SubscriptionEndsEvent
        $this->subscriptionsRepository->refreshInternalStatus($trialSubscription);
        $trialSubscription = $this->subscriptionsRepository->find($trialSubscription->id); // manual refresh

        $this->assertEquals(DateTime::from($expectedTrialEndTime), $trialSubscription->end_time);
    }

    #[DataProvider('trialFinalizeDataProvider')]
    public function testTrialFinalize($payments, array $expectedSubscriptions, ?array $expectedRecurrent)
    {
        $user = $this->initUpgradedState($payments);

        $trialSubscription = $this->subscriptionsRepository->getTable()
            ->where('user_id = ?', $user->id)
            ->where('subscription_type.code = ?', self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL)
            ->fetch();

        // move forward in time to the point where trial subscription ends to trigger refresh and possible extension
        $this->setNow($trialSubscription->end_time);

        // this happens within subscriptions:change_status, should trigger Crm\SubscriptionsModule\Events\SubscriptionEndsEvent
        $this->subscriptionsRepository->refreshInternalStatus($trialSubscription);

        $subscriptions = array_values($this->subscriptionsRepository->getTable()
            ->where('user_id = ?', $user->id)
            ->order('id ASC')
            ->fetchAll());

        foreach ($subscriptions as $i => $subscription) {
            $context = "Context: \$expectedSubscriptions[$i]: " . Json::encode($expectedSubscriptions[$i]);
            $this->assertEquals(
                DateTime::from($expectedSubscriptions[$i]['start_time']),
                $subscription->start_time,
                $context,
            );
            $this->assertEquals(
                DateTime::from($expectedSubscriptions[$i]['end_time']),
                $subscription->end_time,
                $context,
            );
            $this->assertEquals($expectedSubscriptions[$i]['type'], $subscription->type, $context);
        }

        if ($expectedRecurrent) {
            $recurrentPayment = $this->recurrentPaymentsRepository->getTable()
                ->where('user_id = ?', $user->id)
                ->where('state = ?', RecurrentPaymentStateEnum::Active->value)
                ->order('id ASC')
                ->fetch();

            $this->assertEquals($expectedRecurrent['cid'], $recurrentPayment->cid);
            $this->assertEquals(DateTime::from($expectedRecurrent['rp_charge_at']), $recurrentPayment->charge_at);
        }
    }

    private function getUser($email): ActiveRow
    {
        $user = $this->userManager->loadUserByEmail($email);
        if (!$user) {
            $userBuilder = $this->inject(UserBuilder::class);
            $user = $userBuilder->createNew()
                ->setEmail($email)
                ->setPassword('secret', false)
                ->setPublicName($email)
                ->save();
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
                ->setDefault(!str_contains($code, 'trial'))
                ->setContentAccessOption(...$contentAccess)
                ->save();
        }

        return $subscriptionType;
    }

    private function configureUpgradeOption(
        string $schema,
        ActiveRow $baseSubscriptionType,
        array $requireContent = [],
        array $omitContent = [],
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

        $option = $upgradeOptionsRepository->findForSchema(
            upgradeSchemaId: $schemaRow->id,
            type: TrialUpgrade::TYPE,
            config: [
                'require_content' => $requireContent,
                'omit_content' => $omitContent,
                TrialUpgrade::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE => self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL,
            ],
        );

        if (!$option) {
            $upgradeOptionsRepository->add(
                upgradeSchema: $schemaRow,
                type: TrialUpgrade::TYPE,
                config: [
                    'require_content' => $requireContent,
                    'omit_content' => $omitContent,
                    TrialUpgrade::UPGRADE_OPTION_CONFIG_SUBSCRIPTION_TYPE_CODE => self::SUBSCRIPTION_TYPE_PREMIUM_TRIAL,
                ],
            );
        }
    }

    private function setNow(DateTime $now)
    {
        $this->inject(AvailableUpgraders::class)->setNow($now);
        $this->inject(SubscriptionsRepository::class)->setNow($now);
        $this->inject(RecurrentPaymentsRepository::class)->setNow($now);
        $this->inject(ExtendActualExtension::class)->setNow($now);
        $this->inject(PaymentStatusChangeHandler::class)->setNow($now);
        $this->inject(UpgraderFactory::class)->setNow($now);
        $this->inject(TrialSubscriptionEndsEventHandler::class)->setNow($now);
    }

    private function initUpgradedState($payments)
    {
        // prepare initial state
        $user = $this->getUser('user@example.com');

        foreach ($payments as $paymentDef) {
            if ($paymentDef['mode'] ?? null === 'after_trial') {
                continue;
            }
            $this->createPaymentFromDefinition($user, $paymentDef);
        }

        // upgrade
        $this->availableUpgraders->setUpgradeableSubscriptions($this->upgradeableSubscriptions);
        $upgraders = $this->availableUpgraders->all($user->id);
        $this->assertCount(1, $upgraders);

        $upgrader = $upgraders[0];
        $result = $upgrader->upgrade();

        foreach ($payments as $payment) {
            if (($payment['mode'] ?? null) !== 'after_trial') {
                continue;
            }
            $this->createPaymentFromDefinition($user, $payment);
        }

        return $user;
    }

    private function createPaymentFromDefinition(ActiveRow $user, array $paymentDef)
    {
        $payment = $this->createAndConfirmPayment(
            user: $user,
            subscriptionTypeCode: $paymentDef['type'],
            startTime: DateTime::from($paymentDef['start']),
            endTime: DateTime::from($paymentDef['end']),
            gatewayCode: $paymentDef['gateway'] ?? self::GATEWAY_NON_RECURRENT,
            cid: $paymentDef['cid'] ?? null,
            rpChargeAt: $paymentDef['rp_charge_at'] ?? null,
        );

        if ($payment->subscription->start_time < $this->now && $payment->subscription->end_time > $this->now) {
            $this->upgradeableSubscriptions->setSpecificSubscriptions($user->id, $payment->subscription);
        }
    }

    private function createAndConfirmPayment(
        $user,
        $subscriptionTypeCode,
        $startTime,
        $endTime,
        $gatewayCode,
        $cid = null,
        $rpState = null,
        $rpChargeAt = null,
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
        $payment = $this->paymentsRepository->updateStatus($payment, PaymentStatusEnum::Paid->value);

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
                    'state' => RecurrentPaymentStateEnum::Charged->value,
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
