<?php

declare(strict_types=1);

namespace MyFlyingBox\Tests\Unit\Service;

use MyFlyingBox\Model\MyFlyingBoxShipment;
use MyFlyingBox\Service\ShipmentLockGuard;
use MyFlyingBox\Service\ShipmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShipmentLockGuardTest extends TestCase
{
    private ShipmentLockGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new ShipmentLockGuard();
    }

    public function testNullShipmentIsNotLocked(): void
    {
        self::assertFalse($this->guard->isLocked(null));
    }

    #[DataProvider('lockedStatusProvider')]
    public function testLockedStatuses(string $status): void
    {
        $shipment = $this->makeShipmentWithStatus($status);

        self::assertTrue(
            $this->guard->isLocked($shipment),
            sprintf('Expected status "%s" to lock edits.', $status)
        );
    }

    #[DataProvider('unlockedStatusProvider')]
    public function testUnlockedStatuses(string $status): void
    {
        $shipment = $this->makeShipmentWithStatus($status);

        self::assertFalse(
            $this->guard->isLocked($shipment),
            sprintf('Expected status "%s" to allow edits.', $status)
        );
    }

    public static function lockedStatusProvider(): array
    {
        return [
            'booked'    => [ShipmentService::STATUS_BOOKED],
            'shipped'   => [ShipmentService::STATUS_SHIPPED],
            'delivered' => [ShipmentService::STATUS_DELIVERED],
        ];
    }

    public static function unlockedStatusProvider(): array
    {
        return [
            'pending'   => [ShipmentService::STATUS_PENDING],
            'cancelled' => [ShipmentService::STATUS_CANCELLED],
            'unknown'   => ['some-future-status'],
            'empty'     => [''],
        ];
    }

    private function makeShipmentWithStatus(string $status): MyFlyingBoxShipment
    {
        $shipment = $this->getMockBuilder(MyFlyingBoxShipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStatus'])
            ->getMock();

        $shipment->method('getStatus')->willReturn($status);

        return $shipment;
    }
}
