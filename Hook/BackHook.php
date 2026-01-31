<?php

namespace MyFlyingBox\Hook;

use MyFlyingBox\Model\MyFlyingBoxParcelQuery;
use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use MyFlyingBox\MyFlyingBox;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

class BackHook extends BaseHook
{
    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $event->add($this->render('myflyingbox-configuration.html', [
            'module_code' => MyFlyingBox::getModuleCode(),
            'module_id' => MyFlyingBox::getModuleId(),
        ]));
    }

    public function onModuleConfigJs(HookRenderEvent $event): void
    {
        $event->add($this->render('module-config-js.html'));
    }

    /**
     * Add "Shipment" tab to order edit page
     */
    public function onOrderTab(HookRenderBlockEvent $event): void
    {
        $orderId = $event->getArgument('id');

        if (!$orderId) {
            return;
        }

        // Get shipment for this order (not return)
        $shipment = MyFlyingBoxShipmentQuery::create()
            ->filterByOrderId($orderId)
            ->filterByIsReturn(false)
            ->findOne();

        // Get return shipments for this order
        $returnShipments = MyFlyingBoxShipmentQuery::create()
            ->filterByOrderId($orderId)
            ->filterByIsReturn(true)
            ->orderByCreatedAt(\Propel\Runtime\ActiveQuery\Criteria::DESC)
            ->find();

        // Count parcels if shipment exists
        $parcelsCount = 0;
        if ($shipment) {
            $parcelsCount = MyFlyingBoxParcelQuery::create()
                ->filterByShipmentId($shipment->getId())
                ->count();
        }

        // Build status badge
        $statusBadge = '';
        if ($shipment) {
            $status = $shipment->getStatus();
            $badgeClass = match($status) {
                'booked' => 'info',
                'shipped' => 'primary',
                'delivered' => 'success',
                'cancelled' => 'danger',
                default => 'warning',
            };
            $statusLabel = match($status) {
                'pending' => $this->trans('Pending', [], 'myflyingbox'),
                'booked' => $this->trans('Booked', [], 'myflyingbox'),
                'shipped' => $this->trans('Shipped', [], 'myflyingbox'),
                'delivered' => $this->trans('Delivered', [], 'myflyingbox'),
                'cancelled' => $this->trans('Cancelled', [], 'myflyingbox'),
                default => ucfirst($status),
            };
            $statusBadge = '<span class="badge bg-' . $badgeClass . '" style="margin-left: 5px;">' . $statusLabel . '</span>';
        }

        // Add badge for return shipments count
        $returnBadge = '';
        if ($returnShipments->count() > 0) {
            $returnBadge = ' <span class="badge bg-warning" style="margin-left: 3px;" title="' . $this->trans('Return shipments', [], 'myflyingbox') . '">' . $returnShipments->count() . ' ' . $this->trans('return', [], 'myflyingbox') . '</span>';
        }

        $event->add([
            'id' => 'myflyingbox-shipment',
            'title' => '<i class="fa fa-truck"></i> ' . $this->trans('Shipment', [], 'myflyingbox') . $statusBadge . $returnBadge,
            'content' => $this->render('order-shipment-tab.html', [
                'order_id' => $orderId,
                'shipment' => $shipment,
                'parcels_count' => $parcelsCount,
                'return_shipments' => $returnShipments,
            ]),
        ]);
    }

    /**
     * Add JS for order shipment tab
     */
    public function onOrderEditJs(HookRenderEvent $event): void
    {
        $orderId = $event->getArgument('order_id');

        if (!$orderId) {
            return;
        }

        $event->add($this->render('order-shipment-js.html', [
            'order_id' => $orderId,
        ]));
    }

    /**
     * Add MyFlyingBox link to main menu (Tools section)
     */
    public function onMainTopMenuTools(HookRenderBlockEvent $event): void
    {
        $event->add([
            'id' => 'myflyingbox-menu',
            'class' => '',
            'url' => '/admin/module/MyFlyingBox',
            'title' => $this->trans('MyFlyingBox', [], 'myflyingbox'),
        ]);
    }

    /**
     * Add MyFlyingBox sidebar link in modules section
     */
    public function onMainInTopMenuItems(HookRenderEvent $event): void
    {
        $event->add($this->render('menu-item.html'));
    }
}
