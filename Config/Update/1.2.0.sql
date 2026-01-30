-- MyFlyingBox Module - Update 1.2.0
-- Add delivery_delay column to services

ALTER TABLE `myflyingbox_service`
ADD COLUMN `delivery_delay` VARCHAR(100) NULL COMMENT 'Delai de livraison affiche (ex: 24-48h, 3-5 jours)'
AFTER `tracking_url`;

-- Pre-fill delivery delays based on carrier and service type (all in hours)
-- Express services (24-48h)
UPDATE `myflyingbox_service` SET `delivery_delay` = '24-48h'
WHERE `carrier_code` IN ('chronopost', 'dhl_express', 'fedex', 'ups', 'tnt')
   OR `code` LIKE '%express%'
   OR `code` LIKE '%chrono%'
   OR `name` LIKE '%Express%'
   OR `name` LIKE '%24%';

-- Standard services (48-96h = 2-4 days)
UPDATE `myflyingbox_service` SET `delivery_delay` = '48-96h'
WHERE `delivery_delay` IS NULL
  AND (`carrier_code` IN ('colissimo', 'laposte', 'dpd', 'gls', 'hermes')
   OR `code` LIKE '%standard%'
   OR `name` LIKE '%Standard%');

-- Relay point services (72-120h = 3-5 days)
UPDATE `myflyingbox_service` SET `delivery_delay` = '72-120h'
WHERE `delivery_delay` IS NULL
  AND (`relay_delivery` = 1
   OR `code` LIKE '%relay%'
   OR `code` LIKE '%relais%'
   OR `code` LIKE '%pickup%'
   OR `code` LIKE '%point%'
   OR `name` LIKE '%Relais%'
   OR `name` LIKE '%Point%');

-- Mondial Relay / Colis Prive (96-144h = 4-6 days)
UPDATE `myflyingbox_service` SET `delivery_delay` = '96-144h'
WHERE `delivery_delay` IS NULL
  AND `carrier_code` IN ('mondial_relay', 'colis_prive', 'shop2shop');

-- Default for remaining services (72-120h = 3-5 days)
UPDATE `myflyingbox_service` SET `delivery_delay` = '72-120h'
WHERE `delivery_delay` IS NULL;
