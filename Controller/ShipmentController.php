<?php

declare(strict_types=1);

namespace MyFlyingBox\Controller;

use MyFlyingBox\Model\MyFlyingBoxCartRelay;
use MyFlyingBox\Model\MyFlyingBoxCartRelayQuery;
use MyFlyingBox\Model\MyFlyingBoxOffer;
use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use MyFlyingBox\Model\MyFlyingBoxQuoteQuery;
use MyFlyingBox\Model\MyFlyingBoxShipment;
use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\CarrierLogoProvider;
use MyFlyingBox\Service\LceApiService;
use MyFlyingBox\Service\PriceSurchargeService;
use MyFlyingBox\Service\QuoteService;
use MyFlyingBox\Service\ShipmentLockGuard;
use MyFlyingBox\Service\ShipmentService;
use MyFlyingBox\Service\TrackingService;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;
use Psr\Log\LoggerInterface;
use Thelia\Model\CartQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
/**
 * Back-office controller for shipment management
 */
class ShipmentController extends BaseAdminController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Validate CSRF token from AJAX request query string or header.
     * Reads the session directly after auth (avoids TokenProvider constructor timing issue).
     * Returns a JsonResponse on failure, null on success.
     */
    private function checkCsrfToken(Request $request): ?JsonResponse
    {
        $token = $request->query->get('_token')
            ?? $request->headers->get('X-CSRF-Token')
            ?? $request->request->get('_token')
            ?? (json_decode($request->getContent(), true)['_token'] ?? null);

        if (empty($token)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing CSRF token'], 403);
        }

        // Read directly from session — at this point session is started (checkAuth already ran).
        // Uses module-specific key set by BackHook::getCsrfToken() to avoid
        // TokenProvider singleton timing issues entirely.
        $sessionToken = $request->getSession()->get('myflyingbox_csrf_token');

        if (empty($sessionToken) || $token !== $sessionToken) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        }

        return null;
    }

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

        if (null !== $csrfError = $this->checkCsrfToken($request)) {
            return $csrfError;
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
                try {
                    $date = new \DateTime($collectionDate);
                } catch (\Exception) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Invalid date format',
                    ], 400);
                }
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
                    'error_detail' => $result['error_raw'] ?? null,
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('MyFlyingBox: error booking shipment', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => 'An internal error occurred while booking the shipment',
            ], 500);
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
            $this->logger->error('MyFlyingBox: error fetching labels', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => 'An internal error occurred while fetching labels',
            ], 500);
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
            $response->headers->set('Content-Length', (string) strlen($pdfContent));

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('MyFlyingBox: error downloading label', ['exception' => $e]);
            return new Response('An error occurred while downloading the label', 500, ['Content-Type' => 'text/plain']);
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

        if (null !== $csrfError = $this->checkCsrfToken($request)) {
            return $csrfError;
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
            $this->logger->error('MyFlyingBox: error updating shipment status', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => 'An internal error occurred while updating status',
            ], 500);
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

        if (null !== $csrfError = $this->checkCsrfToken($request)) {
            return $csrfError;
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
            $this->logger->error('MyFlyingBox: error cancelling shipment', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => 'An internal error occurred while cancelling the shipment',
            ], 500);
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

        if (null !== $csrfError = $this->checkCsrfToken($request)) {
            return $csrfError;
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
            $this->logger->error('MyFlyingBox: error creating shipment', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => 'An internal error occurred while creating the shipment',
            ], 500);
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

        if (null !== $csrfError = $this->checkCsrfToken($request)) {
            return $csrfError;
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
            $this->logger->error('MyFlyingBox: error syncing tracking', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => 'An internal error occurred while syncing tracking',
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
                'message' => 'An internal error occurred',
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

        if (null !== $csrfError = $this->checkCsrfToken($request)) {
            return $csrfError;
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
            $this->logger->error('MyFlyingBox: error creating return shipment', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => 'An internal error occurred while creating the return shipment',
            ], 500);
        }
    }

    /**
     * BO — list eligible delivery options for an order.
     *
     * Reuses the cart-based quote (created at checkout). If a quote exists
     * but is stale, recreates one synchronously using the order's delivery
     * address. Returns formatted offers (relay + non-relay) plus the
     * currently persisted shipment selection (if any).
     */
    public function listOrderDeliveryOptionsAction(
        Request $request,
        int $orderId,
        QuoteService $quoteService,
        PriceSurchargeService $priceSurchargeService
    ): JsonResponse {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $order = OrderQuery::create()->findPk($orderId);
            if (!$order) {
                return $this->jsonError('Order not found', 404);
            }

            $shipment = $this->getOrderShipment($order);
            $cart = $order->getCartId() ? CartQuery::create()->findPk($order->getCartId()) : null;

            // Locate or rebuild quote for the order's cart, using delivery address country.
            $deliveryAddress = $order->getOrderAddressRelatedByDeliveryOrderAddressId();
            $country = $deliveryAddress?->getCountry();
            $quote = null;

            if ($cart) {
                $quote = MyFlyingBoxQuoteQuery::create()
                    ->filterByCartId($cart->getId())
                    ->orderByCreatedAt(Criteria::DESC)
                    ->findOne();

                if (!$quote && $country) {
                    $quote = $quoteService->getQuoteForCart($cart, null, $country);
                }
            }

            if (!$quote) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'options' => [],
                        'has_relay_offers' => false,
                        'shipment' => $this->serializeShipmentSelection($shipment),
                        'locked' => $shipment !== null && $this->isShipmentLocked($shipment),
                    ],
                    'error' => null,
                ]);
            }

            $dbOffers = MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quote->getId())
                ->joinWithMyFlyingBoxService()
                ->orderByTotalPriceInCents(Criteria::ASC)
                ->find();

            $options = [];
            $hasRelayOffers = false;
            $logoProvider = new CarrierLogoProvider();

            foreach ($dbOffers as $offer) {
                $service = $offer->getMyFlyingBoxService();
                if (!$service || !$service->getActive()) {
                    continue;
                }

                $isRelay = (bool) $service->getRelayDelivery();
                if ($isRelay) {
                    $hasRelayOffers = true;
                }

                $price = $priceSurchargeService->apply($offer->getTotalPriceInCents() / 100);
                $carrierCode = $service->getCarrierCode();

                $options[] = [
                    'id' => $offer->getId(),
                    'service_id' => $service->getId(),
                    'service_code' => $service->getCode(),
                    'service_name' => $service->getName(),
                    'carrier_code' => $carrierCode,
                    'carrier_logo' => $logoProvider->getLogoUrl($carrierCode),
                    'price' => $price,
                    'price_formatted' => number_format($price, 2, ',', ' ') . ' €',
                    'delivery_days' => $offer->getDeliveryDays(),
                    'relay_delivery' => $isRelay,
                    'api_offer_uuid' => $offer->getApiOfferUuid(),
                ];
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'options' => $options,
                    'has_relay_offers' => $hasRelayOffers,
                    'quote_id' => $quote->getId(),
                    'shipment' => $this->serializeShipmentSelection($shipment),
                    'locked' => $shipment !== null && $this->isShipmentLocked($shipment),
                ],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MyFlyingBox BO: listOrderDeliveryOptions error', [
                'order_id' => $orderId,
                'exception' => $e,
            ]);

            return $this->jsonError('Failed to list delivery options', 500);
        }
    }

    /**
     * BO — fetch live relay points for the selected offer.
     *
     * No cache: always queries the carrier API via getDeliveryLocations().
     * The offer must belong to a quote attached to the order's cart.
     */
    public function listOrderRelayPointsAction(
        Request $request,
        int $orderId,
        LceApiService $apiService
    ): JsonResponse {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $order = OrderQuery::create()->findPk($orderId);
            if (!$order) {
                return $this->jsonError('Order not found', 404);
            }

            $offerId = (int) $request->query->get('offer_id', 0);
            $query = trim((string) $request->query->get('query', ''));

            if ($offerId <= 0) {
                return $this->jsonError('Missing offer_id', 400);
            }

            $offer = MyFlyingBoxOfferQuery::create()
                ->filterById($offerId)
                ->useMyFlyingBoxServiceQuery()
                    ->filterByRelayDelivery(true)
                ->endUse()
                ->findOne();

            if (!$offer || !$offer->getApiOfferUuid()) {
                return $this->jsonError('Offer not found or is not a relay offer', 404);
            }

            // Tie the offer to the order's cart so a stale offer_id can't leak relays.
            $quote = MyFlyingBoxQuoteQuery::create()
                ->filterById($offer->getQuoteId())
                ->filterByCartId($order->getCartId())
                ->findOne();

            if (!$quote) {
                return $this->jsonError('Offer does not belong to this order', 403);
            }

            // Pull country from delivery address; default FR.
            $countryCode = 'FR';
            $deliveryAddress = $order->getOrderAddressRelatedByDeliveryOrderAddressId();
            if ($deliveryAddress) {
                $country = $deliveryAddress->getCountry();
                if ($country) {
                    $countryCode = $country->getIsoalpha2();
                }
            }

            // Use the explicit search query when provided, otherwise fall back to the address.
            $params = ['country' => $countryCode];
            if ($query !== '') {
                if (preg_match('/\b([A-Z0-9]{2,10}(?:[\s\-][A-Z0-9]{2,5})?)\b/i', $query, $matches)) {
                    $params['postal_code'] = $matches[1];
                }
                $params['city'] = trim((string) preg_replace('/\d/', '', $query));
                $params['street'] = '';
            } elseif ($deliveryAddress) {
                $params['postal_code'] = $deliveryAddress->getZipcode() ?? '';
                $params['city'] = $deliveryAddress->getCity() ?? '';
                $params['street'] = $deliveryAddress->getAddress1() ?? '';
            }

            $response = $apiService->getDeliveryLocations($offer->getApiOfferUuid(), $params);
            $locations = $response['data'] ?? $response['locations'] ?? [];

            $relays = [];
            foreach ($locations as $location) {
                $relays[] = [
                    'code' => $location['code'] ?? '',
                    'name' => $location['company'] ?? $location['name'] ?? '',
                    'street' => $location['street'] ?? '',
                    'city' => $location['city'] ?? '',
                    'postal_code' => $location['postal_code'] ?? '',
                    'country' => $location['country'] ?? $countryCode,
                    'latitude' => $location['latitude'] ?? null,
                    'longitude' => $location['longitude'] ?? null,
                    'distance' => isset($location['distance']) ? round($location['distance'] / 1000, 1) : null,
                    'opening_hours' => $location['opening_hours'] ?? [],
                ];
            }

            return new JsonResponse([
                'success' => true,
                'data' => ['relays' => $relays],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MyFlyingBox BO: listOrderRelayPoints error', [
                'order_id' => $orderId,
                'exception' => $e,
            ]);

            return $this->jsonError('Failed to load relay points', 500);
        }
    }

    /**
     * BO — persist the merchant's delivery option (and relay) choice for an order.
     *
     * Refuses changes when the shipment is `booked` or higher (409).
     * Updates the MyFlyingBoxShipment row, the cart relay, and writes a
     * shipment event for audit. The offer choice is also stored in session
     * under `mfb_selected_offer_id` so any downstream logic that listens to
     * the session-based key keeps working.
     */
    public function selectOrderDeliveryOptionAction(
        Request $request,
        int $orderId,
        ShipmentLockGuard $lockGuard,
        ShipmentService $shipmentService
    ): JsonResponse {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        if (null !== $csrfError = $this->checkCsrfToken($request)) {
            return new JsonResponse([
                'success' => false,
                'error' => $csrfError->getStatusCode() === 403 ? 'csrf' : 'csrf',
            ], $csrfError->getStatusCode());
        }

        try {
            $order = OrderQuery::create()->findPk($orderId);
            if (!$order) {
                return $this->jsonError('Order not found', 404);
            }

            $payload = json_decode((string) $request->getContent(), true);
            if (!is_array($payload)) {
                $payload = $request->request->all();
            }

            $offerId = (int) ($payload['offer_id'] ?? 0);
            $relayCode = isset($payload['relay_code']) ? (string) $payload['relay_code'] : null;

            if ($offerId <= 0) {
                return $this->jsonError('Missing offer_id', 400);
            }

            $offer = MyFlyingBoxOfferQuery::create()
                ->filterById($offerId)
                ->joinWithMyFlyingBoxService()
                ->findOne();

            if (!$offer) {
                return $this->jsonError('Offer not found', 404);
            }

            $quote = MyFlyingBoxQuoteQuery::create()
                ->filterById($offer->getQuoteId())
                ->filterByCartId($order->getCartId())
                ->findOne();

            if (!$quote) {
                return $this->jsonError('Offer does not belong to this order', 403);
            }

            $service = $offer->getMyFlyingBoxService();
            if (!$service) {
                return $this->jsonError('Offer service missing', 500);
            }

            $isRelay = (bool) $service->getRelayDelivery();
            if ($isRelay && empty($relayCode)) {
                return $this->jsonError(
                    Translator::getInstance()->trans('Please select a relay point for this delivery.', [], MyFlyingBox::DOMAIN_NAME),
                    400
                );
            }

            $shipment = $this->getOrderShipment($order);

            if ($lockGuard->isLocked($shipment)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => Translator::getInstance()->trans(
                        'This shipment has already been booked. Cancel the booking before changing the delivery option.',
                        [],
                        MyFlyingBox::DOMAIN_NAME
                    ),
                ], 409);
            }

            // Create a pending shipment row if none exists yet.
            if (!$shipment) {
                $shipment = $shipmentService->createShipmentFromOrder($order, $service->getId(), $offer->getApiOfferUuid());
                if (!$shipment) {
                    return $this->jsonError('Failed to initialize shipment', 500);
                }
            }

            $shipment->setServiceId($service->getId());
            $shipment->setApiOfferUuid($offer->getApiOfferUuid());
            $shipment->setApiQuoteUuid($quote->getApiQuoteUuid());

            // Wipe any previous relay info; re-set when we have a relay choice.
            $shipment->setRelayDeliveryCode(null);
            $shipment->setRelayName(null);
            $shipment->setRelayStreet(null);
            $shipment->setRelayCity(null);
            $shipment->setRelayPostalCode(null);
            $shipment->setRelayCountry(null);

            if ($isRelay) {
                $shipment->setRelayDeliveryCode($relayCode);
                $shipment->setRelayName((string) ($payload['relay_name'] ?? ''));
                $shipment->setRelayStreet((string) ($payload['relay_street'] ?? ''));
                $shipment->setRelayCity((string) ($payload['relay_city'] ?? ''));
                $shipment->setRelayPostalCode((string) ($payload['relay_postal_code'] ?? ''));
                $shipment->setRelayCountry((string) ($payload['relay_country'] ?? ''));

                // Mirror the choice on cart relay so downstream consumers stay in sync.
                if ($order->getCartId()) {
                    $cartRelay = MyFlyingBoxCartRelayQuery::create()
                        ->filterByCartId($order->getCartId())
                        ->findOne();

                    if (!$cartRelay) {
                        $cartRelay = new MyFlyingBoxCartRelay();
                        $cartRelay->setCartId($order->getCartId());
                    }

                    $cartRelay->setRelayCode($relayCode);
                    $cartRelay->setRelayName((string) ($payload['relay_name'] ?? ''));
                    $cartRelay->setRelayStreet((string) ($payload['relay_street'] ?? ''));
                    $cartRelay->setRelayCity((string) ($payload['relay_city'] ?? ''));
                    $cartRelay->setRelayPostalCode((string) ($payload['relay_postal_code'] ?? ''));
                    $cartRelay->setRelayCountry((string) ($payload['relay_country'] ?? ''));
                    $cartRelay->save();
                }
            } elseif ($order->getCartId()) {
                // Switching from a relay offer to a non-relay one: drop the cart relay.
                MyFlyingBoxCartRelayQuery::create()
                    ->filterByCartId($order->getCartId())
                    ->delete();
            }

            $shipment->save();

            // Mirror selection in session so existing consumers (e.g. getPostage) align.
            $request->getSession()->set('mfb_selected_offer_id', $offer->getId());

            $shipmentService->createShipmentEvent(
                $shipment->getId(),
                'BO_DELIVERY_OPTION_CHANGED',
                sprintf(
                    'Mode de livraison modifié en BO : %s (%s)%s',
                    $service->getName(),
                    $service->getCode(),
                    $isRelay && $relayCode ? ' — relais ' . $relayCode : ''
                ),
                new \DateTime()
            );

            $this->logger->info('MyFlyingBox BO: delivery option updated', [
                'order_id' => $orderId,
                'shipment_id' => $shipment->getId(),
                'offer_id' => $offer->getId(),
                'service_code' => $service->getCode(),
                'relay_code' => $isRelay ? $relayCode : null,
            ]);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'shipment' => $this->serializeShipmentSelection($shipment),
                ],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MyFlyingBox BO: selectOrderDeliveryOption error', [
                'order_id' => $orderId,
                'exception' => $e,
            ]);

            return $this->jsonError('Failed to persist delivery selection', 500);
        }
    }

    private function getOrderShipment(Order $order): ?MyFlyingBoxShipment
    {
        return MyFlyingBoxShipmentQuery::create()
            ->filterByOrderId($order->getId())
            ->filterByIsReturn(false)
            ->orderById(Criteria::DESC)
            ->findOne();
    }

    private function isShipmentLocked(MyFlyingBoxShipment $shipment): bool
    {
        $lockGuard = new ShipmentLockGuard();

        return $lockGuard->isLocked($shipment);
    }

    private function serializeShipmentSelection(?MyFlyingBoxShipment $shipment): ?array
    {
        if (!$shipment) {
            return null;
        }

        return [
            'id' => $shipment->getId(),
            'status' => $shipment->getStatus(),
            'service_id' => $shipment->getServiceId(),
            'api_offer_uuid' => $shipment->getApiOfferUuid(),
            'relay' => $shipment->getRelayDeliveryCode() ? [
                'code' => $shipment->getRelayDeliveryCode(),
                'name' => $shipment->getRelayName(),
                'street' => $shipment->getRelayStreet(),
                'city' => $shipment->getRelayCity(),
                'postal_code' => $shipment->getRelayPostalCode(),
                'country' => $shipment->getRelayCountry(),
            ] : null,
        ];
    }

    private function jsonError(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'data' => null,
            'error' => $message,
        ], $status);
    }
}
