<?php
declare(strict_types=1);

namespace Crm\UpgradesModule\Tests;

use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\SubscriptionMovedHandler;
use Crm\PaymentsModule\Models\GatewayFactory;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\PaymentProcessor;
use Crm\PaymentsModule\Models\UnknownPaymentMethodCode;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Seeders\PaymentGatewaysSeeder;
use Crm\PaymentsModule\Seeders\TestPaymentGatewaysSeeder;
use Crm\PaymentsModule\Tests\Gateways\TestRecurrentGateway;
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
use Crm\UpgradesModule\DataProviders\BaseSubscriptionDataProvider;
use Crm\UpgradesModule\Events\PaymentStatusChangeHandler;
use Crm\UpgradesModule\Models\Upgrade\AvailableUpgraders;
use Crm\UpgradesModule\Models\Upgrade\PaidRecurrentUpgrade;
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

class BaseSubscriptionDataProviderTest extends DatabaseTestCase
{
    private const SUBSCRIPTION_TYPE_BASIC = 'st_basic';
    private const SUBSCRIPTION_TYPE_PREMIUM = 'st_premium';
    private const CONTENT_ACCESS_BASIC = 'basic';
    private const CONTENT_ACCESS_PREMIUM = 'premium';

    private bool $initialized = false;
    private AvailableUpgraders $availableUpgraders;
    private SubscriptionsRepository $subscriptionsRepository;
    private SubscriptionTypesRepository $subscriptionTypesRepository;
    private PaymentsRepository $paymentsRepository;
    private RecurrentPaymentsRepository $recurrentPaymentsRepository;
    private SpecificUserSubscriptions $upgradeableSubscriptions;
    private SubscriptionUpgradesRepository $subscriptionUpgradesRepository;

    public function setUp(): void
    {
        parent::setUp();

        $this->availableUpgraders = $this->inject(AvailableUpgraders::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);
        $this->subscriptionUpgradesRepository = $this->getRepository(SubscriptionUpgradesRepository::class);
        $this->upgradeableSubscriptions = $this->inject(SpecificUserSubscriptions::class);

        /** @var DataProviderManager $dataProviderManager */
        $dataProviderManager = $this->inject(DataProviderManager::class);
        /** @var LazyEventEmitter $lazyEventEmitter */
        $lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        /** @var GatewayFactory $gatewayFactory */
        $gatewayFactory = $this->inject(GatewayFactory::class);
        /** @var PaymentStatusChangeHandler $upgradeStatusChangeHandler */
        $upgradeStatusChangeHandler = $this->inject(PaymentStatusChangeHandler::class);
        /** @var UpgraderFactory $upgraderFactory */
        $upgraderFactory = $this->inject(UpgraderFactory::class);

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
            $gatewayFactory->getGateway(TestRecurrentGateway::GATEWAY_CODE);
        } catch (UnknownPaymentMethodCode $e) {
            $gatewayFactory->registerGateway(TestRecurrentGateway::GATEWAY_CODE, TestRecurrentGateway::class);
        }

        // initialize subscription types
        $basic = $this->getSubscriptionType(self::SUBSCRIPTION_TYPE_BASIC, [self::CONTENT_ACCESS_BASIC], 31, 5);
        $premium = $this->getSubscriptionType(self::SUBSCRIPTION_TYPE_PREMIUM, [self::CONTENT_ACCESS_PREMIUM], 31, 10);

        $this->configureUpgradeOption(
            schema: 'default',
            baseSubscriptionType: $basic,
            requireContent: [self::CONTENT_ACCESS_PREMIUM],
            omitContent: [self::CONTENT_ACCESS_BASIC],
        );

        if (!$this->initialized) {
            // data provider we're testing
            $dataProviderManager->registerDataProvider(
                'payments.dataprovider.base_subscription',
                $this->inject(BaseSubscriptionDataProvider::class),
            );

            // clear initialized handlers (we do not want duplicated listeners)
            $lazyEventEmitter->removeAllListeners(PaymentChangeStatusEvent::class);
            $lazyEventEmitter->removeAllListeners(SubscriptionShortenedEvent::class);
            $lazyEventEmitter->removeAllListeners(SubscriptionMovedEvent::class);

            // bind necessary event handlers
            $lazyEventEmitter->addListener(
                PaymentChangeStatusEvent::class,
                $upgradeStatusChangeHandler,
                1000, // we need to have this executed before \Crm\PaymentsModule\Events\PaymentStatusChangeHandler
            );
            $lazyEventEmitter->addListener(
                PaymentChangeStatusEvent::class,
                $this->inject(\Crm\PaymentsModule\Events\PaymentStatusChangeHandler::class),
                500,
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

    public function testProvider(): void
    {
        $stBasic = $this->subscriptionTypesRepository->findByCode(self::SUBSCRIPTION_TYPE_BASIC);

        $user = $this->inject(UserManager::class)->addNewUser('user@example.com', false, 'unknown', null, false);
        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($stBasic));
        $payment = $this->makePayment($user, $stBasic, $paymentItemContainer);

        $recurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertNotNull($recurrent);

        $this->upgradeableSubscriptions->setSpecificSubscriptions($user->id, $payment->subscription);
        $this->availableUpgraders->setUpgradeableSubscriptions($this->upgradeableSubscriptions);
        $baseSubscription = $payment->subscription;

        $upgraders = $this->availableUpgraders->all($user->id);
        $this->assertCount(1, $upgraders);

        $upgrader = $upgraders[0];
        $this->assertEquals(get_class($upgrader), PaidRecurrentUpgrade::class);
        $result = $upgrader->upgrade();
        $this->assertTrue($result);

        $upgradedSubscription = $this->subscriptionUpgradesRepository->findBy('base_subscription_id', $baseSubscription->id)->upgraded_subscription;
        $this->assertNotNull($upgradedSubscription);

        $this->assertFalse($this->recurrentPaymentsRepository->isStoppedBySubscription($upgradedSubscription));
    }

    private function configureUpgradeOption(
        string $schema,
        ActiveRow $baseSubscriptionType,
        array $requireContent,
        array $omitContent,
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
            PaidRecurrentUpgrade::TYPE,
        ];

        foreach ($testedUpgradeTypes as $upgradeType) {
            $option = $upgradeOptionsRepository->findForSchema(
                $schemaRow->id,
                $upgradeType,
                [
                    'require_content' => $requireContent,
                    'omit_content' => $omitContent,
                ],
            );

            if (!$option) {
                $upgradeOptionsRepository->add(
                    $schemaRow,
                    $upgradeType,
                    [
                        'require_content' => $requireContent,
                        'omit_content' => $omitContent,
                    ],
                );
            }
        }
    }

    private function makePayment(
        ActiveRow $user,
        ActiveRow $subscriptionType,
        PaymentItemContainer $paymentItemContainer,
    ): ActiveRow {
        $paymentGateway = $this->getRepository(PaymentGatewaysRepository::class)->findByCode(TestRecurrentGateway::GATEWAY_CODE);

        $payment = $this->paymentsRepository->add(
            subscriptionType: $subscriptionType,
            paymentGateway: $paymentGateway,
            user: $user,
            paymentItemContainer: $paymentItemContainer,
        );

        // Make manual payment
        $this->inject(PaymentProcessor::class)->complete($payment, fn() => null);
        return $this->paymentsRepository->find($payment->id);
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
}
