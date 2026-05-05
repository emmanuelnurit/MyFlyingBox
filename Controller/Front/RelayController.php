<?php

declare(strict_types=1);

namespace MyFlyingBox\Controller\Front;

use MyFlyingBox\Model\MyFlyingBoxCartRelay;
use MyFlyingBox\Model\MyFlyingBoxCartRelayQuery;
use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\Model\MyFlyingBoxQuoteQuery;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\CarrierLogoProvider;
use MyFlyingBox\Service\LceApiService;
use MyFlyingBox\Service\PriceSurchargeService;
use MyFlyingBox\Service\QuoteService;
use MyFlyingBox\Service\RateLimiterService;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Front\BaseFrontController;
use Psr\Log\LoggerInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Model\AddressQuery;
use Thelia\Model\CountryQuery;

/**
 * Front-office controller for relay point selection and offer management
 */
class RelayController extends BaseFrontController
{
    /**
     * Save selected offer to session
     */
    public function saveOfferAction(Request $request, EventDispatcherInterface $dispatcher, LoggerInterface $logger): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $cartId = $data['cart_id'] ?? null;
            $offerId = $data['offer_id'] ?? null;

            if (!$cartId || !$offerId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing parameters',
                ]);
            }

            // Verify cart belongs to current session
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() !== (int) $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Verify offer exists and belongs to cart's quote
            $offer = MyFlyingBoxOfferQuery::create()
                ->filterById($offerId)
                ->findOne();

            if (!$offer) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid offer',
                ]);
            }

            // Verify offer belongs to a quote for this cart
            $quote = MyFlyingBoxQuoteQuery::create()
                ->filterById($offer->getQuoteId())
                ->filterByCartId($cartId)
                ->findOne();

            if (!$quote) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Offer does not belong to this cart',
                ]);
            }

            // Save selected offer ID to session
            $this->getSession()->set('mfb_selected_offer_id', $offerId);

            // Recalculate price with surcharges and update session Order postage
            /** @var PriceSurchargeService $surchargeService */
            $surchargeService = $this->container->get('myflyingbox.price_surcharge.service');
            $price = $surchargeService->apply($offer->getTotalPriceInCents() / 100);
            $price = round($price, 2);

            $order = $this->getSession()->getOrder();
            if ($order) {
                $order->setPostage($price);
                $order->setPostageTax(0.0);
                $order->setPostageTaxRuleTitle('');
                $this->getSession()->setOrder($order);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Offer selection saved',
                'price' => $price,
                'price_formatted' => number_format($price, 2, ',', ' ') . ' €',
            ]);

        } catch (\Exception $e) {
            $logger->error('MyFlyingBox: error saving offer selection', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => Translator::getInstance()->trans('An error occurred', [], 'myflyingbox'),
            ]);
        }
    }

    /**
     * Get relay points for a given location
     */
    public function getRelayPointsAction(Request $request, LceApiService $apiService, EventDispatcherInterface $dispatcher, LoggerInterface $logger, RateLimiterService $rateLimiter): JsonResponse
    {
        if (!$rateLimiter->isAllowed('relay_points', 20, 60)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Too many requests. Please wait before trying again.',
            ], 429);
        }

        try {
            $query = $request->get('query', '');
            $cartId = $request->get('cart_id');
            $offerId = $request->get('offer_id'); // Optional: the specific offer selected by customer

            if (empty($query) || !$cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing parameters',
                ]);
            }

            // Verify cart belongs to current session (IDOR protection)
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() !== (int) $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Get the latest quote for this cart
            $quote = MyFlyingBoxQuoteQuery::create()
                ->filterByCartId($cartId)
                ->orderByCreatedAt(Criteria::DESC)
                ->findOne();

            if (!$quote) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No quote found for cart',
                ]);
            }

            // Find the relay offer to use for location search.
            // Prefer the offer explicitly selected by the customer (passed as offer_id),
            // so that relay points are filtered to that carrier's network only
            // (e.g. Mondial Relay should only show its own pick-up points).
            $relayOffer = null;
            if ($offerId) {
                $relayOffer = MyFlyingBoxOfferQuery::create()
                    ->filterById($offerId)
                    ->filterByQuoteId($quote->getId())
                    ->useMyFlyingBoxServiceQuery()
                        ->filterByRelayDelivery(true)
                    ->endUse()
                    ->findOne();
            }

            // Fallback: use the first relay offer from the quote when no specific offer was provided
            if (!$relayOffer) {
                $relayOffer = MyFlyingBoxOfferQuery::create()
                    ->filterByQuoteId($quote->getId())
                    ->useMyFlyingBoxServiceQuery()
                        ->filterByRelayDelivery(true)
                    ->endUse()
                    ->findOne();
            }

            if (!$relayOffer || !$relayOffer->getApiOfferUuid()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No relay service available',
                ]);
            }

            // Get the country from the quote's address
            $countryCode = 'FR'; // Default to France
            if ($quote->getAddressId()) {
                $address = AddressQuery::create()->findPk($quote->getAddressId());
                if ($address && $address->getCountryId()) {
                    $country = CountryQuery::create()->findPk($address->getCountryId());
                    if ($country) {
                        $countryCode = $country->getIsoalpha2();
                    }
                }
            }

            // Parse query for postal code (supports international formats: digits, letters, spaces, hyphens)
            $postalCode = '';
            if (preg_match('/\b([A-Z0-9]{2,10}(?:[\s\-][A-Z0-9]{2,5})?)\b/i', $query, $matches)) {
                $postalCode = $matches[1];
            }

            // Get delivery locations from API
            $params = [
                'street' => '',
                'city' => trim(preg_replace('/\d/', '', $query)),
                'country' => $countryCode,
            ];

            if (!empty($postalCode)) {
                $params['postal_code'] = $postalCode;
            }

            $response = $apiService->getDeliveryLocations(
                $relayOffer->getApiOfferUuid(),
                $params
            );

            // API returns data in 'data' key
            $locations = $response['data'] ?? $response['locations'] ?? [];

            if (empty($locations)) {
                return new JsonResponse([
                    'success' => true,
                    'relays' => [],
                    'message' => Translator::getInstance()->trans('No relay points found', [], 'myflyingbox'),
                ]);
            }

            // Format relay points
            $relays = [];
            foreach ($locations as $location) {
                $relays[] = [
                    'code' => $location['code'] ?? '',
                    'name' => $location['company'] ?? $location['name'] ?? '',
                    'street' => $location['street'] ?? '',
                    'city' => $location['city'] ?? '',
                    'postal_code' => $location['postal_code'] ?? '',
                    'country' => $location['country'] ?? 'FR',
                    'latitude' => $location['latitude'] ?? null,
                    'longitude' => $location['longitude'] ?? null,
                    'distance' => isset($location['distance']) ? round($location['distance'] / 1000, 1) : null,
                    'opening_hours' => $location['opening_hours'] ?? [],
                ];
            }

            return new JsonResponse([
                'success' => true,
                'relays' => $relays,
            ]);

        } catch (\Exception $e) {
            $logger->error('MyFlyingBox: relay points error', ['exception' => $e]);

            // Return a user-friendly message, along with success=true and empty relays
            // This allows the frontend to display the fallback message gracefully
            return new JsonResponse([
                'success' => true,
                'relays' => [],
                'message' => Translator::getInstance()->trans('Relay point search temporarily unavailable', [], 'myflyingbox'),
            ]);
        }
    }

    /**
     * Save selected relay point to cart
     */
    public function saveRelayAction(Request $request, EventDispatcherInterface $dispatcher, LoggerInterface $logger): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $cartId = $data['cart_id'] ?? null;
            $relayCode = $data['relay_code'] ?? null;

            if (!$cartId || !$relayCode) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing parameters',
                ]);
            }

            // Verify cart belongs to current session
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() !== (int) $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Find or create cart relay record
            $cartRelay = MyFlyingBoxCartRelayQuery::create()
                ->filterByCartId($cartId)
                ->findOne();

            if (!$cartRelay) {
                $cartRelay = new MyFlyingBoxCartRelay();
                $cartRelay->setCartId($cartId);
            }

            // Update relay info
            $cartRelay->setRelayCode($relayCode);
            $cartRelay->setRelayName($data['relay_name'] ?? '');
            $cartRelay->setRelayStreet($data['relay_street'] ?? '');
            $cartRelay->setRelayCity($data['relay_city'] ?? '');
            $cartRelay->setRelayPostalCode($data['relay_postal_code'] ?? '');
            $cartRelay->setRelayCountry($data['relay_country'] ?? 'FR');
            $cartRelay->save();

            return new JsonResponse([
                'success' => true,
                'message' => 'Relay point saved',
            ]);

        } catch (\Exception $e) {
            $logger->error('MyFlyingBox: error saving relay point', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => Translator::getInstance()->trans('An error occurred', [], 'myflyingbox'),
            ]);
        }
    }

    /**
     * Get offers for a cart with relay_delivery information
     * This API is used by frontend to load shipping options asynchronously
     * Supports optional quote creation via create_quote=1 parameter
     *
     * @param Request $request
     * @param EventDispatcherInterface $dispatcher
     * @param QuoteService $quoteService
     *
     * @return JsonResponse {
     *   success: bool,
     *   offers: array,
     *   offers_count: int,
     *   has_relay_offers: bool,
     *   selected_offer_id: int|null,
     *   selected_relay: array|null,
     *   delivery_postal_code: string,  // Code postal de l'adresse de livraison (NEW)
     *   delivery_country: string        // Code pays ISO (FR, BE, etc.) (NEW)
     * }
     */
    public function getOffersAction(
        Request $request,
        EventDispatcherInterface $dispatcher,
        QuoteService $quoteService,
        PriceSurchargeService $priceSurchargeService,
        LoggerInterface $logger,
        RateLimiterService $rateLimiter
    ): JsonResponse {
        if (!$rateLimiter->isAllowed('offers', 15, 60)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Too many requests. Please wait before trying again.',
            ], 429);
        }

        try {
            $cartId = $request->get('cart_id');
            $addressId = $request->get('address_id');
            $createQuote = $request->get('create_quote', '0') === '1';

            if (!$cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing cart_id parameter',
                ]);
            }

            // Verify cart belongs to current session
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() !== (int) $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Get delivery address and country
            $address = null;
            $country = null;
            $deliveryPostalCode = null;

            if ($addressId) {
                $customer = $this->getSecurityContext()->getCustomerUser();
                $address = AddressQuery::create()
                    ->filterByCustomerId($customer?->getId())
                    ->findPk($addressId);

                if (!$address) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Invalid address',
                    ], 403);
                }

                $country = $address->getCountry();
                $deliveryPostalCode = $address->getZipcode();
            }

            // Fallback to default country
            if (!$country) {
                $country = CountryQuery::create()->findOneByByDefault(1);
            }

            // Get or create quote
            $quote = MyFlyingBoxQuoteQuery::create()
                ->filterByCartId($cartId)
                ->orderByCreatedAt(Criteria::DESC)
                ->findOne();

            // Create quote if requested and none exists (or if address changed)
            if ($createQuote && $country) {
                $quote = $quoteService->getQuoteForCart($sessionCart, $address, $country);
            }

            if (!$quote) {
                return new JsonResponse([
                    'success' => true,
                    'offers' => [],
                    'has_relay_offers' => false,
                ]);
            }

            // Get all offers for this quote
            $dbOffers = MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quote->getId())
                ->joinWithMyFlyingBoxService()
                ->orderByTotalPriceInCents(Criteria::ASC)
                ->find();

            $offers = [];
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

                // Apply price surcharges
                $price = $priceSurchargeService->apply($offer->getTotalPriceInCents() / 100);

                $carrierCode = $service->getCarrierCode();
                $offers[] = [
                    'id' => $offer->getId(),
                    'service_id' => $service->getId(),
                    'service_code' => $service->getCode(),
                    'service_name' => $service->getName(),
                    'carrier_code' => $carrierCode,
                    'carrier_logo' => $logoProvider->getLogoUrl($carrierCode),
                    'carrier_logo_fallback' => $logoProvider->generateFallbackSvg($carrierCode),
                    'price' => $price,
                    'price_formatted' => number_format($price, 2, ',', ' ') . ' €',
                    'delivery_days' => $offer->getDeliveryDays(),
                    'relay_delivery' => $isRelay,
                    'api_offer_uuid' => $offer->getApiOfferUuid(),
                ];
            }

            // Get selected offer from session
            $selectedOfferId = $this->getSession()->get('mfb_selected_offer_id');

            // Get selected relay
            $selectedRelay = null;
            $cartRelay = MyFlyingBoxCartRelayQuery::create()
                ->filterByCartId($cartId)
                ->findOne();

            if ($cartRelay) {
                $selectedRelay = [
                    'code' => $cartRelay->getRelayCode(),
                    'name' => $cartRelay->getRelayName(),
                    'street' => $cartRelay->getRelayStreet(),
                    'city' => $cartRelay->getRelayCity(),
                    'postal_code' => $cartRelay->getRelayPostalCode(),
                    'country' => $cartRelay->getRelayCountry(),
                ];
            }

            return new JsonResponse([
                'success' => true,
                'offers' => $offers,
                'offers_count' => count($offers),
                'has_relay_offers' => $hasRelayOffers,
                'selected_offer_id' => $selectedOfferId,
                'selected_relay' => $selectedRelay,
                'delivery_postal_code' => $deliveryPostalCode,
                'delivery_country' => $country?->getIsoalpha2() ?? 'FR',
            ]);

        } catch (\Exception $e) {
            $logger->error('MyFlyingBox: error fetching offers', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => Translator::getInstance()->trans('An error occurred', [], 'myflyingbox'),
            ]);
        }
    }

    /**
     * Lightweight relay-status endpoint used by the order-delivery hook
     * to gate the modern checkout's "next step" CTA in pickup mode.
     * Returns DB-only state — no LCE API call, safe to poll.
     */
    public function getRelayStatusAction(Request $request, EventDispatcherInterface $dispatcher): JsonResponse
    {
        $cartId = (int) $request->get('cart_id', 0);
        if ($cartId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Missing cart_id']);
        }

        $sessionCart = $this->getSession()->getSessionCart($dispatcher);
        if (!$sessionCart || $sessionCart->getId() !== $cartId) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid cart']);
        }

        // [THE-557/THE-560] Endpoint reports cart-side state for the gate JS:
        // - has_offer: a MyFlyingBox offer/option is recorded for the cart
        // - requires_relay: the picked offer/option is a pickup-style service
        // - has_relay: a relay code is persisted on the cart
        //
        // Two callers feed this endpoint:
        //  1. Smarty (default) widget — sets `mfb_selected_offer_id` in session
        //     via saveOfferAction. We resolve from the session offer id.
        //  2. Modern (React) checkout — never calls our saveOfferAction; instead
        //     the gate JS reads `deliveryModuleOptionCode` from /open_api/checkout
        //     and forwards it as `option_code` here. We resolve to the matching
        //     MyFlyingBoxService (option codes are upper(service.code), see
        //     ApiListener::getDeliveryModuleOptions).
        $optionCode = (string) $request->get('option_code', '');
        $selectedOfferId = $this->getSession()->get('mfb_selected_offer_id');
        $hasOffer = false;
        $requiresRelay = false;

        if ($optionCode !== '') {
            // Modern flow — service code is uppercased in option codes.
            $service = MyFlyingBoxServiceQuery::create()
                ->filterByCode($optionCode)
                ->_or()->filterByCode(strtolower($optionCode))
                ->_or()->filterByCode(strtoupper($optionCode))
                ->filterByActive(true)
                ->findOne();
            if ($service) {
                $hasOffer = true;
                $requiresRelay = (bool) $service->getRelayDelivery();
            }
        } elseif ($selectedOfferId) {
            // Default (Smarty) flow.
            $offer = MyFlyingBoxOfferQuery::create()
                ->joinWithMyFlyingBoxService()
                ->findPk($selectedOfferId);
            if ($offer) {
                $hasOffer = true;
                $service = $offer->getMyFlyingBoxService();
                $requiresRelay = $service ? (bool) $service->getRelayDelivery() : false;
            }
        }

        $cartRelay = MyFlyingBoxCartRelayQuery::create()
            ->filterByCartId($cartId)
            ->findOne();
        $hasRelay = $cartRelay !== null && !empty($cartRelay->getRelayCode());

        return new JsonResponse([
            'success' => true,
            'has_offer' => $hasOffer,
            'requires_relay' => $requiresRelay,
            'has_relay' => $hasRelay,
            'mfb_module_id' => MyFlyingBox::getModuleId(),
        ]);
    }

    /**
     * Lightweight metadata endpoint used by the modern-template gate JS to
     * split the single MyFlyingBox delivery module into two virtual entries
     * (relay vs home) on the client. Returns the MFB module id and a per-code
     * relay-delivery flag so the JS can rewrite /open_api/delivery/modules
     * responses without round-tripping per option.
     */
    public function getOptionsMetaAction(): JsonResponse
    {
        $relayCodes = [];
        $homeCodes = [];

        $services = MyFlyingBoxServiceQuery::create()
            ->filterByActive(true)
            ->find();

        foreach ($services as $service) {
            // ApiListener::getDeliveryModuleOptions emits option codes as
            // strtoupper($service->getCode()); mirror that here so the JS can
            // match against checkout.deliveryModuleOptionCode directly.
            $optionCode = strtoupper((string) $service->getCode());
            if ($service->getRelayDelivery()) {
                $relayCodes[] = $optionCode;
            } else {
                $homeCodes[] = $optionCode;
            }
        }

        return new JsonResponse([
            'success' => true,
            'mfb_module_id' => MyFlyingBox::getModuleId(),
            'relay_codes' => $relayCodes,
            'home_codes' => $homeCodes,
        ]);
    }

    /**
     * Get cart delivery estimation data via AJAX
     * Used by skeleton loader pattern on cart page
     */
    public function getCartEstimateAction(
        Request $request,
        EventDispatcherInterface $dispatcher,
        QuoteService $quoteService,
        PriceSurchargeService $priceSurchargeService,
        LoggerInterface $logger,
        RateLimiterService $rateLimiter
    ): JsonResponse {
        if (!$rateLimiter->isAllowed('cart_estimate', 15, 60)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Too many requests. Please wait before trying again.',
            ], 429);
        }

        try {
            $cartId = $request->get('cart_id');

            if (!$cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing cart_id parameter',
                ]);
            }

            // Verify cart belongs to current session
            $sessionCart = $this->getSession()->getSessionCart($dispatcher);
            if (!$sessionCart || $sessionCart->getId() !== (int) $cartId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid cart',
                ]);
            }

            // Check cart has items
            if ($sessionCart->countCartItems() === 0) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Cart is empty',
                ]);
            }

            // Get default delivery country
            $country = CountryQuery::create()->findOneByByDefault(1);
            if (!$country) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No default country configured',
                ]);
            }

            // Get quote with offers
            $quote = $quoteService->getQuoteForCart($sessionCart, null, $country);
            if (!$quote) {
                return new JsonResponse([
                    'success' => true,
                    'carriers_count' => 0,
                ]);
            }

            // Get formatted offers with prices
            $dbOffers = MyFlyingBoxOfferQuery::create()
                ->filterByQuoteId($quote->getId())
                ->joinWithMyFlyingBoxService()
                ->orderByTotalPriceInCents(Criteria::ASC)
                ->find();

            $prices = [];
            $hasRelay = false;

            foreach ($dbOffers as $offer) {
                $service = $offer->getMyFlyingBoxService();
                if (!$service || !$service->getActive()) {
                    continue;
                }

                $price = $priceSurchargeService->apply($offer->getTotalPriceInCents() / 100);
                $prices[] = $price;

                if ($service->getRelayDelivery()) {
                    $hasRelay = true;
                }
            }

            if (empty($prices)) {
                return new JsonResponse([
                    'success' => true,
                    'carriers_count' => 0,
                ]);
            }

            $minPrice = min($prices);
            $carriersCount = count($prices);

            // Build carriers label
            $translator = Translator::getInstance();
            if ($carriersCount === 1) {
                $carriersLabel = '1 ' . $translator->trans('carrier available', [], 'myflyingbox');
            } else {
                $carriersLabel = $carriersCount . ' ' . $translator->trans('carriers available', [], 'myflyingbox');
            }

            return new JsonResponse([
                'success' => true,
                'min_price' => number_format($minPrice, 2, ',', ' ') . ' €',
                'carriers_count' => $carriersCount,
                'carriers_label' => $carriersLabel,
                'has_relay' => $hasRelay,
            ]);

        } catch (\Exception $e) {
            $logger->error('MyFlyingBox: error fetching estimate', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'message' => Translator::getInstance()->trans('An error occurred', [], 'myflyingbox'),
            ]);
        }
    }

}
