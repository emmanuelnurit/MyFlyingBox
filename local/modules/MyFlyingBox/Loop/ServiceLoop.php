<?php

namespace MyFlyingBox\Loop;

use MyFlyingBox\Model\MyFlyingBoxServiceQuery;
use MyFlyingBox\Service\CarrierLogoProvider;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;

/**
 * Loop to display MyFlyingBox services
 *
 * {loop type="myflyingbox.services" name="services" active="true"}
 *   {$ID} {$CODE} {$NAME} {$CARRIER_CODE} {$CARRIER_LOGO} {$ACTIVE}
 * {/loop}
 */
class ServiceLoop extends BaseLoop implements PropelSearchLoopInterface
{
    private ?CarrierLogoProvider $carrierLogoProvider = null;

    /**
     * Get the CarrierLogoProvider, instantiating it if needed
     */
    private function getCarrierLogoProvider(): CarrierLogoProvider
    {
        if ($this->carrierLogoProvider === null) {
            $this->carrierLogoProvider = new CarrierLogoProvider();
        }
        return $this->carrierLogoProvider;
    }
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('id'),
            Argument::createBooleanTypeArgument('active'),
            Argument::createBooleanTypeArgument('relay_delivery'),
            Argument::createAnyTypeArgument('order', 'name')
        );
    }

    public function buildModelCriteria()
    {
        $query = MyFlyingBoxServiceQuery::create();

        if (null !== $id = $this->getId()) {
            $query->filterById($id);
        }

        if (null !== $active = $this->getActive()) {
            $query->filterByActive($active);
        }

        if (null !== $relayDelivery = $this->getRelayDelivery()) {
            $query->filterByRelayDelivery($relayDelivery);
        }

        // Handle ordering
        $order = $this->getOrder();
        switch ($order) {
            case 'id':
                $query->orderById();
                break;
            case 'code':
                $query->orderByCode();
                break;
            case 'carrier':
            case 'carrier_code':
                $query->orderByCarrierCode();
                break;
            case 'name':
            default:
                $query->orderByName();
                break;
        }

        return $query;
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        foreach ($loopResult->getResultDataCollection() as $service) {
            $row = new LoopResultRow($service);

            $carrierCode = $service->getCarrierCode();
            $carrierLogo = $this->getCarrierLogoProvider()->getLogoUrl($carrierCode);

            $row->set('ID', $service->getId())
                ->set('CODE', $service->getCode())
                ->set('NAME', $service->getName())
                ->set('CARRIER_CODE', $carrierCode)
                ->set('CARRIER_LOGO', $carrierLogo)
                ->set('PICKUP_AVAILABLE', $service->getPickupAvailable())
                ->set('DROPOFF_AVAILABLE', $service->getDropoffAvailable())
                ->set('RELAY_DELIVERY', $service->getRelayDelivery())
                ->set('TRACKING_URL', $service->getTrackingUrl())
                ->set('DELIVERY_DELAY', $service->getDeliveryDelay())
                ->set('ACTIVE', $service->getActive());

            $loopResult->addRow($row);
        }

        return $loopResult;
    }
}
