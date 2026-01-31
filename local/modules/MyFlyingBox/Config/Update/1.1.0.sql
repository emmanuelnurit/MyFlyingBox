-- MyFlyingBox Module - Update 1.1.0
-- Ajout de la table des evenements de suivi

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Table `myflyingbox_shipment_event`
-- Evenements de suivi des expeditions
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `myflyingbox_shipment_event` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `shipment_id` INT NOT NULL COMMENT 'Expedition parente',
    `parcel_id` INT NULL COMMENT 'Colis specifique (optionnel)',
    `event_code` VARCHAR(50) NOT NULL COMMENT 'Code evenement (created, picked_up, in_transit, delivered...)',
    `event_label` VARCHAR(255) NULL COMMENT 'Libelle evenement',
    `event_date` DATETIME NULL COMMENT 'Date de l evenement',
    `location` VARCHAR(255) NULL COMMENT 'Lieu de l evenement',
    `created_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `fk_myflyingbox_event_shipment` (`shipment_id`),
    KEY `fk_myflyingbox_event_parcel` (`parcel_id`),
    CONSTRAINT `fk_myflyingbox_event_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `myflyingbox_shipment` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_myflyingbox_event_parcel` FOREIGN KEY (`parcel_id`) REFERENCES `myflyingbox_parcel` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
