<?php

namespace MyFlyingBox\EventListener;

use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\ShipmentService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ModuleQuery;

/**
 * Event listener for order-related events
 */
class OrderEventListener implements EventSubscriberInterface
{
    private ShipmentService $shipmentService;
    private RequestStack $requestStack;

    public function __construct(ShipmentService $shipmentService, RequestStack $requestStack)
    {
        $this->shipmentService = $shipmentService;
        $this->requestStack = $requestStack;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_SET_DELIVERY_MODULE => ['onOrderSetDeliveryModule', 128],
            TheliaEvents::ORDER_PAY => ['onOrderPay', 128],
        ];
    }

    /**
     * Called when delivery module is set for an order
     * Check if MyFlyingBox is selected
     */
    public function onOrderSetDeliveryModule(OrderEvent $event): void
    {
        $order = $event->getOrder();

        // Check if MyFlyingBox is the delivery module
        if (!$this->isMyFlyingBoxDelivery($order->getDeliveryModuleId())) {
            return;
        }

        // Additional processing could be done here if needed
    }

    /**
     * Called when order is paid
     * Create shipment record for MyFlyingBox orders
     */
    public function onOrderPay(OrderEvent $event): void
    {
        $order = $event->getOrder();

        // Check if MyFlyingBox is the delivery module
        if (!$this->isMyFlyingBoxDelivery($order->getDeliveryModuleId())) {
            return;
        }

        // Check if auto-create shipment is enabled
        $autoCreate = MyFlyingBox::getConfigValue('myflyingbox_auto_create_shipment', true);
        if (!$autoCreate) {
            return;
        }

        // Get selected offer from session
        $serviceId = null;
        $offerUuid = null;

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $session = $request->getSession();
            $selectedOfferId = $session->get('mfb_selected_offer_id');

            if ($selectedOfferId) {
                $offer = MyFlyingBoxOfferQuery::create()
                    ->filterById($selectedOfferId)
                    ->findOne();

                if ($offer) {
                    $serviceId = $offer->getServiceId();
                    $offerUuid = $offer->getApiOfferUuid();

                    // Clear selection from session after use
                    $session->remove('mfb_selected_offer_id');
                }
            }
        }

        // Create shipment from order with selected offer info
        $this->shipmentService->createShipmentFromOrder($order, $serviceId, $offerUuid);
    }

    /**
     * Check if the given module ID is MyFlyingBox
     */
    private function isMyFlyingBoxDelivery(?int $moduleId): bool
    {
        if (!$moduleId) {
            return false;
        }

        $module = ModuleQuery::create()->findPk($moduleId);
        if (!$module) {
            return false;
        }

        return $module->getCode() === 'MyFlyingBox';
    }
}
