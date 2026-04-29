-- Run once on existing databases (MySQL/MariaDB 10.2+)

CREATE TABLE IF NOT EXISTS app_branding (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  app_name VARCHAR(255) NOT NULL DEFAULT 'Gated Document Signing',
  visitor_tagline VARCHAR(255) NOT NULL DEFAULT 'Secure project access',
  admin_tagline VARCHAR(255) NOT NULL DEFAULT 'Administrator',
  logo_path TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_branding (id, app_name, visitor_tagline, admin_tagline, logo_path, updated_at)
VALUES (1, 'Gated Document Signing', 'Secure project access', 'Administrator', NULL, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE id = id;
