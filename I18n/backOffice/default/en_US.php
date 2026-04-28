<?php

declare(strict_types=1);

/**
 * Domain: myflyingbox.bo
 *
 * Back Office strings for the "Delivery mode picker" tab (THE-546).
 * Covers the Smarty BO templates:
 *  - templates/backOffice/default/order-shipment-tab.html
 *  - templates/backOffice/default/order-shipment-js.html
 *
 * AJAX endpoint error messages (ShipmentController) stay in the main
 * `myflyingbox` domain (see I18n/en_US.php) to stay consistent with the
 * rest of the module's API responses.
 */
return [
    // Delivery mode picker
    'Delivery mode' => 'Delivery mode',
    'Select a delivery mode' => 'Select a delivery mode',
    'No eligible delivery mode for this order' => 'No eligible delivery mode for this order',
    'Loading available delivery modes...' => 'Loading available delivery modes...',

    // Relay points
    'Relay' => 'Relay',
    'Relay point' => 'Relay point',
    'Selected relay point' => 'Selected relay point',
    'Search by address or postal code' => 'Search by address or postal code',
    'Search' => 'Search',
    'Loading relay points...' => 'Loading relay points...',
    'No relay points available for this address' => 'No relay points available for this address',

    // Save button
    'Save delivery mode' => 'Save delivery mode',

    // Lock banner
    'This shipment is locked: it has already been booked.' => 'This shipment is locked: it has already been booked.',
    'Modifications will be possible again if the shipment is cancelled.' => 'Modifications will be possible again if the shipment is cancelled.',

    // Toasts
    'Delivery mode updated successfully.' => 'Delivery mode updated successfully.',
    'Failed to load delivery modes.' => 'Failed to load delivery modes.',
    'Failed to load relay points.' => 'Failed to load relay points.',
    'Failed to save delivery mode.' => 'Failed to save delivery mode.',
    'A relay point must be selected for this delivery mode.' => 'A relay point must be selected for this delivery mode.',
    'Shipment is already booked or shipped — delivery mode cannot be modified.' => 'Shipment is already booked or shipped — delivery mode cannot be modified.',
];
