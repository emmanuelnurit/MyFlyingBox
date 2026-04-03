<?php

declare(strict_types=1);

namespace MyFlyingBox\EventListener;

use MyFlyingBox\Model\MyFlyingBoxCartRelay;
use MyFlyingBox\Model\MyFlyingBoxCartRelayQuery;
use MyFlyingBox\Model\MyFlyingBoxOffer;
use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\MyFlyingBox;
use MyFlyingBox\Service\LceApiService;
use MyFlyingBox\Service\QuoteService;
use OpenApi\Events\DeliveryModuleOptionEvent;
use OpenApi\Events\OpenApiEvents;
use OpenApi\Events\PickupLocationEvent as OAPickupLocationEvent;
use OpenApi\Model\Api\DeliveryModuleOption;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Delivery\PickupLocationEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ModuleQuery;
use Thelia\Model\PickupLocation;
use Thelia\Model\PickupLocationAddress;
use Thelia\Module\Exception\DeliveryException;

class ApiListener implements EventSubscriberInterface
{
    protected ?Request $request;

    public function __construct(
        protected ContainerInterface $container,
        protected LceApiService      $apiService,
        protected QuoteService       $quoteService,
        RequestStack                 $requestStack)
    {
        $this->request = $requestStack->getCurrentRequest();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OpenApiEvents::MODULE_DELIVERY_GET_OPTIONS => ['getDeliveryModuleOptions', 128],
            TheliaEvents::MODULE_DELIVERY_GET_PICKUP_LOCATIONS => ['getPickupLocations', 128],
            OAPickupLocationEvent::MODULE_DELIVERY_SET_PICKUP_LOCATION => ['setPickupLocation', 128]
        ];
    }

    public function getDeliveryModuleOptions(DeliveryModuleOptionEvent $event): void
    {
        // Only for MyFlyingBox module
        if ((int)$event->getModule()->getId() !== (int)MyFlyingBox::getModuleId()) {
            return;
        }

        $isValid = true;

        try {
            $propelModule = ModuleQuery::create()
                ->filterById(MyFlyingBox::getModuleId())
                ->findOne();

            if (null === $propelModule) {
                throw new \Exception('MyFlyingBox module not found');
            }

            /** @var MyFlyingBox $moduleInstance */
            $moduleInstance = $propelModule->getModuleInstance($this->container);

            $country = $event->getCountry();
            $state = $event->getState();

            if (!$moduleInstance->isValidDelivery($country, $state)) {
                throw new DeliveryException(Translator::getInstance()->trans('MyFlyingBox is not available'));
            }

        } catch (\Exception) {
            $isValid = false;
            $this->logger->warning('[MFB] ApiListener: delivery options unavailable', [
                'exception' => $e->getMessage(),
            ]);
        }

        $services = MyFlyingBoxServiceQuery::create()->filterByActive(true)->find();

        foreach ($services as $service) {
            /** @var DeliveryModuleOption $option */
            $option = $this->container->get('open_api.model.factory')->buildModel('DeliveryModuleOption');

            $quote = $this->quoteService->getQuoteForCart($event->getCart(), $event->getAddress(), $event->getCountry());

            if (!$quote) {
                continue;
            }

            $offers = $this->quoteService->getOffersForQuote($quote);

            if (empty($offers)) {
                continue;
            }

            $offer = null;
            foreach ($offers as $currentOffer) {
                /** @var MyFlyingBoxOffer $currentOffer */
                if ($currentOffer->getServiceId() === $service->getId()) {
                    $offer = $currentOffer;
                    break;
                }
            }

            if ($offer === null) {
                continue;
            }

            $option
                ->setCode(strtoupper($service->getCode()))
                ->setValid($isValid)
                ->setTitle('')
                ->setImage('')
                ->setMinimumDeliveryDate('')
                ->setMaximumDeliveryDate('')
                ->setPostage($offer->getTotalPriceInCents() / 100)
                ->setPostageTax(($offer->getTotalPriceInCents() - $offer->getBasePriceInCents()) / 100)
                ->setPostageUntaxed($offer->getBasePriceInCents() / 100);

            $event->appendDeliveryModuleOptions($option);
        }
    }

    /**
     * Get the list of locations (relay points)
     *
     * @param PickupLocationEvent $pickupLocationEvent
     * @throws \Exception
     */
    public function getPickupLocations(PickupLocationEvent $pickupLocationEvent): void
    {
        // Filter by module ID if specified
        if (null !== $moduleIds = $pickupLocationEvent->getModuleIds()) {
            if (!in_array(MyFlyingBox::getModuleId(), $moduleIds)) {
                return;
            }
        }

        try {
            // Get search parameters from the event
            $zipCode = $pickupLocationEvent->getZipCode();
            $city = $pickupLocationEvent->getCity();
            $address = $pickupLocationEvent->getAddress();
            $country = $pickupLocationEvent->getCountry();

            // At least zipCode or city is required
            if (empty($zipCode) && empty($city)) {
                return;
            }

            // Get country code
            $countryCode = $country ? $country->getIsoalpha2() : 'FR';

            // Find relay offers for this module
            // We need to get active relay services to search for pickup locations
            $relayServices = MyFlyingBoxServiceQuery::create()
                ->filterByActive(true)
                ->filterByRelayDelivery(true)
                ->find();

            if ($relayServices->count() === 0) {
                return;
            }

            // Get the most recent quote that contains relay offers
            // We need an offer UUID to search for pickup locations
            $relayOffer = MyFlyingBoxOfferQuery::create()
                ->useMyFlyingBoxServiceQuery()
                ->filterByActive(true)
                ->filterByRelayDelivery(true)
                ->endUse()
                ->useMyFlyingBoxQuoteQuery()
                ->endUse()
                ->orderById(Criteria::DESC)
                ->findOne();

            // If no recent quote with relay offers, we cannot search
            if (!$relayOffer || !$relayOffer->getApiOfferUuid()) {
                return;
            }

            // Build API request parameters
            $params = [
                'street' => $address ?? '',
                'city' => $city ?? '',
                'country' => $countryCode,
            ];

            if (!empty($zipCode)) {
                $params['postal_code'] = $zipCode;
            }

            // Call API to get delivery locations
            $response = $this->apiService->getDeliveryLocations(
                $relayOffer->getApiOfferUuid(),
                $params
            );

            // API returns data in 'data' key
            $locations = $response['data'] ?? $response['locations'] ?? [];

            if (empty($locations)) {
                return;
            }

            // Convert API locations to Thelia PickupLocation objects
            foreach ($locations as $location) {
                // Skip locations without valid GPS coordinates
                $lat = $location['latitude'] ?? $location['lat'] ?? null;
                $lng = $location['longitude'] ?? $location['lng'] ?? $location['lon'] ?? null;

                if (empty($lat) || empty($lng) || !is_numeric($lat) || !is_numeric($lng)) {
                    error_log('MyFlyingBox: Skipping location without valid coordinates: ' . json_encode($location));
                    continue;
                }

                $pickupLocation = new PickupLocation();

                $pickupLocation
                    ->setId($location['code'] ?? '')
                    ->setTitle($location['company'] ?? $location['name'] ?? '')
                    ->setAddress($this->createPickupLocationAddressFromLocation($location))
                    ->setLatitude((float)$lat)
                    ->setLongitude((float)$lng)
                    ->setOpeningHours(PickupLocation::MONDAY_OPENING_HOURS_KEY, $location['opening_hours'][0]['hours'] ?? "00:00-00:00 00:00-00:00")
                    ->setOpeningHours(PickupLocation::TUESDAY_OPENING_HOURS_KEY, $location['opening_hours'][1]['hours'] ?? "00:00-00:00 00:00-00:00")
                    ->setOpeningHours(PickupLocation::WEDNESDAY_OPENING_HOURS_KEY, $location['opening_hours'][2]['hours'] ?? "00:00-00:00 00:00-00:00")
                    ->setOpeningHours(PickupLocation::THURSDAY_OPENING_HOURS_KEY, $location['opening_hours'][3]['hours'] ?? "00:00-00:00 00:00-00:00")
                    ->setOpeningHours(PickupLocation::FRIDAY_OPENING_HOURS_KEY, $location['opening_hours'][4]['hours'] ?? "00:00-00:00 00:00-00:00")
                    ->setOpeningHours(PickupLocation::SATURDAY_OPENING_HOURS_KEY, $location['opening_hours'][5]['hours'] ?? "00:00-00:00 00:00-00:00")
                    ->setOpeningHours(PickupLocation::SUNDAY_OPENING_HOURS_KEY, $location['opening_hours'][6]['hours'] ?? "00:00-00:00 00:00-00:00")
                    ->setModuleId(MyFlyingBox::getModuleId());

                $pickupLocationEvent->appendLocation($pickupLocation);
            }
        } catch (\Exception $e) {
            // Log error but don't throw to avoid breaking other modules
            error_log('MyFlyingBox pickup locations error: ' . $e->getMessage());
        }
    }

    /**
     * @throws PropelException
     */
    public function setPickupLocation(OAPickupLocationEvent $pickupLocationEvent): void
    {
        $cartId = $pickupLocationEvent->getCart()->getId();

        $cartRelay = MyFlyingBoxCartRelayQuery::create()
            ->filterByCartId($cartId)
            ->findOne();

        if (!$cartRelay) {
            $cartRelay = new MyFlyingBoxCartRelay();
            $cartRelay->setCartId($cartId);
        }

        if ($cartRelay->getRelayCode() === $pickupLocationEvent->getId()) {
            return;
        }

        // Update relay info
        $cartRelay->setRelayCode($pickupLocationEvent->getId());
        $cartRelay->setRelayName($pickupLocationEvent->getTitle());
        $cartRelay->setRelayStreet($pickupLocationEvent->getAddress());
        $cartRelay->setRelayCity($pickupLocationEvent->getCity());
        $cartRelay->setRelayPostalCode($pickupLocationEvent->getZipCode());
        $cartRelay->setRelayCountry($pickupLocationEvent->getCountryCode());
        $cartRelay->save();
    }

    protected function createPickupLocationAddressFromLocation(array $location): PickupLocationAddress
    {
        /** We create the new location address */
        $pickupLocationAddress = new PickupLocationAddress();

        /** We set the different properties of the location address */
        $pickupLocationAddress
            ->setId($location['code'])
            ->setTitle($location['name'] ?? '')
            ->setAddress1($location['street'])
            ->setCity($location['city'])
            ->setZipCode($location['postal_code'])
            ->setPhoneNumber('')
            ->setCellphoneNumber('')
            ->setCompany('')
            ->setCountryCode($location['country'])
            ->setFirstName('')
            ->setLastName('')
            ->setIsDefault(0)
            ->setLabel('')
        ;

        return $pickupLocationAddress;
    }
}