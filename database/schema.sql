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
  KEY idx_ip_user_time (ip, username, attempted_at),
  KEY idx_user_time (username, attempted_at)
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

-- Stage 8: email, password reset and invitations.
-- Settings > Email: one row (id = 1). The SMTP password is stored encrypted. Left blank until someone enters it.
CREATE TABLE IF NOT EXISTS mail_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  host VARCHAR(190) NOT NULL DEFAULT '',
  port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  encryption ENUM('none','starttls','ssl') NOT NULL DEFAULT 'starttls',
  username VARCHAR(190) NOT NULL DEFAULT '',
  password_enc TEXT NULL,
  from_email VARCHAR(190) NOT NULL DEFAULT '',
  from_name VARCHAR(80) NOT NULL DEFAULT 'Telecomlijn Admin',
  base_url VARCHAR(190) NOT NULL DEFAULT '',
  updated_at DATETIME NULL
) ENGINE=InnoDB;
-- Every message the system sends or tries to send. On a computer without a mail server this is where it can be read.
CREATE TABLE IF NOT EXISTS mail_outbox (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL DEFAULT 'other',
  to_email VARCHAR(190) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  status ENUM('recorded','sent','failed') NOT NULL DEFAULT 'recorded',
  error VARCHAR(200) NOT NULL DEFAULT '',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  KEY idx_mail_created (created_at)
) ENGINE=InnoDB;
-- Password-reset links: only the hash of the random token is stored, valid for one hour, usable once.
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reset_admin (admin_id, created_at),
  CONSTRAINT fk_reset_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- Invitations to join the team: the person opens the link, picks a password and an account is created with the role and permission set here.
CREATE TABLE IF NOT EXISTS invitations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  username VARCHAR(30) NOT NULL,
  name VARCHAR(80) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  perm_integrations ENUM('none','view','manage') NOT NULL DEFAULT 'none',
  token_hash CHAR(64) NOT NULL UNIQUE,
  invited_by VARCHAR(60) NOT NULL DEFAULT '',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inv_email (email)
) ENGINE=InnoDB;
-- Raised when a password is reset, so every other signed-in session of that person ends.
ALTER TABLE admins ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0;

-- Stage 10: supplier details for invoices and PDFs (BTW number and IBAN stay empty until someone enters them),
-- and recurring invoices. A unique key on (recurring_id, issue_date) means a period can never be invoiced twice.
CREATE TABLE IF NOT EXISTS company_profile (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(80) NOT NULL DEFAULT '',
  address1 VARCHAR(80) NOT NULL DEFAULT '',
  address2 VARCHAR(80) NOT NULL DEFAULT '',
  address3 VARCHAR(80) NOT NULL DEFAULT '',
  kvk VARCHAR(8) NOT NULL DEFAULT '',
  btw_number VARCHAR(20) NOT NULL DEFAULT '',
  iban VARCHAR(34) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(40) NOT NULL DEFAULT '',
  updated_at DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS recurring_invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  description VARCHAR(200) NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  vat_rate TINYINT UNSIGNED NOT NULL DEFAULT 21,
  frequency ENUM('month','quarter','year') NOT NULL DEFAULT 'month',
  next_date DATE NOT NULL,
  anchor_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
  terms_days TINYINT UNSIGNED NOT NULL DEFAULT 14,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_run DATE NULL,
  created_by VARCHAR(60) NOT NULL DEFAULT '',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rec_due (active, next_date),
  CONSTRAINT fk_rec_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;
ALTER TABLE invoices ADD COLUMN recurring_id INT UNSIGNED NULL, ADD UNIQUE KEY uq_inv_recurring (recurring_id, issue_date), ADD CONSTRAINT fk_inv_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_invoices(id) ON DELETE SET NULL;

-- Follow-up: notes can be edited (and show it) or deleted by their author or an admin; each person can opt in to notification emails.
ALTER TABLE notes ADD COLUMN edited_at DATETIME NULL;
ALTER TABLE admins ADD COLUMN notify_optin TINYINT(1) NOT NULL DEFAULT 0;

-- Calendar: appointments with a company and, optionally, one of its people. Deleting the company removes its appointments;
-- deleting the person only clears the contact.
CREATE TABLE IF NOT EXISTS appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  location VARCHAR(160) NOT NULL DEFAULT '',
  description VARCHAR(1000) NOT NULL DEFAULT '',
  status ENUM('scheduled','done','cancelled') NOT NULL DEFAULT 'scheduled',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  created_by VARCHAR(60) NOT NULL DEFAULT '',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_appt_time (starts_at),
  KEY idx_appt_company (company_id, starts_at),
  KEY idx_appt_user (user_id),
  CONSTRAINT fk_appt_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Connectors (3CX, Yeastar, Twilio, Short.io): one row per service. The secret (client secret, auth token, API key) is stored encrypted
-- (AES-256-GCM) and never shown again; only its last four characters are kept for display.
CREATE TABLE IF NOT EXISTS connectors (
  provider VARCHAR(20) NOT NULL PRIMARY KEY,            -- threecx | yeastar | twilio | shortio
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  base_url VARCHAR(300) NOT NULL DEFAULT '',             -- the PBX address; empty for the cloud services (their own address is built in)
  account_ref VARCHAR(190) NOT NULL DEFAULT '',          -- Twilio account SID, or the client ID of a PBX: not secret
  secret_enc TEXT NULL,
  secret_last4 VARCHAR(8) NOT NULL DEFAULT '',
  verify_tls TINYINT(1) NOT NULL DEFAULT 1,              -- 0 = accept a self-signed certificate (a PBX on the local network)
  keep_text TINYINT(1) NOT NULL DEFAULT 0,               -- Twilio: keep the text of messages (off by default: only who, when and status)
  last_sync_at DATETIME NULL,
  last_state VARCHAR(20) NOT NULL DEFAULT 'never',       -- never | ok | unauthorized | unreachable | invalid | busy
  last_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by VARCHAR(60) NOT NULL DEFAULT '',
  updated_at DATETIME NULL
) ENGINE=InnoDB;

-- Calls and text messages from the phone systems and Twilio, matched to a person and a company by phone number.
CREATE TABLE IF NOT EXISTS comm_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  kind ENUM('call','sms') NOT NULL,
  external_id VARCHAR(100) NOT NULL,
  direction ENUM('in','out','internal') NOT NULL,
  from_number VARCHAR(40) NOT NULL DEFAULT '',
  to_number VARCHAR(40) NOT NULL DEFAULT '',
  party_key VARCHAR(12) NOT NULL DEFAULT '',             -- last 9 digits of the number outside the company, for matching
  started_at DATETIME NOT NULL,
  duration_s INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'other',
  body VARCHAR(1000) NOT NULL DEFAULT '',
  user_id INT UNSIGNED NULL,
  company_id INT UNSIGNED NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_comm (provider, kind, external_id),
  KEY idx_comm_time (started_at),
  KEY idx_comm_company (company_id, started_at),
  KEY idx_comm_user (user_id, started_at),
  KEY idx_comm_key (party_key),
  CONSTRAINT fk_comm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_comm_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Short links from Short.io, optionally belonging to a company.
CREATE TABLE IF NOT EXISTS short_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_id VARCHAR(100) NOT NULL,
  domain VARCHAR(190) NOT NULL DEFAULT '',
  short_url VARCHAR(400) NOT NULL,
  original_url VARCHAR(2000) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  company_id INT UNSIGNED NULL,
  created_remote DATETIME NULL,
  created_by VARCHAR(60) NOT NULL DEFAULT '',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_short (external_id),
  KEY idx_short_company (company_id),
  CONSTRAINT fk_short_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- What happened at each test or sync of a connector (3CX, Yeastar, Twilio, Short.io): the result and the requests behind it (address, HTTP code,
-- error code, connection error). Never a secret. The newest 40 per service are kept, and nothing older than 90 days (bin/purge.php).
CREATE TABLE IF NOT EXISTS connector_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  action VARCHAR(10) NOT NULL,                             -- test | sync | scheduled
  state VARCHAR(20) NOT NULL,                              -- ok | unauthorized | unreachable | invalid | busy
  message VARCHAR(400) NOT NULL DEFAULT '',
  detail VARCHAR(1500) NOT NULL DEFAULT '',
  created_by VARCHAR(60) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cl_provider (provider, id),
  KEY idx_cl_created (created_at)
) ENGINE=InnoDB;

-- Contracts & subscriptions: which services a company has, the monthly price, start and end date, renewal and how it is invoiced.
-- A contract can carry its own SLA targets (minutes); when they are shorter than the standard ones they apply to the tickets of that company.
CREATE TABLE IF NOT EXISTS contracts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  company_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  status ENUM('draft','active','ended') NOT NULL DEFAULT 'active',
  start_date DATE NOT NULL,
  end_date DATE NULL,                                      -- NULL = runs until it is ended by hand
  auto_renew TINYINT(1) NOT NULL DEFAULT 0,
  renew_months SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  notice_days SMALLINT UNSIGNED NOT NULL DEFAULT 60,       -- the renewal alert is raised this many days before the end date
  billing ENUM('none','month','quarter','year') NOT NULL DEFAULT 'month',
  vat_rate TINYINT UNSIGNED NOT NULL DEFAULT 21,
  terms_days TINYINT UNSIGNED NOT NULL DEFAULT 14,
  next_invoice_date DATE NULL,
  anchor_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
  last_invoiced DATE NULL,
  sla_respond_min INT UNSIGNED NULL,
  sla_resolve_min INT UNSIGNED NULL,
  notes VARCHAR(1000) NOT NULL DEFAULT '',
  quote_id INT UNSIGNED NULL,                              -- the quote it was made from, if any
  ended_at DATETIME NULL,
  created_by VARCHAR(60) NOT NULL DEFAULT '',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ctr_company (company_id),
  KEY idx_ctr_due (status, next_invoice_date),
  KEY idx_ctr_end (status, end_date),
  CONSTRAINT fk_ctr_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- One line per service. unit_cents is the price per unit PER MONTH, excluding BTW.
CREATE TABLE IF NOT EXISTS contract_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id INT UNSIGNED NOT NULL,
  service VARCHAR(20) NOT NULL DEFAULT 'other',            -- fiber | voip_3cx | voip_yeastar | security | numbers | other
  description VARCHAR(200) NOT NULL,
  qty DECIMAL(8,2) NOT NULL,
  unit_cents INT UNSIGNED NOT NULL,
  KEY idx_ci_contract (contract_id),
  CONSTRAINT fk_ci_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- An invoice made from a contract. The unique key on (contract, issue date) means a period can never be invoiced twice.
ALTER TABLE invoices ADD COLUMN contract_id INT UNSIGNED NULL, ADD UNIQUE KEY uq_inv_contract (contract_id, issue_date), ADD CONSTRAINT fk_inv_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL;

-- Quotes (offertes): the same lines as an invoice. "expired" is not stored: it is a draft or sent quote whose valid_until has passed.
CREATE TABLE IF NOT EXISTS quotes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  company_id INT UNSIGNED NULL,
  customer VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  customer_address VARCHAR(255) NOT NULL DEFAULT '',
  title VARCHAR(160) NOT NULL DEFAULT '',
  issue_date DATE NOT NULL,
  valid_until DATE NOT NULL,
  vat_rate TINYINT UNSIGNED NOT NULL DEFAULT 21,
  subtotal_cents INT UNSIGNED NOT NULL,
  vat_cents INT UNSIGNED NOT NULL,
  total_cents INT UNSIGNED NOT NULL,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('draft','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  decided_at DATETIME NULL,
  lost_reason VARCHAR(200) NOT NULL DEFAULT '',
  invoice_id INT UNSIGNED NULL,                            -- what it became (no foreign keys: the invoice or contract may be removed later)
  contract_id INT UNSIGNED NULL,
  created_by VARCHAR(60) NOT NULL DEFAULT '',
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_quote_company (company_id),
  KEY idx_quote_status (status, valid_until),
  CONSTRAINT fk_quote_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS quote_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id INT UNSIGNED NOT NULL,
  description VARCHAR(200) NOT NULL,
  qty DECIMAL(8,2) NOT NULL,
  unit_cents INT UNSIGNED NOT NULL,
  KEY idx_qi_quote (quote_id),
  CONSTRAINT fk_qi_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- SLA: the time allowed for a first response and for resolving a ticket, per priority, in minutes (the clock runs 24 hours a day).
CREATE TABLE IF NOT EXISTS sla_policies (
  priority ENUM('low','normal','high','urgent') NOT NULL PRIMARY KEY,
  respond_min INT UNSIGNED NOT NULL,
  resolve_min INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO sla_policies (priority, respond_min, resolve_min) VALUES ('urgent', 30, 240), ('high', 120, 480), ('normal', 480, 1440), ('low', 1440, 4320);
-- Per ticket: when the first response and the solution are due, when staff first answered, the time spent waiting for the customer (the
-- clock stands still then) and when it was escalated. requester_email is the sender of a ticket that came in by email.
ALTER TABLE tickets ADD COLUMN requester_email VARCHAR(190) NOT NULL DEFAULT '', ADD COLUMN first_response_at DATETIME NULL, ADD COLUMN respond_due_at DATETIME NULL, ADD COLUMN resolve_due_at DATETIME NULL, ADD COLUMN waiting_since DATETIME NULL, ADD COLUMN sla_paused_s INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN escalated_at DATETIME NULL, ADD KEY idx_ticket_sla (resolve_due_at);
ALTER TABLE ticket_comments ADD COLUMN source ENUM('web','email') NOT NULL DEFAULT 'web';

-- Email to ticket: the mailbox that is read (one row, id = 1; the password is stored encrypted) and every message that was handled.
-- The unique message id means one email can never become two tickets or two comments.
CREATE TABLE IF NOT EXISTS inbox_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  host VARCHAR(190) NOT NULL DEFAULT '',
  port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
  encryption ENUM('ssl','starttls','none') NOT NULL DEFAULT 'ssl',
  username VARCHAR(190) NOT NULL DEFAULT '',
  password_enc TEXT NULL,
  folder VARCHAR(80) NOT NULL DEFAULT 'INBOX',
  address VARCHAR(190) NOT NULL DEFAULT '',                -- the support address customers write to; replies to them carry it as Reply-To
  auto_reply TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at DATETIME NULL,
  last_state VARCHAR(20) NOT NULL DEFAULT 'never',         -- never | ok | unauthorized | unreachable | invalid | busy
  last_error VARCHAR(200) NOT NULL DEFAULT '',
  last_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_emails (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NULL,
  message_id VARCHAR(255) NOT NULL UNIQUE,
  from_email VARCHAR(190) NOT NULL DEFAULT '',
  subject VARCHAR(190) NOT NULL DEFAULT '',
  outcome ENUM('new','reply','ignored') NOT NULL,
  note VARCHAR(120) NOT NULL DEFAULT '',
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_te_ticket (ticket_id),
  KEY idx_te_from (from_email, received_at),
  CONSTRAINT fk_te_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;
