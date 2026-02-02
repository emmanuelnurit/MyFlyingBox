# MyFlyingBox - Module Thelia 2.6

Module de livraison multi-transporteurs pour Thelia 2.6 via l'API LCE (MyFlyingBox).

## Fonctionnalites

- **Calcul dynamique des tarifs** : Integration de l'API LCE pour obtenir les tarifs en temps reel
- **Multi-transporteurs** : Support de tous les transporteurs disponibles via MyFlyingBox (Chronopost, Colissimo, DHL, UPS, etc.)
- **Points relais** : Selection de points relais avec carte Google Maps
- **Generation d'etiquettes** : Creation automatique des bordereaux d'expedition
- **Suivi des colis** : Tracking en temps reel avec webhooks et notifications client
- **Gestion des dimensions** : Table de correspondance poids/dimensions configurable

## Pre-requis

- Thelia >= 2.5.0
- PHP >= 8.0
- Extensions PHP : `curl`, `json`
- Compte MyFlyingBox avec acces API (https://www.myflyingbox.com)

## Installation

### Via Composer (recommande)

```bash
composer require thelia/myflyingbox-module
```

### Installation manuelle

1. Copier le dossier `MyFlyingBox` dans `local/modules/`
2. Executer les commandes suivantes :

```bash
# Activer le module
php Thelia module:activate MyFlyingBox

# Mettre a jour la base de donnees
php Thelia module:refresh
```

## Configuration

1. Acceder au back-office Thelia
2. Aller dans **Modules > MyFlyingBox**
3. Configurer les parametres suivants :

### Identifiants API
- **Login API** : Votre identifiant MyFlyingBox
- **Mot de passe API** : Votre mot de passe API
- **Mode sandbox** : Activer pour les tests

### Adresse expediteur
Remplir les informations de l'adresse d'expedition (nom, entreprise, adresse, etc.)

### Parametres de prix
- **Surcharge (%)** : Majoration en pourcentage sur les tarifs
- **Surcharge fixe (cts)** : Majoration en centimes
- **Arrondi (cts)** : Increment d'arrondi des prix
- **Poids max par colis** : Limite de poids pour le decoupage

### Services
Apres validation des identifiants API, actualiser la liste des services et activer ceux souhaites.

### Dimensions
Configurer la table de correspondance poids/dimensions pour le calcul automatique.

### Webhooks (optionnel)
Configurer le secret webhook pour recevoir les mises a jour de tracking automatiquement.

## Structure du module

```
MyFlyingBox/
├── Command/           # Commandes CLI (synchronisation tracking)
├── Config/            # Configuration (routes, schema, SQL)
│   ├── Update/        # Scripts de mise a jour SQL
│   ├── module.xml     # Metadata du module
│   ├── schema.xml     # Schema Propel
│   ├── routing.xml    # Routes
│   └── TheliaMain.sql # Installation initiale
├── Controller/        # Controleurs (config, shipment, webhook)
├── EventListener/     # Listeners d'evenements
├── Form/              # Formulaires Symfony
├── Hook/              # Hooks back-office et front-office
├── I18n/              # Traductions (fr_FR, en_US)
├── Loop/              # Loops Thelia
├── Model/             # Modeles Propel
├── Service/           # Services metier (API, devis, expedition)
├── templates/         # Templates Smarty
│   ├── backOffice/    # Templates administration
│   └── frontOffice/   # Templates front (tracking, relais)
└── images/            # Assets (logos transporteurs)
```

## Tables de base de donnees

| Table | Description |
|-------|-------------|
| `myflyingbox_service` | Services de transport disponibles |
| `myflyingbox_quote` | Devis de transport (cache API) |
| `myflyingbox_offer` | Offres de prix par devis |
| `myflyingbox_shipment` | Expeditions |
| `myflyingbox_parcel` | Colis d'une expedition |
| `myflyingbox_dimension` | Correspondance poids/dimensions |
| `myflyingbox_shipment_event` | Evenements de suivi |
| `myflyingbox_cart_relay` | Point relais selectionne par panier |

## Commandes CLI

```bash
# Synchroniser le tracking des expeditions en cours
php Thelia myflyingbox:sync-tracking
```

## Hooks disponibles

### Back-office
- `order.tab` : Onglet expedition dans le detail commande
- `order.edit-js` : Scripts JS pour la gestion expedition
- `main.head-css` : Styles additionnels

### Front-office
- `order.delivery.extra` : Selecteur de point relais
- `order-placed.additional-payment-info` : Infos livraison confirmation

## API LCE

Documentation officielle : https://www.myflyingbox.com/api/

## Licence

LGPL-3.0-or-later

## Auteur

Emmanuel Nurit - OpenStudio
Email: enurit@openstudio.fr
