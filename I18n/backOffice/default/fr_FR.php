<?php

declare(strict_types=1);

/**
 * Domain: myflyingbox.bo
 *
 * Chaînes du Back Office pour l onglet "Choix du mode de livraison" (THE-546).
 * Couvre THE-547 (endpoints), THE-549 (template Smarty), THE-550 (JS map relais).
 */
return [
    // Onglet Shipment - Sélecteur mode de livraison
    'Delivery mode' => 'Mode de livraison',
    'Select a delivery mode' => 'Sélectionnez un mode de livraison',
    'Current delivery mode' => 'Mode de livraison actuel',
    'Change delivery mode' => 'Changer de mode de livraison',
    'No eligible delivery mode for this order' => 'Aucun mode de livraison éligible pour cette commande',
    'Loading available delivery modes...' => 'Chargement des modes de livraison disponibles...',

    // Points relais
    'Relay' => 'Relais',
    'Relay point' => 'Point relais',
    'Selected relay point' => 'Point relais sélectionné',
    'Search relay points' => 'Rechercher des points relais',
    'Search by address or postal code' => 'Rechercher par adresse ou code postal',
    'Search' => 'Rechercher',
    'Loading relay points...' => 'Chargement des points relais...',
    'No relay points available for this address' => 'Aucun point relais disponible pour cette adresse',
    'Click on a marker to select a relay point' => 'Cliquez sur un marqueur pour sélectionner un point relais',
    'Loading map...' => 'Chargement de la carte...',

    // Boutons
    'Save delivery mode' => 'Enregistrer le mode de livraison',
    'Saving...' => 'Enregistrement...',
    'Cancel' => 'Annuler',

    // États / bandeaux
    'This shipment is locked: it has already been booked.' => 'Cette expédition est verrouillée : elle a déjà été réservée.',
    'Modifications will be possible again if the shipment is cancelled.' => 'Les modifications redeviendront possibles si l expédition est annulée.',
    'Delivery mode updated successfully.' => 'Mode de livraison mis à jour avec succès.',
    'Postage has been recalculated silently.' => 'Le port a été recalculé silencieusement.',

    // Erreurs (toasts BO + réponses endpoints)
    'Failed to load delivery modes.' => 'Impossible de charger les modes de livraison.',
    'Failed to load relay points.' => 'Impossible de charger les points relais.',
    'Failed to save delivery mode.' => 'Impossible d enregistrer le mode de livraison.',
    'Carrier API is currently unavailable. Please try again later.' => 'L API du transporteur est indisponible. Veuillez réessayer plus tard.',
    'Invalid request payload.' => 'Requête invalide.',
    'A relay point must be selected for this delivery mode.' => 'Un point relais doit être sélectionné pour ce mode de livraison.',
    'Selected delivery option is not eligible for this order.' => 'L option de livraison sélectionnée n est pas éligible pour cette commande.',
    'Shipment is already booked or shipped — delivery mode cannot be modified.' => 'L expédition est déjà réservée ou expédiée — le mode de livraison ne peut pas être modifié.',
    'You are not authorized to modify this order.' => 'Vous n êtes pas autorisé à modifier cette commande.',
    'Invalid CSRF token.' => 'Jeton CSRF invalide.',
];
