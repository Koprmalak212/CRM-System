<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/core/layout.php';
$admin = require_login();
$pdo = db();

// Tabs and who may open them. This is enforced here on the server; hiding a tab in the menu is only a convenience.
$tabs = ['account' => 'Account'];
if (integrations_level() !== 'none' && module_enabled('health')) { $tabs['integrations'] = 'Integrations'; }
if (is_admin()) {
    $tabs['team'] = 'Team & access'; $tabs['company'] = 'Company details'; $tabs['email'] = 'Email';
    if (module_enabled('tickets')) { $tabs['tickets'] = 'Tickets'; }
    if (module_enabled('uptime')) { $tabs['kuma'] = 'Kuma API Key'; }
    if (module_any('calls', 'links')) { $tabs['connectors'] = 'Connectors'; }
    $tabs['modules'] = 'Modules'; $tabs['tests'] = 'Tests';
}

$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'account');
if (!in_array($tab, ['account', 'integrations', 'team', 'company', 'email', 'tickets', 'kuma', 'connectors', 'modules', 'tests'], true)) { $tab = 'account'; }
if (!isset($tabs[$tab])) {
    foreach (['integrations' => ['health'], 'kuma' => ['uptime'], 'tickets' => ['tickets'], 'connectors' => ['calls', 'links']] as $t => $mods) {
        if ($tab === $t && !module_any(...$mods)) { require_module($mods[0]); }   // the tab's module is off: 404, not "no access"
    }
    deny_access('settings:' . $tab);
}

require __DIR__ . '/../includes/settings/' . $tab . '.php';   // defines tab_handle() and tab_render() for this tab
if (function_exists('tab_early') && $_SERVER['REQUEST_METHOD'] === 'GET') { tab_early($pdo, $admin); }   // downloads that must send headers before any page output

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    tab_handle($pdo, $admin);
    redirect($GLOBALS['tab_redirect'] ?? ('settings/settings.php?tab=' . $tab));
}

$lede = [
    'account' => 'Your account and the environment this console is running on.',
    'integrations' => 'Connect health checks to your own services, and let other tools read the health status.',
    'team' => 'Who can sign in, and who may use the integrations. Only admins see this tab.',
    'company' => 'The details printed on every invoice and PDF. Only admins see this tab.',
    'email' => 'How the system sends email, and the outbox of every message. Only admins see this tab.',
    'tickets' => 'How fast tickets must be answered and resolved (SLA), and the mailbox that turns email into tickets. Only admins see this tab.',
    'kuma' => 'Connect Uptime Kuma so its monitors show on the Uptime page. Only admins see this tab.',
    'connectors' => 'Connect 3CX, Yeastar, Twilio and Short.io: encrypted credentials, a test button and a sync. Only admins see this tab.',
    'modules' => 'Turn the parts of the system on or off for this install. Only admins see this tab.',
    'tests' => 'Documentation of the tests that were performed, what they found and how it was fixed. Only admins see this tab.',
][$tab];
page_start('Settings', 'settings', $lede);
?>
<nav class="rise mb-6 flex flex-wrap gap-2" aria-label="Settings sections">
  <?php foreach ($tabs as $key => $label): $on = $key === $tab; ?>
    <a href="settings/settings.php?tab=<?= e($key) ?>" <?= $on ? 'aria-current="page"' : '' ?>
       class="inline-flex h-10 items-center rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php
tab_render($pdo, $admin);
page_end();
