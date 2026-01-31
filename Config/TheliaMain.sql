-- MyFlyingBox Module - Installation SQL
-- Version 1.0.0

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Table `myflyingbox_service`
-- Services de transport disponibles (transporteurs LCE)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_service`;
CREATE TABLE `myflyingbox_service` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `carrier_code` VARCHAR(100) NOT NULL COMMENT 'Code transporteur (dhl, ups, chronopost...)',
    `code` VARCHAR(100) NOT NULL COMMENT 'Code produit LCE unique',
    `name` VARCHAR(255) NOT NULL COMMENT 'Nom du service',
    `pickup_available` TINYINT(1) DEFAULT 0 COMMENT 'Enlevement disponible',
    `dropoff_available` TINYINT(1) DEFAULT 0 COMMENT 'Depot disponible',
    `relay_delivery` TINYINT(1) DEFAULT 0 COMMENT 'Livraison point relais',
    `tracking_url` VARCHAR(500) NULL COMMENT 'URL de suivi avec placeholder',
    `delivery_delay` VARCHAR(100) NULL COMMENT 'Delai de livraison affiche (ex: 24-48h, 3-5 jours)',
    `active` TINYINT(1) DEFAULT 1 COMMENT 'Service actif',
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `myflyingbox_service_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `myflyingbox_quote`
-- Devis de transport (cache des quotes API)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_quote`;
CREATE TABLE `myflyingbox_quote` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `cart_id` INT NOT NULL COMMENT 'Panier associe',
    `address_id` INT NULL COMMENT 'Adresse de livraison',
    `api_quote_uuid` VARCHAR(255) NULL COMMENT 'UUID du devis API LCE',
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `fk_myflyingbox_quote_cart` (`cart_id`),
    KEY `fk_myflyingbox_quote_address` (`address_id`),
    CONSTRAINT `fk_myflyingbox_quote_cart` FOREIGN KEY (`cart_id`) REFERENCES `cart` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_myflyingbox_quote_address` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `myflyingbox_offer`
-- Offres de prix par devis
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_offer`;
CREATE TABLE `myflyingbox_offer` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `quote_id` INT NOT NULL COMMENT 'Devis parent',
    `service_id` INT NOT NULL COMMENT 'Service de transport',
    `api_offer_uuid` VARCHAR(255) NULL COMMENT 'UUID offre API LCE',
    `lce_product_code` VARCHAR(100) NULL COMMENT 'Code produit LCE',
    `base_price_in_cents` INT NULL COMMENT 'Prix HT en centimes',
    `total_price_in_cents` INT NULL COMMENT 'Prix TTC en centimes',
    `insurance_price_in_cents` INT NULL COMMENT 'Prix assurance en centimes',
    `currency` VARCHAR(3) DEFAULT 'EUR',
    `delivery_days` INT NULL COMMENT 'Delai de livraison estime',
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `fk_myflyingbox_offer_quote` (`quote_id`),
    KEY `fk_myflyingbox_offer_service` (`service_id`),
    CONSTRAINT `fk_myflyingbox_offer_quote` FOREIGN KEY (`quote_id`) REFERENCES `myflyingbox_quote` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_myflyingbox_offer_service` FOREIGN KEY (`service_id`) REFERENCES `myflyingbox_service` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `myflyingbox_shipment`
-- Expeditions
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_shipment`;
CREATE TABLE `myflyingbox_shipment` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `order_id` INT NOT NULL COMMENT 'Commande Thelia',
    `service_id` INT NULL COMMENT 'Service de transport',
    `api_quote_uuid` VARCHAR(255) NULL,
    `api_offer_uuid` VARCHAR(255) NULL,
    `api_order_uuid` VARCHAR(255) NULL COMMENT 'UUID commande LCE (apres booking)',
    `collection_date` DATE NULL COMMENT 'Date enlevement',
    `relay_delivery_code` VARCHAR(50) NULL COMMENT 'Code point relais',
    `relay_name` VARCHAR(255) NULL COMMENT 'Nom point relais',
    `relay_street` VARCHAR(500) NULL COMMENT 'Adresse point relais',
    `relay_city` VARCHAR(255) NULL COMMENT 'Ville point relais',
    `relay_postal_code` VARCHAR(20) NULL COMMENT 'Code postal point relais',
    `relay_country` VARCHAR(2) NULL COMMENT 'Pays point relais',
    -- Expediteur
    `shipper_name` VARCHAR(255) NULL,
    `shipper_company` VARCHAR(255) NULL,
    `shipper_street` VARCHAR(500) NULL,
    `shipper_city` VARCHAR(255) NULL,
    `shipper_postal_code` VARCHAR(20) NULL,
    `shipper_country` VARCHAR(2) NULL,
    `shipper_phone` VARCHAR(50) NULL,
    `shipper_email` VARCHAR(255) NULL,
    -- Destinataire
    `recipient_name` VARCHAR(255) NULL,
    `recipient_company` VARCHAR(255) NULL,
    `recipient_street` VARCHAR(500) NULL,
    `recipient_city` VARCHAR(255) NULL,
    `recipient_postal_code` VARCHAR(20) NULL,
    `recipient_country` VARCHAR(2) NULL,
    `recipient_phone` VARCHAR(50) NULL,
    `recipient_email` VARCHAR(255) NULL,
    -- Statut
    `status` VARCHAR(50) DEFAULT 'pending' COMMENT 'pending, booked, shipped, delivered, cancelled',
    `is_return` TINYINT(1) DEFAULT 0 COMMENT 'Expedition retour',
    `date_booking` DATETIME NULL COMMENT 'Date de reservation',
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `fk_myflyingbox_shipment_order` (`order_id`),
    KEY `fk_myflyingbox_shipment_service` (`service_id`),
    CONSTRAINT `fk_myflyingbox_shipment_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_myflyingbox_shipment_service` FOREIGN KEY (`service_id`) REFERENCES `myflyingbox_service` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `myflyingbox_parcel`
-- Colis d'une expedition
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_parcel`;
CREATE TABLE `myflyingbox_parcel` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `shipment_id` INT NOT NULL COMMENT 'Expedition parente',
    `length` INT NULL COMMENT 'Longueur en cm',
    `width` INT NULL COMMENT 'Largeur en cm',
    `height` INT NULL COMMENT 'Hauteur en cm',
    `weight` DECIMAL(10,3) NULL COMMENT 'Poids en kg',
    `shipper_reference` VARCHAR(100) NULL,
    `recipient_reference` VARCHAR(100) NULL,
    `customer_reference` VARCHAR(100) NULL,
    `value` INT NULL COMMENT 'Valeur en centimes',
    `currency` VARCHAR(3) DEFAULT 'EUR',
    `description` VARCHAR(500) NULL COMMENT 'Description du contenu',
    `tracking_number` VARCHAR(100) NULL COMMENT 'Numero de suivi',
    `label_url` VARCHAR(500) NULL COMMENT 'URL etiquette',
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `fk_myflyingbox_parcel_shipment` (`shipment_id`),
    CONSTRAINT `fk_myflyingbox_parcel_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `myflyingbox_shipment` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `myflyingbox_dimension`
-- Table de correspondance poids vers dimensions
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_dimension`;
CREATE TABLE `myflyingbox_dimension` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `weight_from` DECIMAL(10,3) NOT NULL COMMENT 'Poids minimum (kg)',
    `weight_to` DECIMAL(10,3) NOT NULL COMMENT 'Poids maximum (kg)',
    `length` INT NOT NULL COMMENT 'Longueur en cm',
    `width` INT NOT NULL COMMENT 'Largeur en cm',
    `height` INT NOT NULL COMMENT 'Hauteur en cm',
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `myflyingbox_shipment_event`
-- Evenements de suivi d'expedition
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_shipment_event`;
CREATE TABLE `myflyingbox_shipment_event` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `shipment_id` INT NOT NULL COMMENT 'Expedition',
    `parcel_id` INT NULL COMMENT 'Colis (optionnel)',
    `event_code` VARCHAR(50) NOT NULL COMMENT 'Code evenement (delivered, in_transit...)',
    `event_label` VARCHAR(255) NULL COMMENT 'Libelle evenement',
    `event_date` DATETIME NULL COMMENT 'Date de l\'evenement',
    `location` VARCHAR(255) NULL COMMENT 'Lieu',
    `created_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `fk_myflyingbox_event_shipment` (`shipment_id`),
    KEY `fk_myflyingbox_event_parcel` (`parcel_id`),
    CONSTRAINT `fk_myflyingbox_event_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `myflyingbox_shipment` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_myflyingbox_event_parcel` FOREIGN KEY (`parcel_id`) REFERENCES `myflyingbox_parcel` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `myflyingbox_cart_relay`
-- Point relais selectionne par panier
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_cart_relay`;
CREATE TABLE `myflyingbox_cart_relay` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `cart_id` INT NOT NULL COMMENT 'Panier',
    `relay_code` VARCHAR(50) NOT NULL COMMENT 'Code point relais',
    `relay_name` VARCHAR(255) NULL COMMENT 'Nom du point relais',
    `relay_street` VARCHAR(500) NULL,
    `relay_city` VARCHAR(255) NULL,
    `relay_postal_code` VARCHAR(20) NULL,
    `relay_country` VARCHAR(2) NULL,
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `myflyingbox_cart_relay_unique` (`cart_id`),
    CONSTRAINT `fk_myflyingbox_cart_relay_cart` FOREIGN KEY (`cart_id`) REFERENCES `cart` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `myflyingbox_email_template`
-- Templates d'emails personnalisables
-- -----------------------------------------------------
DROP TABLE IF EXISTS `myflyingbox_email_template`;
CREATE TABLE `myflyingbox_email_template` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL COMMENT 'Template type: shipped, delivered',
    `locale` VARCHAR(10) NOT NULL COMMENT 'Locale code: fr_FR, en_US',
    `name` VARCHAR(100) NOT NULL COMMENT 'Admin-friendly template name',
    `subject` VARCHAR(255) NOT NULL COMMENT 'Email subject with placeholders',
    `html_content` LONGTEXT NOT NULL COMMENT 'HTML email body with placeholders',
    `text_content` LONGTEXT COMMENT 'Plain text fallback (auto-generated if empty)',
    `is_active` TINYINT(1) DEFAULT 1,
    `is_default` TINYINT(1) DEFAULT 0 COMMENT 'Shipped with module, restorable',
    `created_at` DATETIME NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `myflyingbox_email_template_unique` (`code`, `locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Donnees par defaut : correspondance poids -> dimensions
-- -----------------------------------------------------
INSERT INTO `myflyingbox_dimension` (`weight_from`, `weight_to`, `length`, `width`, `height`, `created_at`, `updated_at`) VALUES
(0.000, 1.000, 15, 15, 15, NOW(), NOW()),
(1.001, 2.000, 18, 18, 18, NOW(), NOW()),
(2.001, 3.000, 20, 20, 20, NOW(), NOW()),
(3.001, 5.000, 25, 25, 20, NOW(), NOW()),
(5.001, 7.000, 30, 25, 20, NOW(), NOW()),
(7.001, 10.000, 35, 30, 25, NOW(), NOW()),
(10.001, 15.000, 40, 35, 30, NOW(), NOW()),
(15.001, 20.000, 50, 40, 30, NOW(), NOW()),
(20.001, 25.000, 55, 45, 35, NOW(), NOW()),
(25.001, 30.000, 60, 50, 40, NOW(), NOW());

SET FOREIGN_KEY_CHECKS = 1;
