<?php

namespace MyFlyingBox\Loop;

use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;

/**
 * Loop to display parcels from a shipment
 *
 * {loop type="myflyingbox.parcels" name="parcels" shipment_id=$shipment_id}
 *   {$ID} {$TRACKING_NUMBER} {$WEIGHT}
 * {/loop}
 */
class ParcelLoop extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('id'),
            Argument::createIntTypeArgument('shipment_id'),
            Argument::createAnyTypeArgument('order', 'id')
        );
    }

    public function buildModelCriteria()
    {
        $query = MyFlyingBoxParcelQuery::create();

        if (null !== $id = $this->getId()) {
            $query->filterById($id);
        }

        if (null !== $shipmentId = $this->getShipmentId()) {
            $query->filterByShipmentId($shipmentId);
        }

        // Handle ordering
        $order = $this->getOrder();
        switch ($order) {
            case 'id':
                $query->orderById(Criteria::ASC);
                break;
            case 'id-desc':
                $query->orderById(Criteria::DESC);
                break;
            default:
                $query->orderById(Criteria::ASC);
                break;
        }

        return $query;
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        foreach ($loopResult->getResultDataCollection() as $parcel) {
            $row = new LoopResultRow($parcel);

            $row->set('ID', $parcel->getId())
                ->set('SHIPMENT_ID', $parcel->getShipmentId())
                ->set('LENGTH', $parcel->getLength())
                ->set('WIDTH', $parcel->getWidth())
                ->set('HEIGHT', $parcel->getHeight())
                ->set('WEIGHT', $parcel->getWeight())
                ->set('SHIPPER_REFERENCE', $parcel->getShipperReference())
                ->set('RECIPIENT_REFERENCE', $parcel->getRecipientReference())
                ->set('CUSTOMER_REFERENCE', $parcel->getCustomerReference())
                ->set('VALUE', $parcel->getValue())
                ->set('VALUE_EUROS', $parcel->getValue() / 100)
                ->set('CURRENCY', $parcel->getCurrency())
                ->set('DESCRIPTION', $parcel->getDescription())
                ->set('TRACKING_NUMBER', $parcel->getTrackingNumber())
                ->set('LABEL_URL', $parcel->getLabelUrl())
                ->set('CREATED_AT', $parcel->getCreatedAt())
                ->set('UPDATED_AT', $parcel->getUpdatedAt());

            $loopResult->addRow($row);
        }

        return $loopResult;
    }
}
