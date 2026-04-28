<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxOffer;
use MyFlyingBox\Model\MyFlyingBoxShipment;
use MyFlyingBox\MyFlyingBox;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;

/**
 * Recomputes and persists the postage of an order when the merchant changes
 * the delivery option from the back-office.
 *
 * Silent update: the merchant absorbs any delta — no email, no notification
 * to the customer is dispatched here. Idempotent: calling twice with the
 * same offer is a no-op.
 */
final readonly class BackOfficeShipmentUpdater
{
    public function __construct(
        private PriceSurchargeService $surchargeService,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Apply the chosen offer to the given order:
     *  - recompute postage from the offer (with configured surcharges)
     *  - update Order.postage / postageTax / postageUntaxed / deliveryModuleId
     *  - persist within a transaction
     *  - dispatch ORDER_SET_DELIVERY_MODULE so other modules can react
     *
     * @return bool true if anything changed, false if the call was a no-op
     */
    public function applyOfferToOrder(
        Order $order,
        MyFlyingBoxOffer $offer,
        ?MyFlyingBoxShipment $shipment = null,
    ): bool {
        $newPostage = $this->surchargeService->apply($offer->getTotalPriceInCents() / 100);
        $newPostage = round($newPostage, 2);

        $myflyingboxModuleId = (int) MyFlyingBox::getModuleId();
        $currentPostage = round((float) $order->getPostage(), 2);
        $currentModuleId = (int) $order->getDeliveryModuleId();

        // Idempotency: same module + same postage → nothing to do.
        if ($currentModuleId === $myflyingboxModuleId
            && abs($currentPostage - $newPostage) < 0.005) {
            $this->logger->info('MyFlyingBox BO: postage unchanged, skipping update', [
                'order_id' => $order->getId(),
                'offer_id' => $offer->getId(),
                'postage' => $newPostage,
            ]);

            return false;
        }

        $con = Propel::getConnection(OrderTableMap::DATABASE_NAME);
        $con->beginTransaction();

        try {
            $order
                ->setDeliveryModuleId($myflyingboxModuleId)
                ->setPostage($newPostage)
                // API price is TTC and the front-office getPostage() builds the
                // OrderPostage with amountTax = 0, so we keep tax at 0 here too.
                ->setPostageTax(0)
                ->setPostageTaxRuleTitle('')
                ->save($con);

            $con->commit();
        } catch (\Throwable $e) {
            $con->rollBack();
            $this->logger->error('MyFlyingBox BO: failed to persist postage update', [
                'order_id' => $order->getId(),
                'offer_id' => $offer->getId(),
                'exception' => $e,
            ]);

            throw $e;
        }

        $this->logger->info('MyFlyingBox BO: postage updated silently', [
            'order_id' => $order->getId(),
            'offer_id' => $offer->getId(),
            'shipment_id' => $shipment?->getId(),
            'previous_postage' => $currentPostage,
            'new_postage' => $newPostage,
            'previous_module_id' => $currentModuleId,
            'new_module_id' => $myflyingboxModuleId,
        ]);

        // Notify the rest of the application (hooks, listeners) without
        // sending any customer-facing notification — the standard
        // SET_DELIVERY_MODULE handler doesn't email the customer.
        $event = (new OrderEvent($order))->setDeliveryModule($myflyingboxModuleId);
        $this->dispatcher->dispatch($event, TheliaEvents::ORDER_SET_DELIVERY_MODULE);

        return true;
    }
}
