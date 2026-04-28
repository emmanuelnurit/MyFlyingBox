<?php

declare(strict_types=1);

/**
 * Domain: myflyingbox.bo
 *
 * Back Office strings for the "Delivery mode picker" tab (THE-546).
 * Covers THE-547 (endpoints), THE-549 (Smarty template), THE-550 (JS relay map).
 */
return [
    // Shipment tab - Delivery mode picker
    'Delivery mode' => 'Delivery mode',
    'Select a delivery mode' => 'Select a delivery mode',
    'Current delivery mode' => 'Current delivery mode',
    'Change delivery mode' => 'Change delivery mode',
    'No eligible delivery mode for this order' => 'No eligible delivery mode for this order',
    'Loading available delivery modes...' => 'Loading available delivery modes...',

    // Relay points
    'Relay' => 'Relay',
    'Relay point' => 'Relay point',
    'Selected relay point' => 'Selected relay point',
    'Search relay points' => 'Search relay points',
    'Search by address or postal code' => 'Search by address or postal code',
    'Search' => 'Search',
    'Loading relay points...' => 'Loading relay points...',
    'No relay points available for this address' => 'No relay points available for this address',
    'Click on a marker to select a relay point' => 'Click on a marker to select a relay point',
    'Loading map...' => 'Loading map...',

    // Buttons
    'Save delivery mode' => 'Save delivery mode',
    'Saving...' => 'Saving...',
    'Cancel' => 'Cancel',

    // States / banners
    'This shipment is locked: it has already been booked.' => 'This shipment is locked: it has already been booked.',
    'Modifications will be possible again if the shipment is cancelled.' => 'Modifications will be possible again if the shipment is cancelled.',
    'Delivery mode updated successfully.' => 'Delivery mode updated successfully.',
    'Postage has been recalculated silently.' => 'Postage has been recalculated silently.',

    // Errors (BO toasts + endpoint responses)
    'Failed to load delivery modes.' => 'Failed to load delivery modes.',
    'Failed to load relay points.' => 'Failed to load relay points.',
    'Failed to save delivery mode.' => 'Failed to save delivery mode.',
    'Carrier API is currently unavailable. Please try again later.' => 'Carrier API is currently unavailable. Please try again later.',
    'Invalid request payload.' => 'Invalid request payload.',
    'A relay point must be selected for this delivery mode.' => 'A relay point must be selected for this delivery mode.',
    'Selected delivery option is not eligible for this order.' => 'Selected delivery option is not eligible for this order.',
    'Shipment is already booked or shipped — delivery mode cannot be modified.' => 'Shipment is already booked or shipped — delivery mode cannot be modified.',
    'You are not authorized to modify this order.' => 'You are not authorized to modify this order.',
    'Invalid CSRF token.' => 'Invalid CSRF token.',
];
