<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxShipment;

/**
 * Guards order-shipment edits in the back office.
 *
 * Edits (delivery option / relay choice) must be refused once the shipment
 * has been booked (or anything past booked). A `cancelled` shipment becomes
 * editable again so the merchant can rebook with a different option.
 */
final class ShipmentLockGuard
{
    private const LOCKED_STATUSES = [
        ShipmentService::STATUS_BOOKED,
        ShipmentService::STATUS_SHIPPED,
        ShipmentService::STATUS_DELIVERED,
    ];

    public function isLocked(?MyFlyingBoxShipment $shipment): bool
    {
        if (!$shipment) {
            return false;
        }

        return in_array($shipment->getStatus(), self::LOCKED_STATUSES, true);
    }
}
