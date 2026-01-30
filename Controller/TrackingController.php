<?php

namespace MyFlyingBox\Controller;

use MyFlyingBox\Service\TrackingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;

/**
 * Controller for tracking shipments
 */
class TrackingController extends BaseAdminController
{
    /**
     * Get tracking information for an order
     */
    public function getTrackingAction(Request $request, TrackingService $trackingService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $orderId = $request->get('order_id');

            if (!$orderId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Order ID required',
                ]);
            }

            $tracking = $trackingService->getTrackingForOrder((int) $orderId);

            return new JsonResponse([
                'success' => true,
                'tracking' => $tracking,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync tracking status from API
     */
    public function syncStatusAction(Request $request, TrackingService $trackingService): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $shipmentId = $request->get('shipment_id');

            if (!$shipmentId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Shipment ID required',
                ]);
            }

            $success = $trackingService->syncTrackingStatus((int) $shipmentId);

            return new JsonResponse([
                'success' => $success,
                'message' => $success ? 'Status updated' : 'Could not update status',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }
}
