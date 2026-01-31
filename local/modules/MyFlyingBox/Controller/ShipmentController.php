<?php

namespace MyFlyingBox\Controller;

use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use MyFlyingBox\Service\ShipmentService;
use MyFlyingBox\Service\TrackingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\OrderQuery;

/**
 * Back-office controller for shipment management
 */
class ShipmentController extends BaseAdminController
{
    /**
     * View shipment details
     */
    public function viewAction(Request $request, int $shipmentId): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return $response;
        }

        $shipment = MyFlyingBoxShipmentQuery::create()
            ->findPk($shipmentId);

        if (!$shipment) {
            return $this->pageNotFound();
        }

        $order = OrderQuery::create()->findPk($shipment->getOrderId());

        $parcels = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipmentId)
            ->find();

        return $this->render('myflyingbox/shipment-view', [
            'shipment' => $shipment,
            'order' => $order,
            'parcels' => $parcels,
        ]);
    }

    /**
     * Book a shipment with the carrier
     */
    public function bookAction(Request $request, ShipmentService $shipmentService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $shipmentId = $request->get('shipment_id');
            $collectionDate = $request->get('collection_date');

            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);

            if (!$shipment) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Shipment not found',
                ]);
            }

            $date = null;
            if ($collectionDate) {
                $date = new \DateTime($collectionDate);
            }

            $result = $shipmentService->bookShipmentWithDetails($shipment, $date);

            if ($result['success']) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Shipment booked successfully',
                    'order_uuid' => $shipment->getApiOrderUuid(),
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => $result['error'] ?? 'Failed to book shipment',
                ]);
            }

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Get shipping labels
     */
    public function labelsAction(Request $request, ShipmentService $shipmentService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $shipmentId = $request->get('shipment_id');

            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);

            if (!$shipment) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Shipment not found',
                ]);
            }

            $labels = $shipmentService->getLabels($shipment);

            return new JsonResponse([
                'success' => true,
                'labels' => $labels,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Download shipping label PDF (proxy to API with authentication)
     */
    public function downloadLabelAction(Request $request, \MyFlyingBox\Service\LceApiService $apiService): Response
    {
        // Check authentication - return the actual auth response (redirect to login if needed)
        if (null !== $authResponse = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return $authResponse;
        }

        try {
            $shipmentId = $request->get('shipment_id');

            if (!$shipmentId) {
                return new Response('Shipment ID is required', 400, ['Content-Type' => 'text/plain']);
            }

            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);

            if (!$shipment) {
                return new Response('Shipment not found', 404, ['Content-Type' => 'text/plain']);
            }

            $orderUuid = $shipment->getApiOrderUuid();
            if (empty($orderUuid)) {
                return new Response('Shipment not booked yet. Please book the shipment first.', 400, ['Content-Type' => 'text/plain']);
            }

            // Download the PDF from the API
            $pdfContent = $apiService->downloadLabel($orderUuid, 'pdf');

            // Return the PDF as a download
            $response = new Response($pdfContent);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'attachment; filename="label-' . $shipmentId . '.pdf"');
            $response->headers->set('Content-Length', strlen($pdfContent));

            return $response;

        } catch (\Exception $e) {
            return new Response('Error downloading label: ' . $e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        }
    }

    /**
     * Update shipment status
     */
    public function updateStatusAction(Request $request, ShipmentService $shipmentService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $shipmentId = $request->get('shipment_id');
            $status = $request->get('status');

            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);

            if (!$shipment) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Shipment not found',
                ]);
            }

            $validStatuses = [
                ShipmentService::STATUS_PENDING,
                ShipmentService::STATUS_BOOKED,
                ShipmentService::STATUS_SHIPPED,
                ShipmentService::STATUS_DELIVERED,
                ShipmentService::STATUS_CANCELLED,
            ];

            if (!in_array($status, $validStatuses)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid status',
                ]);
            }

            $shipmentService->updateStatus($shipment, $status);

            return new JsonResponse([
                'success' => true,
                'message' => 'Status updated',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel a shipment
     */
    public function cancelAction(Request $request, ShipmentService $shipmentService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $shipmentId = $request->get('shipment_id');

            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);

            if (!$shipment) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Shipment not found',
                ]);
            }

            $success = $shipmentService->cancelShipment($shipment);

            if ($success) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Shipment cancelled',
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Cannot cancel this shipment',
                ]);
            }

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Create shipment for an order
     */
    public function createAction(Request $request, ShipmentService $shipmentService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::CREATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $orderId = $request->get('order_id');
            $serviceId = $request->get('service_id');

            $order = OrderQuery::create()->findPk($orderId);

            if (!$order) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Order not found',
                ]);
            }

            $shipment = $shipmentService->createShipmentFromOrder($order, $serviceId);

            if ($shipment) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Shipment created',
                    'shipment_id' => $shipment->getId(),
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Failed to create shipment',
                ]);
            }

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync tracking status for a shipment
     */
    public function syncTrackingAction(Request $request, TrackingService $trackingService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $shipmentId = (int) $request->get('shipment_id');

            if (!$shipmentId) {
                return new JsonResponse(['success' => false, 'message' => 'Shipment ID is required'], 400);
            }

            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);
            if (!$shipment) {
                return new JsonResponse(['success' => false, 'message' => 'Shipment not found'], 404);
            }

            $previousStatus = $shipment->getStatus();
            $success = $trackingService->syncTrackingStatus($shipmentId);

            // Reload shipment to get updated status
            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);
            $statusChanged = $shipment->getStatus() !== $previousStatus;

            return new JsonResponse([
                'success' => $success,
                'status_changed' => $statusChanged,
                'new_status' => $shipment->getStatus(),
                'message' => $success ? 'Tracking updated' : 'No updates available'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tracking information for an order
     */
    public function trackingAction(Request $request, TrackingService $trackingService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $orderId = (int) $request->get('order_id');

            if (!$orderId) {
                return new JsonResponse(['success' => false, 'message' => 'Order ID is required'], 400);
            }

            $tracking = $trackingService->getTrackingForOrder($orderId);

            return new JsonResponse([
                'success' => true,
                'tracking' => $tracking,
                'debug' => [
                    'order_id' => $orderId,
                    'shipments_count' => count($tracking),
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Debug: Get raw API response for tracking
     */
    public function debugTrackingAction(Request $request, \MyFlyingBox\Service\LceApiService $apiService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $shipmentId = (int) $request->get('shipment_id');

            if (!$shipmentId) {
                return new JsonResponse(['success' => false, 'message' => 'Shipment ID is required'], 400);
            }

            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);
            if (!$shipment) {
                return new JsonResponse(['success' => false, 'message' => 'Shipment not found'], 404);
            }

            if (!$shipment->getApiOrderUuid()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No API Order UUID',
                    'shipment_status' => $shipment->getStatus()
                ]);
            }

            $orderUuid = $shipment->getApiOrderUuid();
            $debug = [
                'shipment_id' => $shipmentId,
                'api_order_uuid' => $orderUuid,
                'shipment_status' => $shipment->getStatus(),
            ];

            // Try tracking endpoint
            try {
                $trackingResponse = $apiService->getOrderTracking($orderUuid);
                $debug['tracking_endpoint'] = $trackingResponse;
            } catch (\Exception $e) {
                $debug['tracking_endpoint_error'] = $e->getMessage();
            }

            // Try order endpoint
            try {
                $orderResponse = $apiService->getOrder($orderUuid);
                $debug['order_endpoint'] = $orderResponse;
            } catch (\Exception $e) {
                $debug['order_endpoint_error'] = $e->getMessage();
            }

            return new JsonResponse([
                'success' => true,
                'debug' => $debug
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a return shipment from an existing shipment
     */
    public function createReturnAction(Request $request, ShipmentService $shipmentService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::CREATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $shipmentId = (int) $request->get('shipment_id');
            $serviceId = $request->get('service_id') ? (int) $request->get('service_id') : null;

            if (!$shipmentId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Shipment ID is required',
                ], 400);
            }

            $originalShipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);

            if (!$originalShipment) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Shipment not found',
                ], 404);
            }

            // Validate shipment status
            $validStatuses = [ShipmentService::STATUS_SHIPPED, ShipmentService::STATUS_DELIVERED];
            if (!in_array($originalShipment->getStatus(), $validStatuses)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Cannot create return: shipment must be shipped or delivered',
                ]);
            }

            // Check if this is already a return shipment
            if ($originalShipment->getIsReturn()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Cannot create return from a return shipment',
                ]);
            }

            $returnShipment = $shipmentService->createReturnShipment($originalShipment, $serviceId);

            if ($returnShipment) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Return shipment created successfully',
                    'return_shipment_id' => $returnShipment->getId(),
                    'original_shipment_id' => $originalShipment->getId(),
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Failed to create return shipment',
                ]);
            }

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
