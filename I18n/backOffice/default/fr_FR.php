<?php

declare(strict_types=1);

/**
 * Domain: myflyingbox.bo
 *
 * Chaînes du Back Office pour l'onglet "Choix du mode de livraison" (THE-546).
 * Couvre les templates Smarty BO :
 *  - templates/backOffice/default/order-shipment-tab.html
 *  - templates/backOffice/default/order-shipment-js.html
 *
 * Les messages d'erreur des endpoints AJAX (ShipmentController) restent dans le
 * domaine principal `myflyingbox` (cf. I18n/fr_FR.php) pour rester homogènes
 * avec les autres réponses API du module.
 */
return [
    // Sélecteur mode de livraison
    'Delivery mode' => 'Mode de livraison',
    'Select a delivery mode' => 'Sélectionnez un mode de livraison',
    'No eligible delivery mode for this order' => 'Aucun mode de livraison éligible pour cette commande',
    'Loading available delivery modes...' => 'Chargement des modes de livraison disponibles...',

    // Points relais
    'Relay' => 'Relais',
    'Relay point' => 'Point relais',
    'Selected relay point' => 'Point relais sélectionné',
    'Search by address or postal code' => 'Rechercher par adresse ou code postal',
    'Search' => 'Rechercher',
    'Loading relay points...' => 'Chargement des points relais...',
    'No relay points available for this address' => 'Aucun point relais disponible pour cette adresse',

    // Bouton enregistrement
    'Save delivery mode' => 'Enregistrer le mode de livraison',

    // Bandeau verrouillage
    'This shipment is locked: it has already been booked.' => 'Cette expédition est verrouillée : elle a déjà été réservée.',
    'Modifications will be possible again if the shipment is cancelled.' => 'Les modifications redeviendront possibles si l\'expédition est annulée.',

    // Toasts
    'Delivery mode updated successfully.' => 'Mode de livraison mis à jour avec succès.',
    'Failed to load delivery modes.' => 'Impossible de charger les modes de livraison.',
    'Failed to load relay points.' => 'Impossible de charger les points relais.',
    'Failed to save delivery mode.' => 'Impossible d\'enregistrer le mode de livraison.',
    'A relay point must be selected for this delivery mode.' => 'Un point relais doit être sélectionné pour ce mode de livraison.',
    'Shipment is already booked or shipped — delivery mode cannot be modified.' => 'L\'expédition est déjà réservée ou expédiée — le mode de livraison ne peut pas être modifié.',
];
