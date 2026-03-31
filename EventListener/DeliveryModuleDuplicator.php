<?php

namespace MyFlyingBox\EventListener;

use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\MyFlyingBox;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * This listener intercepts the API response for delivery modules
 * and duplicates MyFlyingBox to appear in both home delivery and pickup lists
 */
class DeliveryModuleDuplicator implements EventSubscriberInterface
{
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['duplicateModuleForBothModes', -10],
        ];
    }

    public function duplicateModuleForBothModes(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        // Only for OpenAPI delivery modules endpoint
        if ($request->getPathInfo() !== '/open_api/delivery/modules') {
            return;
        }

        // Check if we have both types of services
        $hasRelayServices = MyFlyingBoxServiceQuery::create()
            ->filterByRelayDelivery(true)
            ->filterByActive(true)
            ->count() > 0;

        $hasHomeDeliveryServices = MyFlyingBoxServiceQuery::create()
            ->filterByRelayDelivery(false)
            ->filterByActive(true)
            ->count() > 0;

        // Only duplicate if we have both types
        if (!$hasRelayServices || !$hasHomeDeliveryServices) {
            return;
        }

        // Decode the JSON response
        $content = $response->getContent();
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return;
        }

        // Find MyFlyingBox in the response
        $myflyingboxIndex = null;
        $myflyingboxData = null;
        $myflyingboxId = MyFlyingBox::getModuleId();

        foreach ($data as $index => $module) {
            if (isset($module['id']) && (int)$module['id'] === (int)$myflyingboxId) {
                $myflyingboxIndex = $index;
                $myflyingboxData = $module;
                break;
            }
        }

        if ($myflyingboxData === null) {
            return;
        }

        // Create two versions: one for pickup, one for delivery
        $pickupVersion = $myflyingboxData;
        $deliveryVersion = $myflyingboxData;

        // Set delivery modes
        $pickupVersion['deliveryMode'] = 'pickup';
        $deliveryVersion['deliveryMode'] = 'delivery';

        // Get all active services to filter options
        $relayServices = MyFlyingBoxServiceQuery::create()
            ->filterByRelayDelivery(true)
            ->filterByActive(true)
            ->find();

        $homeDeliveryServices = MyFlyingBoxServiceQuery::create()
            ->filterByRelayDelivery(false)
            ->filterByActive(true)
            ->find();

        // Build lists of service codes
        $relayCodes = [];
        foreach ($relayServices as $service) {
            $relayCodes[] = strtoupper($service->getCode());
        }

        $homeDeliveryCodes = [];
        foreach ($homeDeliveryServices as $service) {
            $homeDeliveryCodes[] = strtoupper($service->getCode());
        }

        // Debug: Log to PHP error log
        error_log('MyFlyingBox Duplicator - Relay codes: ' . json_encode($relayCodes));
        error_log('MyFlyingBox Duplicator - Home delivery codes: ' . json_encode($homeDeliveryCodes));

        if (isset($myflyingboxData['options'])) {
            $optionCodes = array_map(function($opt) { return $opt['code'] ?? 'NO_CODE'; }, $myflyingboxData['options']);
            error_log('MyFlyingBox Duplicator - Option codes in response: ' . json_encode($optionCodes));
        }

        // Filter options for pickup version (only relay services)
        if (isset($pickupVersion['options']) && is_array($pickupVersion['options'])) {
            $filteredOptions = [];
            foreach ($pickupVersion['options'] as $option) {
                if (isset($option['code']) && in_array($option['code'], $relayCodes, true)) {
                    $filteredOptions[] = $option;
                }
            }
            $pickupVersion['options'] = $filteredOptions;
            error_log('MyFlyingBox Duplicator - Pickup version has ' . count($filteredOptions) . ' options');
        }

        // Filter options for delivery version (only home delivery services)
        if (isset($deliveryVersion['options']) && is_array($deliveryVersion['options'])) {
            $filteredOptions = [];
            foreach ($deliveryVersion['options'] as $option) {
                if (isset($option['code']) && in_array($option['code'], $homeDeliveryCodes, true)) {
                    $filteredOptions[] = $option;
                }
            }
            $deliveryVersion['options'] = $filteredOptions;
            error_log('MyFlyingBox Duplicator - Delivery version has ' . count($filteredOptions) . ' options');
        }

        // Replace the original with pickup version and add delivery version
        $data[$myflyingboxIndex] = $pickupVersion;
        array_splice($data, $myflyingboxIndex + 1, 0, [$deliveryVersion]);

        // Update the response
        $response->setContent(json_encode($data));
    }
}
