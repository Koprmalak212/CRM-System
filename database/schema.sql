CREATE TABLE admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  perm_integrations ENUM('none','view','manage') NOT NULL DEFAULT 'none',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  avatar VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  role ENUM('admin','editor','support','viewer') NOT NULL DEFAULT 'viewer',
  status ENUM('active','pending','suspended') NOT NULL DEFAULT 'active',
  country VARCHAR(60) NOT NULL,
  logins INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_status (status),
  KEY idx_role (role)
) ENGINE=InnoDB;

CREATE TABLE connections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  device VARCHAR(120) NOT NULL,
  location VARCHAR(80) NOT NULL,
  protocol ENUM('HTTPS','WebSocket','SSH') NOT NULL,
  status ENUM('active','idle','flagged') NOT NULL,
  started_at DATETIME NOT NULL,
  last_activity DATETIME NOT NULL,
  bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_status (status),
  CONSTRAINT fk_conn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE service_checks (
  service_id INT UNSIGNED NOT NULL,
  slot TINYINT UNSIGNED NOT NULL,
  status ENUM('ok','degraded','outage') NOT NULL,
  PRIMARY KEY (service_id, slot),
  CONSTRAINT fk_check_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  type VARCHAR(60) NOT NULL,
  status ENUM('ready','running','failed','scheduled') NOT NULL,
  size_kb INT UNSIGNED NOT NULL,
  author VARCHAR(80) NOT NULL,
  generated_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE backups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  type ENUM('full','incremental','database') NOT NULL,
  status ENUM('completed','running','failed') NOT NULL,
  size_mb INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  duration_s INT UNSIGNED NOT NULL,
  location VARCHAR(60) NOT NULL,
  KEY idx_started (started_at)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level ENUM('info','warning','error','critical') NOT NULL,
  actor VARCHAR(190) NOT NULL,
  action VARCHAR(160) NOT NULL,
  target VARCHAR(120) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_created (created_at),
  KEY idx_level (level)
) ENGINE=InnoDB;

CREATE TABLE invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  customer VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  customer_address VARCHAR(255) NOT NULL DEFAULT '',
  supply_date DATE NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  vat_rate TINYINT UNSIGNED NOT NULL DEFAULT 21,
  subtotal_cents INT UNSIGNED NOT NULL,
  vat_cents INT UNSIGNED NOT NULL,
  total_cents INT UNSIGNED NOT NULL,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY idx_issue (issue_date),
  KEY idx_due (due_date)
) ENGINE=InnoDB;

CREATE TABLE invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  description VARCHAR(200) NOT NULL,
  qty DECIMAL(8,2) NOT NULL,
  unit_cents INT UNSIGNED NOT NULL,
  CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  method ENUM('iDEAL','Bank transfer','Credit card','Direct debit') NOT NULL,
  paid_at DATE NOT NULL,
  reference VARCHAR(60) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL,
  KEY idx_ip_time (ip, attempted_at),
  KEY idx_ip_user_time (ip, username, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE rate_limits (
  ip VARCHAR(45) NOT NULL,
  bucket VARCHAR(20) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (ip, bucket, window_start)
) ENGINE=InnoDB;

CREATE TABLE monitored_endpoints (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  url VARCHAR(500) NOT NULL,
  method ENUM('GET','HEAD') NOT NULL DEFAULT 'GET',
  expected_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  timeout_s TINYINT UNSIGNED NOT NULL DEFAULT 5,
  slow_ms INT UNSIGNED NOT NULL DEFAULT 1500,
  interval_min SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  auth_header VARCHAR(60) NOT NULL DEFAULT '',
  auth_secret_enc TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_state ENUM('up','slow','down','unknown') NOT NULL DEFAULT 'unknown',
  last_status_code SMALLINT UNSIGNED NULL,
  last_latency_ms INT UNSIGNED NULL,
  last_error VARCHAR(160) NOT NULL DEFAULT '',
  last_checked_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE endpoint_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  endpoint_id INT UNSIGNED NOT NULL,
  checked_at DATETIME NOT NULL,
  state ENUM('up','slow','down') NOT NULL,
  status_code SMALLINT UNSIGNED NULL,
  latency_ms INT UNSIGNED NULL,
  error VARCHAR(160) NOT NULL DEFAULT '',
  KEY idx_ep_time (endpoint_id, checked_at),
  CONSTRAINT fk_check_ep FOREIGN KEY (endpoint_id) REFERENCES monitored_endpoints(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  key_prefix VARCHAR(12) NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  key_enc TEXT NULL,
  scope VARCHAR(30) NOT NULL DEFAULT 'health:read',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL
) ENGINE=InnoDB;

-- Demo-data tagging: rows loaded by "Load demo data" carry is_demo = 1, real rows keep 0. Removal only deletes tagged rows.
ALTER TABLE users ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE connections ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE services ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE payments ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE reports ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE backups ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE monitored_endpoints ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE activity_logs ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE app_meta (
  k VARCHAR(40) NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Settings > Tests: documentation of tests, results and fixes
CREATE TABLE test_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(20) NULL UNIQUE,
  tested_on DATE NOT NULL,
  area VARCHAR(40) NOT NULL,
  test VARCHAR(200) NOT NULL,
  result ENUM('pass','fixed','open','info') NOT NULL,
  details TEXT NOT NULL,
  fix TEXT NOT NULL,
  tester VARCHAR(80) NOT NULL,
  source ENUM('baseline','manual') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_area (area), KEY idx_result (result)
) ENGINE=InnoDB;

-- Settings > Kuma API Key: one row (id = 1). The API key is stored encrypted (AES-256-GCM); key_last4 is only for the masked display.
CREATE TABLE kuma_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  kuma_url VARCHAR(300) NOT NULL DEFAULT '',
  api_key_enc TEXT NULL,
  key_last4 CHAR(4) NULL,
  poll_interval_s SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB;

-- Uptime page history: one sample per monitor per minute (and one whenever the status changes), kept 30 days.
CREATE TABLE kuma_heartbeats (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  monitor_key CHAR(16) NOT NULL,
  monitor_name VARCHAR(160) NOT NULL,
  status TINYINT NOT NULL,
  response_ms INT UNSIGNED NULL,
  important TINYINT(1) NOT NULL DEFAULT 0,
  at DATETIME NOT NULL,
  KEY idx_key_at (monitor_key, at),
  KEY idx_important (important, at)
) ENGINE=InnoDB;

-- Job title and phone for each user (shown on the user's own page).
ALTER TABLE users ADD COLUMN job_title VARCHAR(80) NOT NULL DEFAULT '', ADD COLUMN phone VARCHAR(40) NOT NULL DEFAULT '';

-- Stage 1: companies as their own section. Users and invoices point at a company.
-- (Everything below uses IF NOT EXISTS so database/migrate.php can apply it to a live database; see that file for the ALTERs.)
CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(160) NOT NULL DEFAULT '',
  postcode VARCHAR(12) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '',
  country VARCHAR(60) NOT NULL DEFAULT 'Netherlands',
  kvk VARCHAR(12) NOT NULL DEFAULT '',
  vat_number VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(40) NOT NULL DEFAULT '',
  website VARCHAR(190) NOT NULL DEFAULT '',
  notes VARCHAR(1000) NOT NULL DEFAULT '',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_company_name (name),
  KEY idx_company_kvk (kvk)
) ENGINE=InnoDB;
ALTER TABLE users ADD COLUMN company_id INT UNSIGNED NULL, ADD KEY idx_user_company (company_id), ADD CONSTRAINT fk_user_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT;
ALTER TABLE invoices ADD COLUMN company_id INT UNSIGNED NULL, ADD KEY idx_inv_company (company_id), ADD CONSTRAINT fk_inv_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL;

-- Stage 2: notes and activity timeline on users and companies (about_type + about_id; no foreign key because it points at two tables).
CREATE TABLE IF NOT EXISTS notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  about_type ENUM('user','company') NOT NULL,
  about_id INT UNSIGNED NOT NULL,
  kind ENUM('note','call','email','meeting') NOT NULL DEFAULT 'note',
  body VARCHAR(2000) NOT NULL,
  author VARCHAR(60) NOT NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_note_about (about_type, about_id, created_at)
) ENGINE=InnoDB;

-- Stage 3: tickets (support requests) with a comment thread.
CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(16) NOT NULL UNIQUE,
  company_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  subject VARCHAR(160) NOT NULL,
  description VARCHAR(2000) NOT NULL DEFAULT '',
  status ENUM('open','in_progress','waiting','resolved','closed') NOT NULL DEFAULT 'open',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assignee_id INT UNSIGNED NULL,
  created_by VARCHAR(60) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_ticket_status (status),
  KEY idx_ticket_company (company_id),
  KEY idx_ticket_user (user_id),
  KEY idx_ticket_assignee (assignee_id),
  CONSTRAINT fk_ticket_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ticket_assignee FOREIGN KEY (assignee_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  author VARCHAR(60) NOT NULL,
  body VARCHAR(2000) NOT NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tc_ticket (ticket_id, created_at),
  CONSTRAINT fk_tc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Stage 6: notifications. dedupe_key is the event itself (for example invoice-overdue:12), so one event is announced once.
-- emailed_at is filled by the mailer in stage 8.
CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL,
  dedupe_key VARCHAR(120) NOT NULL UNIQUE,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(300) NOT NULL DEFAULT '',
  link VARCHAR(200) NOT NULL DEFAULT '',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  emailed_at DATETIME NULL,
  KEY idx_notif_created (created_at),
  KEY idx_notif_read (read_at)
) ENGINE=InnoDB;
INSERT IGNORE INTO app_meta (k, v) VALUES ('notify_email', 'denis@telecomlijn.nl');

-- Stage 7: two-factor sign-in. The secret is stored encrypted (AES-256-GCM, the app key); totp_last_step stops a code being used twice.
ALTER TABLE admins ADD COLUMN totp_secret_enc TEXT NULL, ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN totp_last_step BIGINT UNSIGNED NULL;
-- One-time recovery codes, stored only as keyed hashes (HMAC-SHA256 with the app key).
CREATE TABLE IF NOT EXISTS admin_recovery_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  KEY idx_rc_admin (admin_id, code_hash),
  CONSTRAINT fk_rc_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;
