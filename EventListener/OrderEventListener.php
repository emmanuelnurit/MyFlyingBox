<?php

namespace MyFlyingBox\EventListener;

use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\ShipmentService;
use Psr\Log\LoggerInterface;
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
    private LoggerInterface $logger;

    public function __construct(ShipmentService $shipmentService, RequestStack $requestStack, LoggerInterface $logger)
    {
        $this->shipmentService = $shipmentService;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
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

        $this->logger->info('[MFB] onOrderPay called for order ' . $order->getId());

        // Check if MyFlyingBox is the delivery module
        if (!$this->isMyFlyingBoxDelivery($order->getDeliveryModuleId())) {
            $this->logger->info('[MFB] Order ' . $order->getId() . ' does not use MyFlyingBox, skipping');
            return;
        }

        $this->logger->info('[MFB] Order ' . $order->getId() . ' uses MyFlyingBox');

        // Check if auto-create shipment is enabled
        $autoCreate = MyFlyingBox::getConfigValue('myflyingbox_auto_create_shipment', true);
        if (!$autoCreate) {
            $this->logger->info('[MFB] Auto-create shipment is disabled');
            return;
        }

        // Get selected offer from session
        $serviceId = null;
        $offerUuid = null;

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $session = $request->getSession();
            $selectedOfferId = $session->get('mfb_selected_offer_id');

            $this->logger->info('[MFB] Selected offer ID from session: ' . ($selectedOfferId ?? 'NULL'));

            if ($selectedOfferId) {
                $offer = MyFlyingBoxOfferQuery::create()
                    ->filterById($selectedOfferId)
                    ->findOne();

                if ($offer) {
                    $serviceId = $offer->getServiceId();
                    $offerUuid = $offer->getApiOfferUuid();
                    $this->logger->info('[MFB] Offer found: serviceId=' . $serviceId . ', offerUuid=' . $offerUuid);

                    // Clear selection from session after use
                    $session->remove('mfb_selected_offer_id');
                } else {
                    $this->logger->warning('[MFB] Offer not found for ID: ' . $selectedOfferId);
                }
            }
        } else {
            $this->logger->warning('[MFB] No request available');
        }

        // Create shipment from order with selected offer info
        $this->logger->info('[MFB] Creating shipment with serviceId=' . ($serviceId ?? 'NULL'));
        $shipment = $this->shipmentService->createShipmentFromOrder($order, $serviceId, $offerUuid);

        if ($shipment) {
            $this->logger->info('[MFB] Shipment created successfully: ID=' . $shipment->getId());
        } else {
            $this->logger->error('[MFB] Failed to create shipment for order ' . $order->getId());
        }
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
