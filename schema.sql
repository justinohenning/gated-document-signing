-- Gated Document Signing schema (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_branding (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  app_name VARCHAR(255) NOT NULL DEFAULT 'Gated Document Signing',
  visitor_tagline VARCHAR(255) NOT NULL DEFAULT 'Secure project access',
  admin_tagline VARCHAR(255) NOT NULL DEFAULT 'Administrator',
  logo_path TEXT NULL,
  funding_progress_color VARCHAR(16) NULL DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_branding (id, app_name, visitor_tagline, admin_tagline, logo_path, funding_progress_color, updated_at)
VALUES (1, 'Gated Document Signing', 'Secure project access', 'Administrator', NULL, NULL, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  token VARCHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  allow_downloads TINYINT(1) NOT NULL DEFAULT 1,
  watermark_enabled TINYINT(1) NOT NULL DEFAULT 0,
  watermark_image_name VARCHAR(255) NULL,
  watermark_image_path TEXT NULL,
  welcome_enabled TINYINT(1) NOT NULL DEFAULT 0,
  welcome_message TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nda_templates (
  project_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (project_id),
  CONSTRAINT fk_nda_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NDA field placement (admin places fields onto PDF pages)
CREATE TABLE IF NOT EXISTS nda_field_defs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(32) NOT NULL, -- signature, signed_date, signer_name, signer_position, signer_address, free_text
  field_label VARCHAR(64) NULL,
  page_num INT UNSIGNED NOT NULL DEFAULT 1,
  -- Normalized coordinates (0..1) relative to rendered page width/height
  x DECIMAL(8,6) NOT NULL,
  y DECIMAL(8,6) NOT NULL,
  w DECIMAL(8,6) NOT NULL,
  h DECIMAL(8,6) NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_nda_fields_project (project_id),
  CONSTRAINT fk_nda_fields_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NULL DEFAULT NULL, -- admin-set display name (null = use original_name)
  stored_path TEXT NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL DEFAULT NULL,        -- soft-delete; preserves analytics data
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_files_project (project_id),
  CONSTRAINT fk_files_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signatures (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  signer_email VARCHAR(255) NOT NULL,
  signer_name VARCHAR(255) NOT NULL,
  signer_position VARCHAR(255) NOT NULL,
  signer_address VARCHAR(512) NOT NULL DEFAULT '',
  signed_at DATETIME NOT NULL,
  ip_address VARCHAR(64) NOT NULL DEFAULT '',
  user_agent TEXT NOT NULL,
  signature_image_path TEXT NOT NULL,
  signed_receipt_path TEXT NOT NULL,
  signed_pdf_path TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_signature_project_email (project_id, signer_email),
  KEY idx_signatures_project (project_id),
  CONSTRAINT fk_signatures_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  signer_email VARCHAR(255) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access_token_hash (project_id, token_hash),
  KEY idx_access_tokens_project_email (project_id, signer_email),
  CONSTRAINT fk_access_tokens_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time magic links to verify visitor email before session access
CREATE TABLE IF NOT EXISTS email_verify_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evt_project_hash (project_id, token_hash),
  KEY idx_evt_expires (expires_at),
  KEY idx_evt_project_email (project_id, email),
  CONSTRAINT fk_evt_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics: page timing and events (time-on-page)
CREATE TABLE IF NOT EXISTS analytics_page_views (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  signer_email VARCHAR(255) NULL,
  session_id CHAR(36) NOT NULL,
  view_id VARCHAR(64) NOT NULL,
  page_key VARCHAR(64) NOT NULL,
  path VARCHAR(255) NOT NULL,
  referrer VARCHAR(255) NOT NULL DEFAULT '',
  user_agent TEXT NOT NULL,
  ip_address VARCHAR(64) NOT NULL DEFAULT '',
  started_at DATETIME NOT NULL,
  last_heartbeat_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_apv_view (project_id, session_id, view_id),
  KEY idx_apv_project_time (project_id, started_at),
  KEY idx_apv_project_email (project_id, signer_email),
  KEY idx_apv_project_page (project_id, page_key),
  CONSTRAINT fk_apv_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  signer_email VARCHAR(255) NULL,
  session_id CHAR(36) NOT NULL,
  event_key VARCHAR(64) NOT NULL,
  page_key VARCHAR(64) NOT NULL,
  path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  payload_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_ae_project_time (project_id, created_at),
  KEY idx_ae_project_email (project_id, signer_email),
  KEY idx_ae_project_event (project_id, event_key),
  CONSTRAINT fk_ae_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Investment module (per-project funding commitments + contract)
CREATE TABLE IF NOT EXISTS investment_settings (
  project_id INT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  goal_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  goal_currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  min_commitment DECIMAL(15,2) NULL DEFAULT NULL,
  equity_offered_pct DECIMAL(8,4) NULL DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id),
  CONSTRAINT fk_inv_settings_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_contracts (
  project_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (project_id),
  CONSTRAINT fk_inv_contract_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_contract_fields (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(32) NOT NULL,
  field_label VARCHAR(64) NULL,
  page_num INT UNSIGNED NOT NULL DEFAULT 1,
  x DECIMAL(8,6) NOT NULL,
  y DECIMAL(8,6) NOT NULL,
  w DECIMAL(8,6) NOT NULL,
  h DECIMAL(8,6) NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_inv_fields_project (project_id),
  CONSTRAINT fk_inv_fields_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_commitments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  signer_email VARCHAR(255) NOT NULL,
  signer_name VARCHAR(255) NOT NULL DEFAULT '',
  signer_position VARCHAR(255) NOT NULL DEFAULT '',
  signer_address VARCHAR(512) NOT NULL DEFAULT '',
  committed_amount DECIMAL(15,2) NOT NULL,
  currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  committed_at DATETIME NOT NULL,
  ip_address VARCHAR(64) NOT NULL DEFAULT '',
  user_agent TEXT NOT NULL,
  signature_image_path TEXT NOT NULL,
  signed_receipt_path TEXT NOT NULL,
  signed_pdf_path TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_commit_project_email (project_id, signer_email),
  KEY idx_inv_commit_project (project_id),
  CONSTRAINT fk_inv_commit_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_waitlist (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(64) NOT NULL,
  address VARCHAR(768) NOT NULL,
  desired_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  desired_currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_waitlist_project_email (project_id, email),
  KEY idx_inv_waitlist_project (project_id),
  CONSTRAINT fk_inv_waitlist_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_allocations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  category VARCHAR(32) NOT NULL,
  label VARCHAR(160) NOT NULL,
  percent DECIMAL(8,4) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inv_alloc_project (project_id),
  CONSTRAINT fk_inv_alloc_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

