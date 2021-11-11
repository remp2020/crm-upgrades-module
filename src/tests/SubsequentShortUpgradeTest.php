<?php


namespace Crm\UpgradesModule\Tests;

use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\GatewayFactory;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\RecurrentPaymentsResolver;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repository\PaymentItemsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Seeders\PaymentGatewaysSeeder;
use Crm\PaymentsModule\Tests\Gateways\TestRecurrentGateway;
use Crm\PaymentsModule\UnknownPaymentMethodCode;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Extension\ExtendActualExtension;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\ContentAccessRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UpgradesModule\Events\PaymentStatusChangeHandler;
use Crm\UpgradesModule\Repository\SubscriptionTypeUpgradeSchemasRepository;
use Crm\UpgradesModule\Repository\SubscriptionUpgradesRepository;
use Crm\UpgradesModule\Repository\UpgradeOptionsRepository;
use Crm\UpgradesModule\Repository\UpgradeSchemasRepository;
use Crm\UpgradesModule\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Upgrade\FreeRecurrentUpgrade;
use Crm\UpgradesModule\Upgrade\PaidExtendUpgrade;
use Crm\UpgradesModule\Upgrade\PaidRecurrentUpgrade;
use Crm\UpgradesModule\Upgrade\ShortUpgrade;
use Crm\UpgradesModule\Upgrade\SpecificUserSubscriptions;
use Crm\UpgradesModule\Upgrade\UpgraderFactory;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\UserMetaRepository;
use Crm\UsersModule\Repository\UsersRepository;
use League\Event\Emitter;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class SubsequentShortUpgradeTest extends DatabaseTestCase
{
    private const GW_RECURRENT = 'recurrent';
    private const GW_NON_RECURRENT = 'non_recurrent';

    private const ST_BASIC = 'basic';
    private const ST_PREMIUM = 'premium';

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

        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        /** @var GatewayFactory $gatewayFactory */
        $gatewayFactory = $this->inject(GatewayFactory::class);
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

        // initialize gateways
        try {
            $gatewayFactory->getGateway(self::GW_RECURRENT);
        } catch (UnknownPaymentMethodCode $e) {
            $gatewayFactory->registerGateway(self::GW_RECURRENT, TestRecurrentGateway::class);
        }
        try {
            $gatewayFactory->registerGateway(self::GW_NON_RECURRENT);
        } catch (UnknownPaymentMethodCode $e) {
            $gatewayFactory->registerGateway(self::GW_NON_RECURRENT);
        }

        $this->paymentGatewaysRepository->add(self::GW_RECURRENT, self::GW_RECURRENT, 10, true, true);
        $this->paymentGatewaysRepository->add(self::GW_NON_RECURRENT, self::GW_NON_RECURRENT, 10, true, false);

        // initialize subscription types
        $basic = $this->getSubscriptionType(self::ST_BASIC, 5);
        $premium = $this->getSubscriptionType(self::ST_PREMIUM, 10);

        $this->configureUpgradeOption('default', $basic, $premium);

        // bind necessary event handlers
        if (!$emitter->hasListeners(\Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class)) {
            $emitter->addListener(
                \Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class,
                $upgradeStatusChangeHandler,
                1000 // we need to have this executed before \Crm\PaymentsModule\Events\PaymentStatusChangeHandler
            );
            $emitter->addListener(
                \Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class,
                $this->inject(\Crm\PaymentsModule\Events\PaymentStatusChangeHandler::class),
                500
            );
        }
        if (!$emitter->hasListeners(\Crm\SubscriptionsModule\Events\SubscriptionShortenedEvent::class)) {
            $emitter->addListener(
                \Crm\SubscriptionsModule\Events\SubscriptionShortenedEvent::class,
                $this->inject(\Crm\SubscriptionsModule\Events\SubscriptionShortenedHandler::class),
            );
            $emitter->addListener(
                \Crm\SubscriptionsModule\Events\SubscriptionMovedEvent::class,
                $this->inject(\Crm\PaymentsModule\Events\SubscriptionMovedHandler::class),
            );
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

        parent::tearDown();
    }

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
            UserMetaRepository::class,
            SubscriptionsRepository::class,
            PaymentsRepository::class,
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
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            PaymentGatewaysSeeder::class,
            SubscriptionTypeNamesSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
        ];
    }

    public function shortUpgradeDataProvider()
    {
        return [
            'Short_NoneFollowing_ShouldShortenOne' => [
                'upgrade_type' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                ],
            ],
            'Short_OneFollowingSubscription_ShouldShortenBoth' => [
                'upgrade_type' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                ],
            ],
            'Short_TwoFollowingSubscription_ShouldShortenAll' => [
                'upgrade_type' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-06-05',
                        'end' => '2021-07-06',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::ST_BASIC, 'start' => '2021-05-05 12:00:00', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-05-05 12:00:00', 'end' => '2021-05-21'],
                ],
            ],
            'Short_OneFollowingOneOverlapping_ShouldShortenOnlyFollowing' => [
                'upgrade_type' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-04 12:00:00',
                        'end' => '2021-05-05',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-04 12:00:00', 'end' => '2021-05-05'], // untouched
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                ],
            ],
            'Short_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndMoveSecond' => [
                'upgrade_type' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_PREMIUM,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-21'], // only moved
                ],
            ],
            'Short_BaseHasStoppedRecurrent_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndMoveSecond' => [
                'upgrade_type' => ShortUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-04',
                        'end' => '2021-05-05',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                        'rp_state' => RecurrentPaymentsRepository::STATE_USER_STOP,
                    ],
                    [
                        'type' => self::ST_PREMIUM,
                        'start' => '2021-05-05',
                        'end' => '2021-06-05',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '2222',
                        'rp_charge_at' => '2021-06-03',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-04-04', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-21'], // only moved
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'state' => RecurrentPaymentsRepository::STATE_SYSTEM_STOP],
                    ['cid' => '2222', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-05-19'], // keep "2 days before"
                ],
            ],
        ];
    }

    public function freeRecurrentUpgradeDataProvider()
    {
        return [
            'FreeRecurrent_NoneFollowing_ShouldUpgradeOne' => [
                'upgrade_type' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-04-05',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-04-05'],
                ],
            ],
            'FreeRecurrent_OneFollowingSubscription_ShouldShortenSecond' => [
                'upgrade_type' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-07',
                        'end' => '2021-05-08',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-06',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-07', 'end' => '2021-04-07'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-07', 'end' => '2021-04-22 12:00:00'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-04-20 12:00:00'],
                ],
            ],
            'FreeRecurrent_TwoFollowingSubscriptions_ShouldShortenSecondAndThird' => [
                'upgrade_type' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-07',
                        'end' => '2021-05-08',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-05-08',
                        'end' => '2021-06-08',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-06-06',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-07', 'end' => '2021-04-07'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-07', 'end' => '2021-04-22 12:00:00'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-22 12:00:00', 'end' => '2021-04-22 12:00:00'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-22 12:00:00', 'end' => '2021-05-08'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-05-06'],
                ],
            ],
            'FreeRecurrent_OneFollowingOneOverlapping_ShouldShortenOnlyFollowing' => [
                'upgrade_type' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-07',
                        'end' => '2021-05-08',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-06',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-06',
                        'end' => '2022-04-06',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '2222',
                        'rp_charge_at' => '2022-04-04',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-07', 'end' => '2021-04-07'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-07', 'end' => '2021-04-22 12:00:00'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-06', 'end' => '2022-04-06'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-04-20 12:00:00'],
                    ['cid' => '2222', 'type' => self::ST_BASIC, 'charge_at' => '2022-04-04'],
                ],
            ],
            'FreeRecurrent_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndKeepSecondUntouched' => [
                'upgrade_type' => FreeRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-07',
                        'end' => '2021-04-07',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_PREMIUM,
                        'start' => '2021-04-07',
                        'end' => '2021-05-08',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-06',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-07', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-07'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-07', 'end' => '2021-05-08'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-05-06'],
                ],
            ],
        ];
    }

    public function paidRecurrentUpgradeDataProvider()
    {
        return [
            'PaidRecurrent_NoneFollowing_ShouldUpgradeOne' => [
                'upgrade_type' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-04-18',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-04-18'],
                ],
            ],
            'PaidRecurrent_OneFollowingSubscription_ShouldShortenSecond' => [
                'upgrade_type' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-19',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-05-03 12:00:00'],
                ],
            ],
            'PaidRecurrent_TwoFollowingSubscriptions_ShouldShortenSecondAndThird' => [
                'upgrade_type' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-05-21',
                        'end' => '2021-06-21',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-06-19',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::ST_BASIC, 'start' => '2021-05-05 12:00:00', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-05-05 12:00:00', 'end' => '2021-05-21'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-05-19'],
                ],
            ],
            'PaidRecurrent_OneFollowingOneOverlapping_ShouldShortenOnlyFollowing' => [
                'upgrade_type' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-19',
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-05-01',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '2222',
                        'rp_charge_at' => '2021-05-19',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_BASIC, 'start' => '2021-04-20', 'end' => '2021-04-20'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-05 12:00:00'],
                    ['type' => self::ST_BASIC, 'start' => '2021-05-01', 'end' => '2021-05-21'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-05-03 12:00:00'],
                    ['cid' => '2222', 'type' => self::ST_BASIC, 'charge_at' => '2021-05-19'],
                ],
            ],
            'PaidRecurrent_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndKeepSecondUntouched' => [
                'upgrade_type' => PaidRecurrentUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_RECURRENT,
                        'upgradeable' => true,
                        'cid' => '1111',
                    ],
                    [
                        'type' => self::ST_PREMIUM,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'rp_charge_at' => '2021-05-19',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-04-20'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-20', 'end' => '2021-05-21'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-05-19'],
                ],
            ],
        ];
    }

    public function paidExtendUpgradeDataProvider()
    {
        return [
            'PaidExtend_NoneFollowing_ShouldUpgradeOne' => [
                'upgrade_type' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                ],
            ],
            'PaidExtend_OneFollowingSubscription_ShouldShortenSecond' => [
                'upgrade_type' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::ST_BASIC, 'start' => '2021-05-06', 'end' => '2021-05-06'], // start was moved before shortened
                    ['type' => self::ST_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-05-21 12:00:00'],
                ],
            ],
            'PaidExtend_TwoFollowingSubscriptions_ShouldShortenSecondAndThird' => [
                'upgrade_type' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-05-21',
                        'end' => '2021-06-21',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::ST_BASIC, 'start' => '2021-05-06', 'end' => '2021-05-06'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-05-21 12:00:00'],
                    ['type' => self::ST_BASIC, 'start' => '2021-05-21 12:00:00', 'end' => '2021-05-21 12:00:00'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-05-21 12:00:00', 'end' => '2021-06-06'],
                ],
            ],
            'PaidExtend_OneFollowingOneOverlapping_ShouldShortenOnlyFollowing' => [
                'upgrade_type' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-05-01',
                        'end' => '2021-06-01',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::ST_BASIC, 'start' => '2021-05-06', 'end' => '2021-05-06'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-05-21 12:00:00'],
                    ['type' => self::ST_BASIC, 'start' => '2021-05-01', 'end' => '2021-06-01'],
                ],
            ],
            'PaidExtend_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndKeepSecondUntouched' => [
                'upgrade_type' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_NON_RECURRENT,
                        'upgradeable' => true,
                    ],
                    [
                        'type' => self::ST_PREMIUM,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_NON_RECURRENT,
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-06-06'],
                ],
            ],
            'PaidExtend_BaseHasStoppedRecurrent_OneFollowingAlreadyUpgraded_ShouldShortenFirstAndKeepSecondUntouched' => [
                'upgrade_type' => PaidExtendUpgrade::TYPE,
                'payments' => [
                    [
                        'type' => self::ST_BASIC,
                        'start' => '2021-03-20',
                        'end' => '2021-04-20',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '1111',
                        'upgradeable' => true,
                        'rp_state' => RecurrentPaymentsRepository::STATE_USER_STOP,
                    ],
                    [
                        'type' => self::ST_PREMIUM,
                        'start' => '2021-04-20',
                        'end' => '2021-05-21',
                        'gateway' => self::GW_RECURRENT,
                        'cid' => '2222',
                        'rp_charge_at' => '2021-05-19',
                    ],
                ],
                'subscription_result' => [
                    ['type' => self::ST_BASIC, 'start' => '2021-03-20', 'end' => '2021-04-05'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-04-05', 'end' => '2021-05-06'],
                    ['type' => self::ST_PREMIUM, 'start' => '2021-05-06', 'end' => '2021-06-06'],
                ],
                'recurrent_result' => [
                    ['cid' => '1111', 'state' => RecurrentPaymentsRepository::STATE_SYSTEM_STOP],
                    ['cid' => '2222', 'type' => self::ST_PREMIUM, 'charge_at' => '2021-06-04'], // keep "2 days before"
                ],
            ],
        ];
    }

    /**
     * @dataProvider paidExtendUpgradeDataProvider
     * @dataProvider shortUpgradeDataProvider
     * @dataProvider paidRecurrentUpgradeDataProvider
     * @dataProvider freeRecurrentUpgradeDataProvider
     */
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
            $upgrader->setGateway($this->paymentGatewaysRepository->findByCode(self::GW_NON_RECURRENT));
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
            $this->assertEquals($expectedSubscription['type'], $subscriptions[$i]->subscription_type->code);
            $this->assertEquals(DateTime::from($expectedSubscription['start']), $subscriptions[$i]->start_time);
            $this->assertEquals(DateTime::from($expectedSubscription['end']), $subscriptions[$i]->end_time);
        }

        foreach ($recurrentResult as $expectedRecurrent) {
            $rp = $this->recurrentPaymentsRepository->userRecurrentPayments($user->id)
                ->where(['cid' => $expectedRecurrent['cid']])
                ->order('charge_at DESC')
                ->fetch();

            if (isset($expectedRecurrent['state'])) {
                $this->assertEquals($expectedRecurrent['state'], $rp->state);
                continue;
            }

            /** @var RecurrentPaymentsResolver $rpResolver */
            $rpResolver = $this->inject(RecurrentPaymentsResolver::class);
            $subscriptionType = $rpResolver->resolveSubscriptionType($rp);
            $this->assertEquals($expectedRecurrent['type'], $subscriptionType->code);
            $this->assertEquals(DateTime::from($expectedRecurrent['charge_at']), $rp->charge_at);
        }
    }

    private function getUser($email) : IRow
    {
        $user = $this->userManager->loadUserByEmail($email);
        if (!$user) {
            $user = $this->usersRepository->add($email, 'secret', '', '');
        }
        return $user;
    }

    private function getSubscriptionType($code, $price = null)
    {
        /** @var ContentAccessRepository $contentAccessRepository */
        $contentAccessRepository = $this->inject(ContentAccessRepository::class);
        if (!$contentAccessRepository->exists($code)) {
            $contentAccessRepository->add($code, $code);
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
                ->setLength(31)
                ->setActive(true)
                ->setDefault(true)
                ->setContentAccessOption($code)
                ->save();
        }

        return $subscriptionType;
    }

    private function configureUpgradeOption($schema, $baseSubscriptionType, $targetSubscriptionType)
    {
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
            $upgradeOptionsRepository->add(
                $schemaRow,
                $upgradeType,
                [
                    'require_content' => ['premium'],
                    'omit_content' => ['basic'],
                ]
            );
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
                ->where('cid = ?', $rp->cid)
                ->where('id < ?', $rp->id)
                ->order('created_at DESC')
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
