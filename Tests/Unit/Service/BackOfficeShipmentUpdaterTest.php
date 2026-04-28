<?php

declare(strict_types=1);

namespace MyFlyingBox\Tests\Unit\Service;

use MyFlyingBox\Model\MyFlyingBoxOffer;
use MyFlyingBox\Service\BackOfficeShipmentUpdater;
use MyFlyingBox\Service\PriceSurchargeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Order;
use Thelia\Module\BaseModule;

/**
 * Unit coverage for the no-op (idempotency) branch of BackOfficeShipmentUpdater.
 *
 * The "apply update" branch goes through Propel::getConnection() / Order::save()
 * and therefore belongs to the integration test tier (real DB) — covered by
 * functional tests, not unit tests.
 */
final class BackOfficeShipmentUpdaterTest extends TestCase
{
    private const MODULE_CODE = 'MyFlyingBox';
    private const MODULE_ID = 4242;

    protected function setUp(): void
    {
        // Prime BaseModule's static module-id cache so getModuleId() doesn't
        // need a DB connection. The cache is keyed by module code.
        $reflection = new \ReflectionClass(BaseModule::class);
        $prop = $reflection->getProperty('moduleIds');
        $prop->setAccessible(true);
        $prop->setValue(null, [self::MODULE_CODE => self::MODULE_ID]);
    }

    public function testReturnsFalseWhenPostageAndModuleAlreadyMatch(): void
    {
        $surcharge = $this->createMock(PriceSurchargeService::class);
        $surcharge->expects(self::once())
            ->method('apply')
            ->with(12.34)
            ->willReturn(12.34);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $updater = new BackOfficeShipmentUpdater($surcharge, $dispatcher, new NullLogger());

        $offer = $this->createMock(MyFlyingBoxOffer::class);
        $offer->method('getTotalPriceInCents')->willReturn(1234);
        $offer->method('getId')->willReturn(99);

        $order = $this->createMock(Order::class);
        $order->method('getPostage')->willReturn(12.34);
        $order->method('getDeliveryModuleId')->willReturn(self::MODULE_ID);
        $order->method('getId')->willReturn(101);
        $order->expects(self::never())->method('setPostage');
        $order->expects(self::never())->method('save');

        self::assertFalse(
            $updater->applyOfferToOrder($order, $offer),
            'Same module + same postage must short-circuit without persisting.'
        );
    }

    public function testReturnsFalseWhenPostageDifferenceIsBelowRoundingThreshold(): void
    {
        $surcharge = $this->createMock(PriceSurchargeService::class);
        // 12.343 rounds to 12.34, current postage 12.34 → diff < 0.005 → no-op.
        $surcharge->method('apply')->willReturn(12.343);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $updater = new BackOfficeShipmentUpdater($surcharge, $dispatcher, new NullLogger());

        $offer = $this->createMock(MyFlyingBoxOffer::class);
        $offer->method('getTotalPriceInCents')->willReturn(1234);

        $order = $this->createMock(Order::class);
        $order->method('getPostage')->willReturn(12.34);
        $order->method('getDeliveryModuleId')->willReturn(self::MODULE_ID);
        $order->expects(self::never())->method('save');

        self::assertFalse($updater->applyOfferToOrder($order, $offer));
    }

    public function testIdempotencyDoesNotShortCircuitWhenModuleIsDifferent(): void
    {
        // Same postage but different delivery module → must NOT be treated as
        // idempotent. The persist branch starts with Propel::getConnection(),
        // which is unavailable in a unit context — we use that failure as the
        // signal that we left the no-op branch.
        $this->assertEntersPersistBranch(
            currentPostage: 12.34,
            currentModuleId: self::MODULE_ID + 1,
            offerCents: 1234,
            surchargeReturn: 12.34,
        );
    }

    public function testIdempotencyDoesNotShortCircuitWhenPostageDiffers(): void
    {
        $this->assertEntersPersistBranch(
            currentPostage: 12.34,
            currentModuleId: self::MODULE_ID,
            offerCents: 1500,
            surchargeReturn: 15.00,
        );
    }

    private function assertEntersPersistBranch(
        float $currentPostage,
        int $currentModuleId,
        int $offerCents,
        float $surchargeReturn,
    ): void {
        $surcharge = $this->createMock(PriceSurchargeService::class);
        $surcharge->method('apply')->willReturn($surchargeReturn);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch'); // never reached

        $updater = new BackOfficeShipmentUpdater($surcharge, $dispatcher, new NullLogger());

        $offer = $this->createMock(MyFlyingBoxOffer::class);
        $offer->method('getTotalPriceInCents')->willReturn($offerCents);

        $order = $this->createMock(Order::class);
        $order->method('getPostage')->willReturn($currentPostage);
        $order->method('getDeliveryModuleId')->willReturn($currentModuleId);

        try {
            $updater->applyOfferToOrder($order, $offer);
            self::fail('Expected the persist branch to be reached.');
        } catch (\Throwable $e) {
            // The no-op branch returns false instead of throwing; any throwable
            // here proves we got past it. The concrete error comes from Propel
            // because there's no service container in unit tests.
            self::assertStringContainsString(
                'getConnection',
                $e->getMessage(),
                'Expected the persist branch to call Propel::getConnection().'
            );
        }
    }

    public function testEventConstantIsTheStandardSetDeliveryModule(): void
    {
        // Sanity check: the dispatcher contract used by the service relies on
        // TheliaEvents::ORDER_SET_DELIVERY_MODULE staying stable. Catching a
        // rename here saves us from a silent regression at runtime.
        self::assertSame(
            'action.order.setDeliveryModule',
            TheliaEvents::ORDER_SET_DELIVERY_MODULE
        );
    }
}
