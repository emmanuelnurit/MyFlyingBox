-- MyFlyingBox 1.4.0 - Email Template Management
-- Add table for customizable email templates

CREATE TABLE IF NOT EXISTS `myflyingbox_email_template` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL COMMENT 'Template type: shipped, delivered',
    `locale` VARCHAR(10) NOT NULL COMMENT 'Locale code: fr_FR, en_US',
    `name` VARCHAR(100) NOT NULL COMMENT 'Admin-friendly template name',
    `subject` VARCHAR(255) NOT NULL COMMENT 'Email subject with placeholders',
    `html_content` LONGTEXT NOT NULL COMMENT 'HTML email body with placeholders',
    `text_content` LONGTEXT COMMENT 'Plain text fallback (auto-generated if empty)',
    `is_active` TINYINT(1) DEFAULT 1,
    `is_default` TINYINT(1) DEFAULT 0 COMMENT 'Shipped with module, restorable',
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE KEY `myflyingbox_email_template_unique` (`code`, `locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
