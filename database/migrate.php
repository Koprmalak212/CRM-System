<?php
declare(strict_types=1);

/*
 * Brings an EXISTING database up to date with database/schema.sql, keeping all data.
 *
 *   C:\xampp\php\php.exe database\migrate.php
 *
 * Runs as root (the web app's own database account may not ALTER tables). Safe to run repeatedly: every step first
 * checks whether it is already done. New installs do not need this; bin/setup.php loads schema.sql directly.
 *   - new tables: every "CREATE TABLE IF NOT EXISTS" statement in schema.sql is run
 *   - changes to existing tables: the steps below, one block per build stage
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . (getenv('MIGRATE_DB') ?: 'ops_console') . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '" . date('P') . "'");

function col_exists(PDO $p, string $t, string $c): bool
{
    $s = $p->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $s->execute([$t, $c]);
    return (int) $s->fetchColumn() > 0;
}
function index_exists(PDO $p, string $t, string $i): bool
{
    $s = $p->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $s->execute([$t, $i]);
    return (int) $s->fetchColumn() > 0;
}
function say(string $m): void { echo "  $m\n"; }

// ---- new tables
$sql = (string) file_get_contents(__DIR__ . '/schema.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
    $stmt = trim($stmt);
    if (stripos($stmt, 'CREATE TABLE IF NOT EXISTS') === 0) {
        $pdo->exec($stmt);
        preg_match('/EXISTS\s+(\w+)/i', $stmt, $m);
        say('table ' . $m[1] . ' ready');
    }
}

// ---- seed rows that are safe to repeat (INSERT IGNORE)
foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
    if (stripos(trim($stmt), 'INSERT IGNORE INTO') === 0) { $pdo->exec(trim($stmt)); say('seed row checked'); }
}

// ---- Stage 1: companies
if (!col_exists($pdo, 'users', 'company_id')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN company_id INT UNSIGNED NULL, ADD KEY idx_user_company (company_id), ADD CONSTRAINT fk_user_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT');
    say('users.company_id added');
}
if (col_exists($pdo, 'users', 'company')) {
    // each distinct old company name becomes a company row; its users are pointed at it; then the text column goes
    $names = $pdo->query("SELECT company, MAX(is_demo) d FROM users WHERE company <> '' GROUP BY company")->fetchAll();
    $ins = $pdo->prepare('INSERT INTO companies (name, is_demo) VALUES (?, ?)');
    $upd = $pdo->prepare('UPDATE users SET company_id = ? WHERE company = ?');
    foreach ($names as $n) { $ins->execute([$n['company'], $n['d']]); $upd->execute([$pdo->lastInsertId(), $n['company']]); }
    $pdo->exec('ALTER TABLE users DROP INDEX idx_company, DROP COLUMN company');
    say('users.company migrated: ' . count($names) . ' companies created, text column dropped');
}
if (!col_exists($pdo, 'invoices', 'company_id')) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN company_id INT UNSIGNED NULL, ADD KEY idx_inv_company (company_id), ADD CONSTRAINT fk_inv_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL');
    say('invoices.company_id added');
}

// ---- Stage 7: two-factor columns on admins
if (!col_exists($pdo, 'admins', 'totp_enabled')) {
    $pdo->exec('ALTER TABLE admins ADD COLUMN totp_secret_enc TEXT NULL, ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN totp_last_step BIGINT UNSIGNED NULL');
    say('admins.totp_* added');
}

// ---- Stage 8: session epoch on admins
if (!col_exists($pdo, 'admins', 'session_epoch')) {
    $pdo->exec('ALTER TABLE admins ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0');
    say('admins.session_epoch added');
}

// ---- Stage 10: invoices can belong to a recurring schedule
if (!col_exists($pdo, 'invoices', 'recurring_id')) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN recurring_id INT UNSIGNED NULL, ADD UNIQUE KEY uq_inv_recurring (recurring_id, issue_date), ADD CONSTRAINT fk_inv_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_invoices(id) ON DELETE SET NULL');
    say('invoices.recurring_id added');
}

// ---- Follow-up: editable notes, per-person notification emails
if (!col_exists($pdo, 'notes', 'edited_at')) { $pdo->exec('ALTER TABLE notes ADD COLUMN edited_at DATETIME NULL'); say('notes.edited_at added'); }
if (!col_exists($pdo, 'admins', 'notify_optin')) { $pdo->exec('ALTER TABLE admins ADD COLUMN notify_optin TINYINT(1) NOT NULL DEFAULT 0'); say('admins.notify_optin added'); }

// ---- Hardening: lockout per account counts failures from every address
if (!index_exists($pdo, 'login_attempts', 'idx_user_time')) { $pdo->exec('ALTER TABLE login_attempts ADD KEY idx_user_time (username, attempted_at)'); say('login_attempts.idx_user_time added'); }

echo "Database is up to date.\n";
