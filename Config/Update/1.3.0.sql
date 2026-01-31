-- MyFlyingBox 1.3.0 - Add relay point details to shipment table

ALTER TABLE `myflyingbox_shipment`
    ADD COLUMN `relay_name` VARCHAR(255) NULL AFTER `relay_delivery_code`,
    ADD COLUMN `relay_street` VARCHAR(500) NULL AFTER `relay_name`,
    ADD COLUMN `relay_city` VARCHAR(255) NULL AFTER `relay_street`,
    ADD COLUMN `relay_postal_code` VARCHAR(20) NULL AFTER `relay_city`,
    ADD COLUMN `relay_country` VARCHAR(2) NULL AFTER `relay_postal_code`;
