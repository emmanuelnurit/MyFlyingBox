<?php

namespace MyFlyingBox\Controller;

use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\WebhookHandlerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Front\BaseFrontController;

/**
 * Webhook controller for receiving tracking updates from MyFlyingBox/LCE API
 *
 * This endpoint is accessible without Thelia authentication as it receives
 * notifications from an external service.
 */
class WebhookController extends BaseFrontController
{
    private WebhookHandlerService $webhookHandler;
    private LoggerInterface $logger;

    public function __construct(WebhookHandlerService $webhookHandler, LoggerInterface $logger)
    {
        $this->webhookHandler = $webhookHandler;
        $this->logger = $logger;
    }

    /**
     * Handle incoming tracking webhook notifications from MyFlyingBox
     *
     * Expected payload format:
     * {
     *   "event": "tracking.updated",
     *   "order_id": "LCE-123456",
     *   "tracking_number": "1Z999AA10123456784",
     *   "status": "in_transit",
     *   "events": [
     *     {
     *       "date": "2026-01-29T10:30:00Z",
     *       "code": "IT",
     *       "label": "In transit",
     *       "location": "Paris, FR"
     *     }
     *   ]
     * }
     */
    public function trackingAction(Request $request): Response
    {
        $webhookId = $this->generateWebhookId();
        $startTime = microtime(true);

        // Log incoming request
        $this->logger->info('MyFlyingBox webhook received', [
            'webhook_id' => $webhookId,
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'content_type' => $request->headers->get('Content-Type'),
            'user_agent' => $request->headers->get('User-Agent'),
        ]);

        // Check if webhooks are enabled
        if (!$this->isWebhookEnabled()) {
            $this->logger->warning('Webhook received but webhooks are disabled', [
                'webhook_id' => $webhookId,
            ]);
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Webhooks are disabled',
            ], 503);
        }

        try {
            // Get raw payload for signature validation
            $rawPayload = $request->getContent();

            // Validate signature if configured
            $signature = $request->headers->get('X-Webhook-Signature')
                ?? $request->headers->get('X-MFB-Signature')
                ?? $request->headers->get('X-Signature');

            if (!$this->validateSignature($rawPayload, $signature, $webhookId)) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 401);
            }

            // Parse JSON payload
            $payload = $this->parsePayload($rawPayload, $webhookId);
            if ($payload === null) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Invalid payload',
                ], 400);
            }

            // Extract event ID for idempotence check
            $eventId = $this->extractEventId($request, $payload);

            // Check for duplicate webhook
            if ($this->webhookHandler->isDuplicateEvent($eventId)) {
                $this->logger->info('Duplicate webhook ignored', [
                    'webhook_id' => $webhookId,
                    'event_id' => $eventId,
                ]);
                return new JsonResponse([
                    'status' => 'already_processed',
                    'webhook_id' => $webhookId,
                ], 200);
            }

            // Process the tracking event
            $result = $this->webhookHandler->handleTrackingEvent($payload, $eventId);

            // Calculate processing time
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logger->info('Webhook processed', [
                'webhook_id' => $webhookId,
                'event_id' => $eventId,
                'success' => $result,
                'duration_ms' => round($duration, 2),
            ]);

            return new JsonResponse([
                'status' => $result ? 'processed' : 'no_action',
                'webhook_id' => $webhookId,
            ], 200);

        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Webhook validation failed', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Invalid request',
            ], 400);

        } catch (\Exception $e) {
            $this->logger->error('Webhook processing error', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 500 so the sender can retry
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Internal error',
            ], 500);
        }
    }

    /**
     * Check if webhooks are enabled in configuration
     */
    private function isWebhookEnabled(): bool
    {
        $enabled = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_WEBHOOK_ENABLED, '0');
        return $enabled === '1' || $enabled === 'true';
    }

    /**
     * Validate the webhook signature using HMAC
     */
    private function validateSignature(?string $payload, ?string $signature, string $webhookId): bool
    {
        $secret = MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_WEBHOOK_SECRET, '');

        // If no secret is configured, skip signature validation
        // This is less secure but allows for easier initial setup
        if (empty($secret)) {
            $this->logger->debug('No webhook secret configured, skipping signature validation', [
                'webhook_id' => $webhookId,
            ]);
            return true;
        }

        // If secret is configured but no signature provided, reject
        if (empty($signature)) {
            $this->logger->warning('Webhook signature missing', [
                'webhook_id' => $webhookId,
            ]);
            return false;
        }

        // Calculate expected signature
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // Handle different signature formats (with or without prefix)
        $normalizedSignature = $this->normalizeSignature($signature);

        // Timing-safe comparison to prevent timing attacks
        $valid = hash_equals($expectedSignature, $normalizedSignature);

        if (!$valid) {
            $this->logger->warning('Webhook signature invalid', [
                'webhook_id' => $webhookId,
                'expected_prefix' => substr($expectedSignature, 0, 8) . '...',
                'received_prefix' => substr($normalizedSignature, 0, 8) . '...',
            ]);
        }

        return $valid;
    }

    /**
     * Normalize signature by removing any prefix (e.g., "sha256=")
     */
    private function normalizeSignature(string $signature): string
    {
        // Handle formats like "sha256=signature" or "v1=signature"
        if (strpos($signature, '=') !== false) {
            $parts = explode('=', $signature, 2);
            return $parts[1] ?? $signature;
        }

        return $signature;
    }

    /**
     * Parse the JSON payload
     */
    private function parsePayload(string $rawPayload, string $webhookId): ?array
    {
        if (empty($rawPayload)) {
            $this->logger->warning('Empty webhook payload', [
                'webhook_id' => $webhookId,
            ]);
            return null;
        }

        $payload = json_decode($rawPayload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Invalid JSON in webhook payload', [
                'webhook_id' => $webhookId,
                'json_error' => json_last_error_msg(),
            ]);
            return null;
        }

        $this->logger->debug('Webhook payload parsed', [
            'webhook_id' => $webhookId,
            'event_type' => $payload['event'] ?? $payload['type'] ?? 'unknown',
            'order_id' => $payload['order_id'] ?? $payload['lce_order_id'] ?? 'unknown',
        ]);

        return $payload;
    }

    /**
     * Extract a unique event ID for idempotence checking
     */
    private function extractEventId(Request $request, array $payload): string
    {
        // Try to get event ID from headers
        $eventId = $request->headers->get('X-Event-Id')
            ?? $request->headers->get('X-Webhook-Id')
            ?? $request->headers->get('X-Request-Id');

        if ($eventId) {
            return $eventId;
        }

        // Try to get from payload
        if (isset($payload['event_id'])) {
            return $payload['event_id'];
        }
        if (isset($payload['id'])) {
            return (string) $payload['id'];
        }

        // Generate from content hash for deduplication
        // Include order_id, event type, and first tracking event date
        $orderId = $payload['order_id'] ?? $payload['lce_order_id'] ?? '';
        $eventType = $payload['event'] ?? $payload['type'] ?? '';
        $lastEventDate = '';

        if (!empty($payload['events']) && is_array($payload['events'])) {
            $lastEvent = end($payload['events']);
            $lastEventDate = $lastEvent['date'] ?? $lastEvent['happened_at'] ?? '';
        }

        return hash('sha256', $orderId . '|' . $eventType . '|' . $lastEventDate);
    }

    /**
     * Generate a unique webhook ID for logging/tracking
     */
    private function generateWebhookId(): string
    {
        return 'wh_' . bin2hex(random_bytes(8));
    }
}
