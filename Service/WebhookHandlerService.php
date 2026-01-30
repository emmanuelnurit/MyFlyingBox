<?php

namespace MyFlyingBox\Service;

use MyFlyingBox\Model\MyFlyingBoxShipment;
use MyFlyingBox\Model\MyFlyingBoxShipmentEvent;
use MyFlyingBox\Model\MyFlyingBoxShipmentEventQuery;
use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use Psr\Log\LoggerInterface;

/**
 * Service for handling incoming webhooks from MyFlyingBox/LCE API
 */
class WebhookHandlerService
{
    private LoggerInterface $logger;
    private ?TrackingNotificationService $notificationService = null;

    /**
     * Map of API statuses to internal shipment statuses
     */
    private const STATUS_MAP = [
        'created' => ShipmentService::STATUS_BOOKED,
        'booked' => ShipmentService::STATUS_BOOKED,
        'picked_up' => ShipmentService::STATUS_SHIPPED,
        'in_transit' => ShipmentService::STATUS_SHIPPED,
        'out_for_delivery' => ShipmentService::STATUS_SHIPPED,
        'delivered' => ShipmentService::STATUS_DELIVERED,
        'cancelled' => ShipmentService::STATUS_CANCELLED,
        'returned' => ShipmentService::STATUS_CANCELLED,
        'exception' => ShipmentService::STATUS_SHIPPED, // Keep as shipped but log
    ];

    /**
     * Cache for processed event IDs (to avoid duplicates within same request)
     * @var array<string, bool>
     */
    private array $processedEvents = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Set the notification service for email notifications
     */
    public function setNotificationService(TrackingNotificationService $notificationService): void
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Check if an event has already been processed (idempotence)
     */
    public function isDuplicateEvent(string $eventId): bool
    {
        // Check in-memory cache first
        if (isset($this->processedEvents[$eventId])) {
            return true;
        }

        // Check if we have a recent event with this ID in database
        // We check by looking for events created in the last 24 hours with matching content hash
        // This is a simple implementation - for production, consider a dedicated webhook_log table

        return false; // For now, let the handleTrackingEvent method handle deduplication
    }

    /**
     * Handle a tracking event from the webhook
     *
     * @param array $data The webhook payload
     * @param string $eventId Unique event ID for idempotence
     * @return bool True if the event was processed, false if no action was taken
     */
    public function handleTrackingEvent(array $data, string $eventId): bool
    {
        // Mark as processed
        $this->processedEvents[$eventId] = true;

        // Extract order ID from various possible formats
        $lceOrderId = $data['order_id']
            ?? $data['lce_order_id']
            ?? $data['api_order_uuid']
            ?? $data['order_uuid']
            ?? null;

        // Also check nested data structures
        if (!$lceOrderId && isset($data['data']['id'])) {
            $lceOrderId = $data['data']['id'];
        }
        if (!$lceOrderId && isset($data['order']['id'])) {
            $lceOrderId = $data['order']['id'];
        }

        if (!$lceOrderId) {
            $this->logger->warning('Webhook missing order ID', [
                'event_id' => $eventId,
                'payload_keys' => array_keys($data),
            ]);
            return false;
        }

        // Find the shipment by LCE order UUID
        $shipment = $this->findShipmentByLceOrderId($lceOrderId);

        if (!$shipment) {
            $this->logger->info('Shipment not found for webhook', [
                'event_id' => $eventId,
                'lce_order_id' => $lceOrderId,
            ]);
            // Not an error - might be from another system or old shipment
            return false;
        }

        $this->logger->info('Processing webhook for shipment', [
            'event_id' => $eventId,
            'shipment_id' => $shipment->getId(),
            'order_id' => $shipment->getOrderId(),
            'lce_order_id' => $lceOrderId,
        ]);

        // Update shipment status if provided
        $statusUpdated = $this->updateShipmentStatus($shipment, $data);

        // Process tracking events if provided
        $eventsAdded = $this->processTrackingEvents($shipment, $data);

        // Update tracking number if provided
        $trackingUpdated = $this->updateTrackingNumber($shipment, $data);

        return $statusUpdated || $eventsAdded > 0 || $trackingUpdated;
    }

    /**
     * Find a shipment by its LCE order UUID
     */
    public function findShipmentByLceOrderId(string $lceOrderId): ?MyFlyingBoxShipment
    {
        return MyFlyingBoxShipmentQuery::create()
            ->filterByApiOrderUuid($lceOrderId)
            ->findOne();
    }

    /**
     * Update shipment status based on webhook data
     */
    private function updateShipmentStatus(MyFlyingBoxShipment $shipment, array $data): bool
    {
        $apiStatus = $data['status']
            ?? $data['state']
            ?? $data['data']['state'] ?? null;

        if (!$apiStatus) {
            return false;
        }

        $apiStatus = strtolower($apiStatus);
        $newStatus = self::STATUS_MAP[$apiStatus] ?? null;

        if (!$newStatus) {
            $this->logger->debug('Unknown API status, no mapping found', [
                'shipment_id' => $shipment->getId(),
                'api_status' => $apiStatus,
            ]);
            return false;
        }

        $currentStatus = $shipment->getStatus();

        // Don't downgrade status (e.g., don't go from delivered back to shipped)
        if (!$this->shouldUpdateStatus($currentStatus, $newStatus)) {
            $this->logger->debug('Status update skipped (would be downgrade)', [
                'shipment_id' => $shipment->getId(),
                'current_status' => $currentStatus,
                'new_status' => $newStatus,
            ]);
            return false;
        }

        $shipment->setStatus($newStatus);
        $shipment->save();

        $this->logger->info('Shipment status updated via webhook', [
            'shipment_id' => $shipment->getId(),
            'old_status' => $currentStatus,
            'new_status' => $newStatus,
            'api_status' => $apiStatus,
        ]);

        // Send notification email for status change
        $this->sendStatusNotification($shipment, $currentStatus, $newStatus);

        return true;
    }

    /**
     * Send notification email for status change
     */
    private function sendStatusNotification(MyFlyingBoxShipment $shipment, string $previousStatus, string $newStatus): void
    {
        if (!$this->notificationService) {
            return;
        }

        try {
            $this->notificationService->sendStatusChangeNotification($shipment, $previousStatus, $newStatus);
        } catch (\Exception $e) {
            // Don't let notification failures break the webhook processing
            $this->logger->error('Failed to send status notification from webhook: ' . $e->getMessage());
        }
    }

    /**
     * Check if status should be updated based on progression order
     */
    private function shouldUpdateStatus(string $currentStatus, string $newStatus): bool
    {
        $statusOrder = [
            ShipmentService::STATUS_PENDING => 1,
            ShipmentService::STATUS_BOOKED => 2,
            ShipmentService::STATUS_SHIPPED => 3,
            ShipmentService::STATUS_DELIVERED => 4,
            ShipmentService::STATUS_CANCELLED => 5, // Terminal status
        ];

        $currentOrder = $statusOrder[$currentStatus] ?? 0;
        $newOrder = $statusOrder[$newStatus] ?? 0;

        // Allow update if new status is equal or higher in order
        // (except for cancelled which should only come from explicit cancellation)
        if ($currentStatus === ShipmentService::STATUS_CANCELLED) {
            return false; // Can't change from cancelled
        }

        if ($newStatus === ShipmentService::STATUS_CANCELLED) {
            return true; // Can always cancel
        }

        return $newOrder >= $currentOrder;
    }

    /**
     * Process tracking events from webhook payload
     */
    private function processTrackingEvents(MyFlyingBoxShipment $shipment, array $data): int
    {
        // Extract events from various possible locations in payload
        $events = $this->extractTrackingEvents($data);

        if (empty($events)) {
            return 0;
        }

        // Get parcel for this shipment
        $parcel = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->findOne();

        $parcelId = $parcel?->getId();
        $eventsAdded = 0;

        foreach ($events as $apiEvent) {
            if ($this->addTrackingEvent($shipment, $parcelId, $apiEvent)) {
                $eventsAdded++;
            }
        }

        if ($eventsAdded > 0) {
            $this->logger->info('Tracking events added via webhook', [
                'shipment_id' => $shipment->getId(),
                'events_added' => $eventsAdded,
            ]);
        }

        return $eventsAdded;
    }

    /**
     * Extract tracking events from various payload formats
     */
    private function extractTrackingEvents(array $data): array
    {
        $events = [];

        // Format 1: events[] at root level
        if (!empty($data['events']) && is_array($data['events'])) {
            return $data['events'];
        }

        // Format 2: data.events[]
        if (!empty($data['data']['events']) && is_array($data['data']['events'])) {
            return $data['data']['events'];
        }

        // Format 3: tracking[] at root level
        if (!empty($data['tracking']) && is_array($data['tracking'])) {
            return $data['tracking'];
        }

        // Format 4: data.parcels[].events[] (LCE API v2 format)
        if (!empty($data['data']['parcels']) && is_array($data['data']['parcels'])) {
            foreach ($data['data']['parcels'] as $parcel) {
                if (!empty($parcel['events']) && is_array($parcel['events'])) {
                    $events = array_merge($events, $parcel['events']);
                }
            }
        }

        // Format 5: parcels[].events[] at root
        if (!empty($data['parcels']) && is_array($data['parcels'])) {
            foreach ($data['parcels'] as $parcel) {
                if (!empty($parcel['events']) && is_array($parcel['events'])) {
                    $events = array_merge($events, $parcel['events']);
                }
            }
        }

        return $events;
    }

    /**
     * Add a single tracking event, checking for duplicates
     */
    private function addTrackingEvent(MyFlyingBoxShipment $shipment, ?int $parcelId, array $apiEvent): bool
    {
        // Extract event code
        $eventCode = $apiEvent['code'] ?? $apiEvent['status'] ?? $apiEvent['event'] ?? 'unknown';

        // Extract event date
        $eventDate = null;
        $dateValue = $apiEvent['happened_at']
            ?? $apiEvent['date']
            ?? $apiEvent['datetime']
            ?? $apiEvent['timestamp']
            ?? null;

        if ($dateValue) {
            try {
                $eventDate = new \DateTime($dateValue);
            } catch (\Exception $e) {
                $this->logger->debug('Invalid event date format', [
                    'date_value' => $dateValue,
                ]);
                $eventDate = new \DateTime();
            }
        }

        // Extract event label (may be string or array with translations)
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
        } elseif (isset($apiEvent['description'])) {
            $eventLabel = $apiEvent['description'];
        }

        // Extract location
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

        // Check for duplicate event
        $existingEvent = MyFlyingBoxShipmentEventQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->filterByEventCode($eventCode)
            ->filterByEventDate($eventDate)
            ->findOne();

        if ($existingEvent) {
            return false; // Already exists, skip
        }

        // Create new event
        $event = new MyFlyingBoxShipmentEvent();
        $event->setShipmentId($shipment->getId());
        $event->setParcelId($parcelId);
        $event->setEventCode($eventCode);
        $event->setEventLabel($eventLabel);
        $event->setEventDate($eventDate);
        $event->setLocation($location);
        $event->save();

        $this->logger->debug('Tracking event created', [
            'shipment_id' => $shipment->getId(),
            'event_code' => $eventCode,
            'event_date' => $eventDate?->format('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Update tracking number from webhook data
     */
    private function updateTrackingNumber(MyFlyingBoxShipment $shipment, array $data): bool
    {
        $trackingNumber = $data['tracking_number']
            ?? $data['data']['tracking_number']
            ?? null;

        if (!$trackingNumber) {
            return false;
        }

        // Update the parcel's tracking number
        $parcel = MyFlyingBoxParcelQuery::create()
            ->filterByShipmentId($shipment->getId())
            ->findOne();

        if (!$parcel) {
            return false;
        }

        // Only update if different
        if ($parcel->getTrackingNumber() === $trackingNumber) {
            return false;
        }

        $parcel->setTrackingNumber($trackingNumber);
        $parcel->save();

        $this->logger->info('Tracking number updated via webhook', [
            'shipment_id' => $shipment->getId(),
            'tracking_number' => $trackingNumber,
        ]);

        return true;
    }

    /**
     * Validate webhook signature using HMAC-SHA256
     *
     * @param string $payload Raw request payload
     * @param string $signature Signature from header
     * @param string $secret Webhook secret
     * @return bool True if valid
     */
    public function validateSignature(string $payload, string $signature, string $secret): bool
    {
        if (empty($secret)) {
            // No secret configured, skip validation
            return true;
        }

        if (empty($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // Normalize signature (remove prefix if present)
        $normalizedSignature = $signature;
        if (strpos($signature, '=') !== false) {
            $parts = explode('=', $signature, 2);
            $normalizedSignature = $parts[1] ?? $signature;
        }

        return hash_equals($expectedSignature, $normalizedSignature);
    }
}
