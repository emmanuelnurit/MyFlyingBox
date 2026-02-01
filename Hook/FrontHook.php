<?php

namespace MyFlyingBox\Hook;

use MyFlyingBox\Model\MyFlyingBoxCartRelayQuery;
use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\Model\MyFlyingBoxShipmentEventQuery;
use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\QuoteService;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Assets\AssetResolverInterface;
use Thelia\Model\CountryQuery;
use Thelia\Model\ModuleQuery;
use TheliaSmarty\Template\SmartyParser;

/**
 * Front-office hooks for MyFlyingBox module
 */
class FrontHook extends BaseHook
{
    private QuoteService $quoteService;

    public function __construct(
        QuoteService $quoteService,
        SmartyParser $parser = null,
        AssetResolverInterface $resolver = null,
        EventDispatcherInterface $eventDispatcher = null
    ) {
        parent::__construct($parser, $resolver, $eventDispatcher);
        $this->quoteService = $quoteService;
    }

    /**
     * Hook for order-delivery.extra - displays shipping options and relay point selection
     * The hook is called for each delivery module with module=$ID parameter
     * Renders skeleton immediately, data loaded via AJAX for better UX
     */
    public function onOrderDeliveryExtra(HookRenderEvent $event): void
    {
        // Check if this hook is for MyFlyingBox module
        $moduleId = $event->getArgument('module');
        $myFlyingBoxModule = ModuleQuery::create()->findOneByCode('MyFlyingBox');

        if (!$myFlyingBoxModule || $moduleId != $myFlyingBoxModule->getId()) {
            return;
        }

        // Get cart from session (use BaseHook's getCart() method)
        $cart = $this->getCart();
        if (!$cart) {
            return;
        }

        // Get delivery address ID (data will be loaded via AJAX)
        $addressId = $this->getSession()->getOrder()?->getChoosenDeliveryAddress();

        // Render skeleton template immediately - offers will be loaded via AJAX
        // This provides instant visual feedback instead of waiting for API
        $event->add($this->render('order-delivery-extra.html', [
            'cart_id' => $cart->getId(),
            'address_id' => $addressId,
            'google_maps_api_key' => MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_GOOGLE_MAPS_API_KEY, ''),
        ]));
    }

    /**
     * Hook for cart.bottom - displays delivery cost estimation in cart page
     * Renders skeleton immediately, data loaded via AJAX for better UX
     */
    public function onCartBottom(HookRenderEvent $event): void
    {
        // Get cart from session (use BaseHook's getCart() method)
        $cart = $this->getCart();
        if (!$cart || $cart->countCartItems() === 0) {
            return;
        }

        // Render skeleton template immediately - data will be loaded via AJAX
        // This provides instant visual feedback instead of waiting for API
        $event->add($this->render('cart-delivery-estimation.html', [
            'cart_id' => $cart->getId(),
        ]));
    }

    /**
     * Get formatted offers for template display
     */
    private function getFormattedOffers(int $quoteId): array
    {
        try {
            $dbOffers = MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quoteId)
                ->joinWithMyFlyingBoxService()
                ->orderByTotalPriceInCents(Criteria::ASC)
                ->find();

            $offers = [];
            foreach ($dbOffers as $offer) {
                $service = $offer->getMyFlyingBoxService();
                if (!$service || !$service->getActive()) {
                    continue;
                }

                // Apply price surcharges
                $price = $offer->getTotalPriceInCents() / 100;
                $price = $this->applyPriceSurcharges($price);

                $offers[] = [
                    'id' => $offer->getId(),
                    'service_id' => $service->getId(),
                    'service_code' => $service->getCode(),
                    'service_name' => $service->getName(),
                    'carrier_code' => $service->getCarrierCode(),
                    'price' => $price,
                    'price_formatted' => number_format($price, 2, ',', ' ') . ' €',
                    'delivery_days' => $offer->getDeliveryDays(),
                    'relay_delivery' => (bool) $service->getRelayDelivery(),
                    'api_offer_uuid' => $offer->getApiOfferUuid(),
                ];
            }

            return $offers;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Apply price surcharges (same logic as main module)
     */
    private function applyPriceSurcharges(float $price): float
    {
        // Percentage surcharge
        $percentSurcharge = (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_PERCENT, 0);
        if ($percentSurcharge > 0) {
            $price += $price * ($percentSurcharge / 100);
        }

        // Static surcharge (stored in cents, convert to euros)
        $staticSurcharge = (float) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_SURCHARGE_STATIC, 0);
        if ($staticSurcharge > 0) {
            $price += $staticSurcharge / 100;
        }

        // Rounding
        $roundIncrement = (int) MyFlyingBox::getConfigValue(MyFlyingBox::CONFIG_PRICE_ROUND_INCREMENT, 1);
        if ($roundIncrement > 1) {
            $price = ceil($price * 100 / $roundIncrement) * $roundIncrement / 100;
        }

        return round($price, 2);
    }

    /**
     * Check if there are any active relay delivery services
     */
    private function hasRelayServices(): bool
    {
        try {
            $relayService = MyFlyingBoxServiceQuery::create()
                ->filterByActive(true)
                ->filterByRelayDelivery(true)
                ->findOne();

            return $relayService !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the currently selected relay point for a cart
     */
    private function getSelectedRelay(int $cartId): ?array
    {
        try {
            $relay = MyFlyingBoxCartRelayQuery::create()
                ->filterByCartId($cartId)
                ->findOne();

            if ($relay) {
                return [
                    'code' => $relay->getRelayCode(),
                    'name' => $relay->getRelayName(),
                    'street' => $relay->getRelayStreet(),
                    'city' => $relay->getRelayCity(),
                    'postal_code' => $relay->getRelayPostalCode(),
                    'country' => $relay->getRelayCountry(),
                ];
            }
        } catch (\Exception $e) {
            // Table may not exist
        }

        return null;
    }

    /**
     * Hook for order-invoice.delivery-address - displays delivery summary before payment
     */
    public function onOrderInvoiceDeliveryAddress(HookRenderEvent $event): void
    {
        // Get cart from session (use BaseHook's getCart() method)
        $cart = $this->getCart();
        if (!$cart) {
            return;
        }

        // Get selected offer from session
        $selectedOfferId = $this->getSession()->get('mfb_selected_offer_id');
        if (!$selectedOfferId) {
            return;
        }

        try {
            $offer = MyFlyingBoxOfferQuery::create()
                ->joinWithMyFlyingBoxService()
                ->findPk($selectedOfferId);

            if (!$offer) {
                return;
            }

            $service = $offer->getMyFlyingBoxService();
            if (!$service) {
                return;
            }

            $price = $this->applyPriceSurcharges($offer->getTotalPriceInCents() / 100);

            // Get selected relay point if applicable
            $selectedRelay = null;
            if ($service->getRelayDelivery()) {
                $selectedRelay = $this->getSelectedRelay($cart->getId());
            }

            $event->add($this->render('order-delivery-summary.html', [
                'selected_offer' => [
                    'service_name' => $service->getName(),
                    'carrier_code' => $service->getCarrierCode(),
                    'price' => number_format($price, 2, ',', ' ') . ' €',
                    'delivery_days' => $offer->getDeliveryDays(),
                    'is_relay' => $service->getRelayDelivery(),
                ],
                'selected_relay' => $selectedRelay,
            ]));

        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Hook for account-order.after-information - displays tracking information on customer order page
     */
    public function onAccountOrderAfterInformation(HookRenderEvent $event): void
    {
        $orderId = $event->getArgument('order');
        if (!$orderId) {
            return;
        }

        // Get the order
        $order = \Thelia\Model\OrderQuery::create()->findPk($orderId);
        if (!$order) {
            return;
        }

        // Check if MyFlyingBox is the delivery module
        $module = ModuleQuery::create()->findPk($order->getDeliveryModuleId());
        if (!$module || $module->getCode() !== 'MyFlyingBox') {
            return;
        }

        // Get shipment for this order
        try {
            $shipment = MyFlyingBoxShipmentQuery::create()
                ->filterByOrderId($orderId)
                ->filterByIsReturn(false)
                ->findOne();

            if (!$shipment) {
                return;
            }

            // Get parcels with tracking info
            $parcels = MyFlyingBoxParcelQuery::create()
                ->filterByShipmentId($shipment->getId())
                ->find();

            $parcelData = [];
            foreach ($parcels as $parcel) {
                $parcelData[] = [
                    'id' => $parcel->getId(),
                    'tracking_number' => $parcel->getTrackingNumber(),
                    'weight' => $parcel->getWeight(),
                    'dimensions' => $parcel->getLength() . 'x' . $parcel->getWidth() . 'x' . $parcel->getHeight() . ' cm',
                ];
            }

            // Get tracking events
            $events = MyFlyingBoxShipmentEventQuery::create()
                ->filterByShipmentId($shipment->getId())
                ->orderByEventDate(Criteria::DESC)
                ->find();

            $eventData = [];
            foreach ($events as $event) {
                $eventData[] = [
                    'code' => $event->getEventCode(),
                    'label' => $event->getEventLabel(),
                    'date' => $event->getEventDate()?->format('d/m/Y H:i'),
                    'location' => $event->getLocation(),
                ];
            }

            // Get service info
            $service = $shipment->getMyFlyingBoxService();
            $trackingUrl = null;
            if ($service && $service->getTrackingUrl() && !empty($parcelData)) {
                $firstTracking = $parcelData[0]['tracking_number'] ?? '';
                if ($firstTracking) {
                    $trackingUrl = str_replace('{tracking_number}', $firstTracking, $service->getTrackingUrl());
                }
            }

            // Get relay info if relay delivery
            $relayInfo = null;
            if ($shipment->getRelayDeliveryCode()) {
                $relayInfo = [
                    'code' => $shipment->getRelayDeliveryCode(),
                    'street' => $shipment->getRecipientStreet(),
                    'city' => $shipment->getRecipientCity(),
                    'postal_code' => $shipment->getRecipientPostalCode(),
                ];
            }

            $event->add($this->render('order-tracking.html', [
                'shipment' => [
                    'id' => $shipment->getId(),
                    'status' => $shipment->getStatus(),
                    'status_label' => $this->getStatusLabel($shipment->getStatus()),
                    'date_booking' => $shipment->getDateBooking()?->format('d/m/Y H:i'),
                    'collection_date' => $shipment->getCollectionDate()?->format('d/m/Y'),
                ],
                'service' => $service ? [
                    'name' => $service->getName(),
                    'carrier_code' => $service->getCarrierCode(),
                    'is_relay' => $service->getRelayDelivery(),
                ] : null,
                'parcels' => $parcelData,
                'events' => $eventData,
                'tracking_url' => $trackingUrl,
                'relay' => $relayInfo,
            ]));

        } catch (\Exception $e) {
            // Silently fail - tables may not exist
        }
    }

    /**
     * Hook for order-placed.additional-payment-info - displays delivery confirmation after payment
     */
    public function onOrderPlacedAdditionalPaymentInfo(HookRenderEvent $event): void
    {
        // Get order ID from hook argument (placed_order_id in order-placed.html template)
        $orderId = $event->getArgument('placed_order_id');
        if (!$orderId) {
            return;
        }

        // Get the order
        $order = \Thelia\Model\OrderQuery::create()->findPk($orderId);
        if (!$order) {
            return;
        }

        // Check if MyFlyingBox is the delivery module
        $module = ModuleQuery::create()->findPk($order->getDeliveryModuleId());
        if (!$module || $module->getCode() !== 'MyFlyingBox') {
            return;
        }

        try {
            // Get shipment for this order
            $shipment = MyFlyingBoxShipmentQuery::create()
                ->filterByOrderId($orderId)
                ->filterByIsReturn(false)
                ->findOne();

            // Get service info (from shipment or from order delivery module title)
            $carrierName = null;
            $carrierCode = null;
            $isRelay = false;
            $relayAddress = null;

            if ($shipment) {
                $service = $shipment->getMyFlyingBoxService();
                if ($service) {
                    $carrierName = $service->getName();
                    $carrierCode = $service->getCarrierCode();
                    $isRelay = (bool) $service->getRelayDelivery();
                }

                // Get relay address if relay delivery
                if ($shipment->getRelayDeliveryCode()) {
                    $isRelay = true;
                    $relayAddress = [
                        'street' => $shipment->getRecipientStreet(),
                        'city' => $shipment->getRecipientCity(),
                        'postal_code' => $shipment->getRecipientPostalCode(),
                    ];
                }
            } else {
                // Fallback: get info from order delivery module title
                $carrierName = $order->getDeliveryModuleTitle();
            }

            if (!$carrierName) {
                return;
            }

            $event->add($this->render('order-confirmation-delivery.html', [
                'carrier_name' => $carrierName,
                'carrier_code' => $carrierCode ?? 'MFB',
                'is_relay' => $isRelay,
                'relay_address' => $relayAddress,
            ]));

        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Get human-readable status label
     */
    private function getStatusLabel(string $status): string
    {
        $labels = [
            'pending' => 'En attente',
            'booked' => 'Réservé',
            'shipped' => 'Expédié',
            'delivered' => 'Livré',
            'cancelled' => 'Annulé',
        ];

        return $labels[$status] ?? $status;
    }
}
