<?php

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\Model\MyFlyingBoxShipmentEvent;
use MyFlyingBox\Model\MyFlyingBoxShipmentEventQuery;
use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;

/**
 * Service for tracking shipments and parcels
 */
class TrackingService
{
    private LceApiService $apiService;
    private LoggerInterface $logger;
    private ?TrackingNotificationService $notificationService = null;

    public function __construct(LceApiService $apiService, LoggerInterface $logger)
    {
        $this->apiService = $apiService;
        $this->logger = $logger;
    }

    /**
     * Set the notification service (optional dependency for email notifications)
     */
    public function setNotificationService(TrackingNotificationService $notificationService): void
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get tracking information for an order
     */
    public function getTrackingForOrder(int $orderId): array
    {
        try {
            $shipments = MyFlyingBoxShipmentQuery::create()
                ->filterByOrderId($orderId)
                ->find();

            $trackingInfo = [];

            foreach ($shipments as $shipment) {
                $parcels = MyFlyingBoxParcelQuery::create()
                    ->filterByShipmentId($shipment->getId())
                    ->find();

                $parcelTracking = [];
                foreach ($parcels as $parcel) {
                    $parcelData = [
                        'parcel_id' => $parcel->getId(),
                        'tracking_number' => $parcel->getTrackingNumber(),
                        'tracking_url' => null,
                        'events' => [],
                    ];

                    // Get tracking URL
                    if ($parcel->getTrackingNumber() && $shipment->getServiceId()) {
                        $service = MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId());
                        if ($service && $service->getTrackingUrl()) {
                            $parcelData['tracking_url'] = str_replace(
                                '{tracking_number}',
                                $parcel->getTrackingNumber(),
                                $service->getTrackingUrl()
                            );
                        }
                    }

                    // Get tracking events from local database
                    $events = $this->getLocalTrackingEvents($shipment->getId(), $parcel->getId());
                    $parcelData['events'] = $events;

                    $parcelTracking[] = $parcelData;
                }

                // Get shipment-level events (events without parcel_id)
                $shipmentEvents = $this->getShipmentLevelEvents($shipment->getId());

                $trackingInfo[] = [
                    'shipment_id' => $shipment->getId(),
                    'status' => $shipment->getStatus(),
                    'is_return' => $shipment->getIsReturn(),
                    'service_name' => $this->getServiceName($shipment->getServiceId()),
                    'parcels' => $parcelTracking,
                    'events' => $shipmentEvents,
                ];
            }

            return $trackingInfo;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get tracking info: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get tracking events from local database
     */
    private function getLocalTrackingEvents(int $shipmentId, ?int $parcelId = null): array
    {
        try {
            $query = MyFlyingBoxShipmentEventQuery::create()
                ->filterByShipmentId($shipmentId)
                ->orderByEventDate(Criteria::DESC);

            if ($parcelId) {
                $query->filterByParcelId($parcelId);
            }

            $dbEvents = $query->find();

            $events = [];
            foreach ($dbEvents as $event) {
                $events[] = [
                    'date' => $event->getEventDate()?->format('d/m/Y H:i'),
                    'status' => $event->getEventCode(),
                    'message' => $event->getEventLabel(),
                    'location' => $event->getLocation(),
                ];
            }

            return $events;

        } catch (\Exception $e) {
            $this->logger->debug('Could not get local tracking events: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get shipment-level events (events without parcel_id)
     */
    private function getShipmentLevelEvents(int $shipmentId): array
    {
        try {
            $dbEvents = MyFlyingBoxShipmentEventQuery::create()
                ->filterByShipmentId($shipmentId)
                ->filterByParcelId(null)
                ->orderByEventDate(Criteria::DESC)
                ->find();

            $events = [];
            foreach ($dbEvents as $event) {
                $events[] = [
                    'date' => $event->getEventDate()?->format('d/m/Y H:i'),
                    'status' => $event->getEventCode(),
                    'message' => $event->getEventLabel(),
                    'location' => $event->getLocation(),
                ];
            }

            return $events;

        } catch (\Exception $e) {
            $this->logger->debug('Could not get shipment level events: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sync tracking events from API and store them locally
     */
    public function syncTrackingEvents(int $shipmentId): bool
    {
        try {
            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);

            if (!$shipment || !$shipment->getApiOrderUuid()) {
                $this->logger->debug('No API order UUID for shipment ' . $shipmentId);
                return false;
            }

            $trackingEvents = [];
            $orderUuid = $shipment->getApiOrderUuid();

            // Try dedicated tracking endpoint first
            try {
                $response = $this->apiService->getOrderTracking($orderUuid);
                $this->logger->debug('Tracking API response', ['response' => json_encode($response)]);
                $trackingEvents = $this->extractEventsFromResponse($response);
            } catch (\Exception $e) {
                $this->logger->debug('Tracking endpoint failed: ' . $e->getMessage());
            }

            // Fallback: try to get events from order endpoint
            if (empty($trackingEvents)) {
                try {
                    $orderResponse = $this->apiService->getOrder($orderUuid);
                    $this->logger->debug('Order API response for tracking', ['response' => json_encode($orderResponse)]);
                    $trackingEvents = $this->extractEventsFromResponse($orderResponse);
                } catch (\Exception $e) {
                    $this->logger->debug('Order endpoint for tracking failed: ' . $e->getMessage());
                }
            }

            if (empty($trackingEvents)) {
                $this->logger->debug('No tracking events found for shipment ' . $shipmentId);
                return false;
            }

            // Get first parcel for this shipment
            $parcel = MyFlyingBoxParcelQuery::create()
                ->filterByShipmentId($shipmentId)
                ->findOne();

            $parcelId = $parcel?->getId();

            // Store events locally
            $storedCount = 0;
            foreach ($trackingEvents as $apiEvent) {
                // Event code
                $eventCode = $apiEvent['code'] ?? $apiEvent['status'] ?? 'unknown';

                // Event date (LCE uses 'happened_at')
                $eventDate = null;
                $dateValue = $apiEvent['happened_at'] ?? $apiEvent['date'] ?? $apiEvent['datetime'] ?? null;
                if ($dateValue) {
                    try {
                        $eventDate = new \DateTime($dateValue);
                    } catch (\Exception $e) {
                        $eventDate = new \DateTime();
                    }
                }

                // Event label (LCE uses object with language keys)
                $eventLabel = '';
                if (isset($apiEvent['label'])) {
                    if (is_array($apiEvent['label'])) {
                        // Prefer French, fallback to English
                        $eventLabel = $apiEvent['label']['fr'] ?? $apiEvent['label']['en'] ?? '';
                    } else {
                        $eventLabel = $apiEvent['label'];
                    }
                } elseif (isset($apiEvent['message'])) {
                    $eventLabel = $apiEvent['message'];
                }

                // Location (LCE uses object with city, country, etc.)
                $location = '';
                if (isset($apiEvent['location'])) {
                    if (is_array($apiEvent['location'])) {
                        $locationParts = [];
                        if (!empty($apiEvent['location']['city'])) {
                            $locationParts[] = $apiEvent['location']['city'];
                        }
                        if (!empty($apiEvent['location']['country'])) {
                            $locationParts[] = $apiEvent['location']['country'];
                        }
                        $location = implode(', ', $locationParts);
                    } else {
                        $location = $apiEvent['location'];
                    }
                }

                // Check if this event already exists (avoid duplicates)
                $existingEvent = MyFlyingBoxShipmentEventQuery::create()
                    ->filterByShipmentId($shipmentId)
                    ->filterByEventCode($eventCode)
                    ->filterByEventDate($eventDate)
                    ->findOne();

                if ($existingEvent) {
                    continue;
                }

                // Create new event
                $event = new MyFlyingBoxShipmentEvent();
                $event->setShipmentId($shipmentId);
                $event->setParcelId($parcelId);
                $event->setEventCode($eventCode);
                $event->setEventLabel($eventLabel);
                $event->setEventDate($eventDate);
                $event->setLocation($location);
                $event->save();
                $storedCount++;

                $this->logger->info('Stored tracking event', [
                    'shipment_id' => $shipmentId,
                    'event_code' => $eventCode,
                ]);
            }

            return $storedCount > 0;

        } catch (\Exception $e) {
            $this->logger->error('Failed to sync tracking events: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract tracking events from various API response formats
     */
    private function extractEventsFromResponse(array $response): array
    {
        $events = [];

        // Format 1: LCE API v2 - data[].events[] (parcels with events)
        if (!empty($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $item) {
                if (!empty($item['events']) && is_array($item['events'])) {
                    $events = array_merge($events, $item['events']);
                }
                // Check for parcels.events
                if (!empty($item['parcels']) && is_array($item['parcels'])) {
                    foreach ($item['parcels'] as $parcel) {
                        if (!empty($parcel['events']) && is_array($parcel['events'])) {
                            $events = array_merge($events, $parcel['events']);
                        }
                    }
                }
            }
        }

        // Format 2: data.events[] (events directly in data)
        if (empty($events) && !empty($response['data']['events']) && is_array($response['data']['events'])) {
            $events = $response['data']['events'];
        }

        // Format 3: data.parcels[].events[]
        if (empty($events) && !empty($response['data']['parcels']) && is_array($response['data']['parcels'])) {
            foreach ($response['data']['parcels'] as $parcel) {
                if (!empty($parcel['events']) && is_array($parcel['events'])) {
                    $events = array_merge($events, $parcel['events']);
                }
            }
        }

        // Format 4: tracking[] at root
        if (empty($events) && !empty($response['tracking']) && is_array($response['tracking'])) {
            $events = $response['tracking'];
        }

        // Format 5: events[] at root
        if (empty($events) && !empty($response['events']) && is_array($response['events'])) {
            $events = $response['events'];
        }

        // Format 6: parcels[].events[] at root
        if (empty($events) && !empty($response['parcels']) && is_array($response['parcels'])) {
            foreach ($response['parcels'] as $parcel) {
                if (!empty($parcel['events']) && is_array($parcel['events'])) {
                    $events = array_merge($events, $parcel['events']);
                }
            }
        }

        $this->logger->debug('Extracted tracking events', ['count' => count($events)]);

        return $events;
    }

    /**
     * Get service name by ID
     */
    private function getServiceName(?int $serviceId): string
    {
        if (!$serviceId) {
            return '';
        }

        try {
            $service = MyFlyingBoxServiceQuery::create()->findPk($serviceId);
            return $service ? $service->getName() : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get tracking URL for a parcel
     */
    public function getTrackingUrl(int $parcelId): ?string
    {
        try {
            $parcel = MyFlyingBoxParcelQuery::create()->findPk($parcelId);

            if (!$parcel || !$parcel->getTrackingNumber()) {
                return null;
            }

            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($parcel->getShipmentId());

            if (!$shipment || !$shipment->getServiceId()) {
                return null;
            }

            $service = MyFlyingBoxServiceQuery::create()->findPk($shipment->getServiceId());

            if (!$service || !$service->getTrackingUrl()) {
                return null;
            }

            return str_replace(
                '{tracking_number}',
                $parcel->getTrackingNumber(),
                $service->getTrackingUrl()
            );

        } catch (\Exception $e) {
            $this->logger->error('Failed to get tracking URL: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update shipment status based on tracking
     */
    public function syncTrackingStatus(int $shipmentId): bool
    {
        try {
            $shipment = MyFlyingBoxShipmentQuery::create()->findPk($shipmentId);

            if (!$shipment || !$shipment->getApiOrderUuid()) {
                return false;
            }

            // First sync tracking events to local database
            $eventsStored = $this->syncTrackingEvents($shipmentId);

            // Get order details to check status
            $response = $this->apiService->getOrder($shipment->getApiOrderUuid());

            // LCE API v2: status is in data.state
            $orderData = $response['data'] ?? $response;
            $apiStatus = $orderData['state'] ?? $orderData['status'] ?? null;

            if (empty($apiStatus)) {
                // Events were stored but no status change
                return $eventsStored;
            }

            // Map API status to internal status
            $statusMap = [
                'created' => ShipmentService::STATUS_BOOKED,
                'booked' => ShipmentService::STATUS_BOOKED,
                'picked_up' => ShipmentService::STATUS_SHIPPED,
                'in_transit' => ShipmentService::STATUS_SHIPPED,
                'delivered' => ShipmentService::STATUS_DELIVERED,
                'cancelled' => ShipmentService::STATUS_CANCELLED,
            ];

            $apiStatus = strtolower($apiStatus);
            if (isset($statusMap[$apiStatus]) && $shipment->getStatus() !== $statusMap[$apiStatus]) {
                $previousStatus = $shipment->getStatus();
                $newStatus = $statusMap[$apiStatus];

                $shipment->setStatus($newStatus);
                $shipment->save();

                $this->logger->info('Shipment status updated', [
                    'shipment_id' => $shipmentId,
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                ]);

                // Send notification email for status change
                $this->sendStatusNotification($shipment, $previousStatus, $newStatus);

                return true;
            }

            return $eventsStored;

        } catch (\Exception $e) {
            $this->logger->error('Failed to sync tracking status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification email for status change
     *
     * @param \MyFlyingBox\Model\MyFlyingBoxShipment $shipment
     * @param string $previousStatus
     * @param string $newStatus
     */
    private function sendStatusNotification($shipment, string $previousStatus, string $newStatus): void
    {
        if (!$this->notificationService) {
            $this->logger->debug('Notification service not configured, skipping email');
            return;
        }

        try {
            $this->notificationService->sendStatusChangeNotification($shipment, $previousStatus, $newStatus);
        } catch (\Exception $e) {
            // Don't let notification failures break the tracking sync
            $this->logger->error('Failed to send status notification: ' . $e->getMessage());
        }
    }
}
