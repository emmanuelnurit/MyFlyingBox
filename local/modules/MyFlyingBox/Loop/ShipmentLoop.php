<?php

namespace MyFlyingBox\Loop;

use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;

/**
 * Loop to display shipments
 *
 * {loop type="myflyingbox.shipments" name="shipments" order_id=$order_id}
 *   {$ID} {$STATUS} {$RECIPIENT_NAME}
 * {/loop}
 */
class ShipmentLoop extends BaseLoop implements PropelSearchLoopInterface
{
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('id'),
            Argument::createIntTypeArgument('order_id'),
            Argument::createIntTypeArgument('service_id'),
            Argument::createAnyTypeArgument('status'),
            Argument::createBooleanTypeArgument('is_return'),
            Argument::createAnyTypeArgument('order', 'id-desc')
        );
    }

    public function buildModelCriteria()
    {
        $query = MyFlyingBoxShipmentQuery::create()
            ->leftJoinWithMyFlyingBoxService();

        if (null !== $id = $this->getId()) {
            $query->filterById($id);
        }

        if (null !== $orderId = $this->getOrderId()) {
            $query->filterByOrderId($orderId);
        }

        if (null !== $serviceId = $this->getServiceId()) {
            $query->filterByServiceId($serviceId);
        }

        if (null !== $status = $this->getStatus()) {
            $query->filterByStatus($status);
        }

        if (null !== $isReturn = $this->getIsReturn()) {
            $query->filterByIsReturn($isReturn);
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
            case 'date':
                $query->orderByCreatedAt(Criteria::ASC);
                break;
            case 'date-desc':
                $query->orderByCreatedAt(Criteria::DESC);
                break;
            case 'status':
                $query->orderByStatus(Criteria::ASC);
                break;
            default:
                $query->orderById(Criteria::DESC);
                break;
        }

        return $query;
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        foreach ($loopResult->getResultDataCollection() as $shipment) {
            $row = new LoopResultRow($shipment);
            $service = $shipment->getMyFlyingBoxService();

            $row->set('ID', $shipment->getId())
                ->set('ORDER_ID', $shipment->getOrderId())
                ->set('SERVICE_ID', $shipment->getServiceId())
                ->set('API_QUOTE_UUID', $shipment->getApiQuoteUuid())
                ->set('API_OFFER_UUID', $shipment->getApiOfferUuid())
                ->set('API_ORDER_UUID', $shipment->getApiOrderUuid())
                ->set('COLLECTION_DATE', $shipment->getCollectionDate())
                ->set('RELAY_DELIVERY_CODE', $shipment->getRelayDeliveryCode())
                // Shipper info
                ->set('SHIPPER_NAME', $shipment->getShipperName())
                ->set('SHIPPER_COMPANY', $shipment->getShipperCompany())
                ->set('SHIPPER_STREET', $shipment->getShipperStreet())
                ->set('SHIPPER_CITY', $shipment->getShipperCity())
                ->set('SHIPPER_POSTAL_CODE', $shipment->getShipperPostalCode())
                ->set('SHIPPER_COUNTRY', $shipment->getShipperCountry())
                ->set('SHIPPER_PHONE', $shipment->getShipperPhone())
                ->set('SHIPPER_EMAIL', $shipment->getShipperEmail())
                // Recipient info
                ->set('RECIPIENT_NAME', $shipment->getRecipientName())
                ->set('RECIPIENT_COMPANY', $shipment->getRecipientCompany())
                ->set('RECIPIENT_STREET', $shipment->getRecipientStreet())
                ->set('RECIPIENT_CITY', $shipment->getRecipientCity())
                ->set('RECIPIENT_POSTAL_CODE', $shipment->getRecipientPostalCode())
                ->set('RECIPIENT_COUNTRY', $shipment->getRecipientCountry())
                ->set('RECIPIENT_PHONE', $shipment->getRecipientPhone())
                ->set('RECIPIENT_EMAIL', $shipment->getRecipientEmail())
                // Status
                ->set('STATUS', $shipment->getStatus())
                ->set('IS_RETURN', $shipment->getIsReturn())
                ->set('DATE_BOOKING', $shipment->getDateBooking())
                ->set('CREATED_AT', $shipment->getCreatedAt())
                ->set('UPDATED_AT', $shipment->getUpdatedAt())
                // Service info
                ->set('SERVICE_CODE', $service ? $service->getCode() : '')
                ->set('SERVICE_NAME', $service ? $service->getName() : '')
                ->set('CARRIER_CODE', $service ? $service->getCarrierCode() : '');

            $loopResult->addRow($row);
        }

        return $loopResult;
    }
}
