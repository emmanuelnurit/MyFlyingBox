<?php

declare(strict_types=1);

namespace MyFlyingBox\Controller;

use MyFlyingBox\Service\TrackingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Tools\TokenProvider;

/**
 * Controller for tracking shipments
 */
class TrackingController extends BaseAdminController
{
    /**
     * Validate CSRF token from AJAX request header or body.
     */
    private function checkCsrfToken(Request $request, TokenProvider $tokenProvider): ?JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token')
            ?? $request->request->get('_token')
            ?? (json_decode($request->getContent(), true)['_token'] ?? null);

        if (empty($token)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing CSRF token'], 403);
        }

        try {
            $tokenProvider->checkToken($token);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        }

        return null;
    }

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
    public function syncStatusAction(Request $request, TrackingService $trackingService, TokenProvider $tokenProvider): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        if (null !== $csrfError = $this->checkCsrfToken($request, $tokenProvider)) {
            return $csrfError;
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
