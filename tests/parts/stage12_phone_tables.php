<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 12 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 12: phone layout for tables
    // =========================================================
    section('35. Phone tables: assets and markup');
    $cssF = TESTS_DIR . '/../assets/css/cards.css'; $jsF = TESTS_DIR . '/../assets/js/cards.js';
    check(is_file($cssF) && is_file($jsF), 'assets/css/cards.css and assets/js/cards.js exist');
    $cardsCss = (string) @file_get_contents($cssF); $cardsJs = (string) @file_get_contents($jsF);
    check(str_contains($cardsCss, '@media (max-width: 639.98px)'), 'the card layout applies only below 640 px');
    check(str_contains($cardsCss, 'content: attr(data-label)') && str_contains($cardsCss, 'min-width: 0 !important') && str_contains($cardsCss, 'overflow-x: visible'), 'the column name is shown beside each value, table minimum widths are cancelled and the scroll box no longer scrolls sideways');
    check(str_contains($cardsCss, 'table.rt thead th.rt-sort') && str_contains($cardsCss, 'Sort by'), 'the sort links of the header stay available as a row of chips above the cards');
    check(str_contains($cardsCss, 'rgb(var(--c-line))') && !preg_match('/#[0-9A-Fa-f]{3,6}\b/', $cardsCss), 'the cards use the theme colours only, so they follow light and dark mode');
    check(str_contains($cardsJs, "setAttribute('role', 'table')") && str_contains($cardsJs, "'columnheader'") && str_contains($cardsJs, "'rowheader'") && str_contains($cardsJs, "'cell'") && str_contains($cardsJs, "'rowgroup'") && str_contains($cardsJs, "'row'"), 'the script gives every table explicit ARIA roles (table, rowgroup, row, columnheader, rowheader, cell) so screen readers keep reading it as a table');
    check(str_contains($cardsJs, "data-label") && str_contains($cardsJs, 'colSpan') && str_contains($cardsJs, 'MutationObserver') && str_contains($cardsJs, 'data-plain'), 'labels follow header colspans, tables added later by scripts are handled, and data-plain opts out');
    $lay = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    check(str_contains($lay, 'assets/css/cards.css') && str_contains($lay, 'assets/js/cards.js'), 'every dashboard page loads the card styles and script');
    same(http('GET', "$base/assets/css/cards.css")['code'], 200, 'the stylesheet is served');
    same(http('GET', "$base/assets/js/cards.js")['code'], 200, 'and the script');
    $firstUser = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn(); $firstCo = (int) $pdo->query('SELECT MIN(id) FROM companies')->fetchColumn(); $firstInv = (int) $pdo->query('SELECT MIN(id) FROM invoices')->fetchColumn();
    $tablePages = ['users/users.php', 'companies/companies.php', 'tickets/tickets.php', 'invoices/invoices.php', 'system/logs.php', 'system/reports.php', 'system/backups.php', 'monitoring/connections.php', 'invoices/recurring.php', 'settings/settings.php?tab=team', 'settings/settings.php?tab=integrations', 'settings/settings.php?tab=tests', "users/user.php?id=$firstUser", "companies/company.php?id=$firstCo", "invoices/invoice.php?id=$firstInv"];
    $noHead = []; $noScope = []; $count = 0;
    foreach ($tablePages as $pg) {
        $b = http('GET', "$base/$pg", ['jar' => $admin])['body'];
        preg_match_all('#<table\b(.*?)</table>#s', $b, $tm);
        foreach ($tm[0] as $tb) {
            $count++;
            if (!str_contains($tb, '<thead')) { $noHead[] = $pg; }
            elseif (preg_match('#<thead.*?</thead>#s', $tb, $th) && preg_match('/<th(?=[\s>])(?![^>]*scope="col")[^>]*>/', $th[0])) { $noScope[] = $pg; }
        }
    }
    check($count >= 8 && !$noHead, "all $count tables on " . count($tablePages) . ' pages have a header row (the card labels come from it)', implode(', ', array_unique($noHead)));
    check(!$noScope, 'and every header cell is marked scope="col"', implode(', ', array_unique($noScope)));
    $plain = 0; foreach (['index.php'] as $pg) { $plain += substr_count(http('GET', "$base/$pg", ['jar' => $admin])['body'], 'data-plain'); }
    same($plain, substr_count(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'data-empty="1"') === 4 ? 0 : 4, 'only the four tiny two-column chart tables on the Overview opt out of the card layout (none when the charts are empty)');
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'role="search" class="relative min-w-0'), 'the top-bar search can shrink, so the bar (bell, theme button, language) fits on a phone');

    section('36. Passwords and secrets at rest');
    same((int) $pdo->query("SELECT COUNT(*) FROM admins WHERE password_hash NOT LIKE '$argon2id$%' AND password_hash NOT LIKE '$2y$%'")->fetchColumn(), 0, 'every account password is stored as an Argon2id (or bcrypt) hash, never as text');
    same((int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE token_hash NOT REGEXP '^[a-f0-9]{64}$'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM invitations WHERE token_hash NOT REGEXP '^[a-f0-9]{64}$'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM admin_recovery_codes WHERE code_hash NOT REGEXP '^[a-f0-9]{64}$'")->fetchColumn(), 0, 'reset tokens, invitation tokens and recovery codes are stored only as 64-character hashes');
    $tp = $post($admin, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'add_user', 'username' => 'zz.tmp', 'name' => 'Tmp', 'email' => 'zz.tmp@example.test', 'role' => 'user', 'perm' => 'none'], 'settings/settings.php?tab=team');
    $shown = preg_match('/select-all">([^<]+)</', http('GET', "$base/settings/settings.php?tab=team", ['jar' => $admin])['body'], $tm) ? html_entity_decode($tm[1]) : '';
    check(strlen($shown) >= 12 && password_verify($shown, (string) $pdo->query("SELECT password_hash FROM admins WHERE username = 'zz.tmp'")->fetchColumn()), 'a temporary password is shown once and only its hash is stored');
    check(!str_contains((string) $pdo->query("SELECT GROUP_CONCAT(CONCAT(action, target)) FROM activity_logs WHERE target = 'zz.tmp' OR action LIKE 'Team member%'")->fetchColumn(), $shown), 'and it never appears in the activity log');
    $pdo->exec("DELETE FROM admins WHERE username = 'zz.tmp'"); $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND target LIKE 'zz.tmp%'");
    $teamSrc = (string) file_get_contents(TESTS_DIR . '/../includes/settings/team.php');
    check(str_contains($teamSrc, '\'password_enc\' => secret_encrypt($pw)') && !str_contains($teamSrc, '\'password\' => $pw'), 'while it waits to be shown, the temporary password is encrypted in the session, not plain text');
