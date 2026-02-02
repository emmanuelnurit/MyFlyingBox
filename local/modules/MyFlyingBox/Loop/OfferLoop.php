<?php

namespace MyFlyingBox\Loop;

use MyFlyingBox\Model\MyFlyingBoxOfferQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;

/**
 * Loop to display offers from a quote
 *
 * {loop type="myflyingbox.offers" name="offers" quote_id=$quote_id}
 *   {$ID} {$SERVICE_NAME} {$PRICE} {$DELIVERY_DAYS}
 * {/loop}
 */
class OfferLoop extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('id'),
            Argument::createIntTypeArgument('quote_id'),
            Argument::createIntTypeArgument('service_id'),
            Argument::createAnyTypeArgument('order', 'price')
        );
    }

    public function buildModelCriteria()
    {
        $query = MyFlyingBoxOfferQuery::create()
            ->joinWithMyFlyingBoxService();

        if (null !== $id = $this->getId()) {
            $query->filterById($id);
        }

        if (null !== $quoteId = $this->getQuoteId()) {
            $query->filterByQuoteId($quoteId);
        }

        if (null !== $serviceId = $this->getServiceId()) {
            $query->filterByServiceId($serviceId);
        }

        // Handle ordering
        $order = $this->getOrder();
        switch ($order) {
            case 'id':
                $query->orderById();
                break;
            case 'delivery_days':
                $query->orderByDeliveryDays();
                break;
            case 'price':
            default:
                $query->orderByTotalPriceInCents();
                break;
        }

        return $query;
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        foreach ($loopResult->getResultDataCollection() as $offer) {
            $row = new LoopResultRow($offer);
            $service = $offer->getMyFlyingBoxService();

            $row->set('ID', $offer->getId())
                ->set('QUOTE_ID', $offer->getQuoteId())
                ->set('SERVICE_ID', $offer->getServiceId())
                ->set('API_OFFER_UUID', $offer->getApiOfferUuid())
                ->set('LCE_PRODUCT_CODE', $offer->getLceProductCode())
                ->set('BASE_PRICE_IN_CENTS', $offer->getBasePriceInCents())
                ->set('TOTAL_PRICE_IN_CENTS', $offer->getTotalPriceInCents())
                ->set('INSURANCE_PRICE_IN_CENTS', $offer->getInsurancePriceInCents())
                ->set('CURRENCY', $offer->getCurrency())
                ->set('DELIVERY_DAYS', $offer->getDeliveryDays())
                // Computed values
                ->set('PRICE', $offer->getTotalPriceInCents() / 100)
                ->set('BASE_PRICE', $offer->getBasePriceInCents() / 100)
                ->set('INSURANCE_PRICE', $offer->getInsurancePriceInCents() / 100)
                // Service info
                ->set('SERVICE_CODE', $service ? $service->getCode() : '')
                ->set('SERVICE_NAME', $service ? $service->getName() : '')
                ->set('CARRIER_CODE', $service ? $service->getCarrierCode() : '')
                ->set('RELAY_DELIVERY', $service ? $service->getRelayDelivery() : false);

            $loopResult->addRow($row);
        }

        return $loopResult;
    }
}
