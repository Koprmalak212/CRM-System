<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 11 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 11: light and dark theme
    // =========================================================
    require_once TESTS_DIR . '/../includes/core/theme.php';
    section('33. Theme: palette and contrast');
    same(round(theme_contrast('0 0 0', '255 255 255'), 1), 21.0, 'contrast maths: black on white is 21:1');
    same(round(theme_contrast('255 255 255', '255 255 255'), 1), 1.0, 'and a colour on itself is 1:1');
    same(round(theme_contrast('119 119 119', '255 255 255'), 2), 4.48, 'a known value: #777 on white is 4.48:1 (just under AA)');
    same(THEME_DARK['ground'] . '|' . THEME_DARK['panel'] . '|' . THEME_DARK['raised'] . '|' . THEME_DARK['line'] . '|' . THEME_DARK['ink'] . '|' . THEME_DARK['soft'], '10 18 38|17 29 54|27 44 80|35 53 89|238 242 250|165 178 207', 'the dark palette is exactly the colours the dashboard always had (#0A1226, #111D36, #1B2C50, #233559, #EEF2FA, #A5B2CF)');
    same(array_keys(THEME_DARK), array_keys(THEME_LIGHT), 'the light palette defines every colour the dark one does');
    foreach (['dark' => THEME_DARK, 'light' => THEME_LIGHT] as $mode => $pal) {
        $bad = [];
        foreach (THEME_PAIRS as [$fg, $bg, $min, $what]) {
            $r = theme_contrast($pal[$fg], $pal[$bg]);
            if ($r < $min) { $bad[] = sprintf('%s (%s on %s) %.2f < %.1f', $what, $fg, $bg, $r, $min); }
        }
        check(!$bad, "$mode mode: all " . count(THEME_PAIRS) . ' text, accent and focus colour pairs meet WCAG AA', implode('; ', $bad));
    }
    check(theme_contrast('255 255 255', '192 38 27') >= 4.5, 'the unread-count badge (white on #C0261B) is readable');
    check(theme_contrast('17 29 54', '199 243 107') >= 4.5 && theme_contrast('17 29 54', '255 241 168') >= 4.5, 'navy text on the lime and butter backgrounds (buttons, badges, summary box) is readable in both themes');
    check(theme_contrast('126 22 10', '255 211 204') >= 4.5, 'the red error chips (#7E160A on #FFD3CC) are readable in both themes');
    $css = theme_style();
    check(str_contains($css, 'html[data-theme=light]{') && str_contains($css, ':root,html[data-theme=dark]{') && str_contains($css, 'color-scheme:light') && str_contains($css, 'color-scheme:dark'), 'the CSS defines both palettes and tells the browser which colour scheme applies (native controls follow)');
    check(str_contains($css, '.on-navy{--c-ground:10 18 38'), 'dark islands (sidebar, service health, login brand panel) redeclare the dark palette');
    check(str_contains($css, 'html .text-lime') && str_contains($css, 'html .text-butter') && str_contains($css, 'html .chart .bg-lime') && str_contains($css, 'focus\\:ring-lime'), 'accent colours used as text, chart bars and focus rings follow the theme');
    $cfg = theme_tailwind_script();
    check(str_contains($cfg, "ground:'rgb(var(--c-ground) / <alpha-value>)'") && str_contains($cfg, "boxShadow:{panel:'var(--shadow-panel)'}") && !str_contains($cfg, '#0A1226'), 'the Tailwind colours point at the variables (so opacity modifiers such as bg-ground/95 keep working) and no dark colour is hard-coded');

    section('34. Theme: every page carries it');
    $boot = theme_boot_script();
    check(str_contains($boot, 'localStorage.getItem("theme")') && str_contains($boot, 'prefers-color-scheme: light') && str_contains($boot, '"light":"dark"'), 'the boot script uses the saved choice, else the system setting, else dark, before the page paints');
    $btn = theme_toggle_button();
    check(str_contains($btn, 'data-theme-toggle') && str_contains($btn, 'aria-label="Switch to light mode"') && str_contains($btn, 'theme-sun') && str_contains($btn, 'theme-moon') && str_contains($btn, 'type="button"'), 'the toggle is a real button with a text label and a sun and a moon icon');
    $scr = theme_toggle_script();
    check(str_contains($scr, 'localStorage.setItem("theme"') && str_contains($scr, 'Switch to dark mode') && str_contains($scr, 'addEventListener("change"'), 'clicking saves the choice per browser and updates the label; the system setting is followed while nothing is saved');
    $anon = $newJar();
    $pages = ['auth/login.php' => null, 'auth/forgot.php' => null, 'auth/reset.php?token=nope' => null, 'auth/invite.php?token=nope' => null, 'privacy/privacybeleid.php' => null];
    foreach (array_keys($pages) as $pg) {
        $b = http('GET', "$base/$pg", ['jar' => $anon])['body'];
        check(str_contains($b, 'setAttribute("data-theme"') && str_contains($b, 'data-theme-toggle') && str_contains($b, '--c-ground:') && !str_contains($b, 'ground:\'#0A1226\''), "$pg has the theme (boot script, toggle button, palette)");
    }
    check(str_contains(http('GET', "$base/auth/login.php", ['jar' => $anon])['body'], 'logo-on-light') && str_contains(http('GET', "$base/auth/forgot.php", ['jar' => $anon])['body'], 'logo-on-light'), 'pages on a page background carry the coloured logo for light mode next to the white one');
    foreach (['index.php', 'users/users.php', 'companies/companies.php', 'tickets/tickets.php', 'invoices/invoices.php', 'monitoring/uptime.php', 'invoices/recurring.php', 'notifications/notifications.php', 'search/search.php?q=a', 'settings/settings.php?tab=account', 'settings/settings.php?tab=email', 'privacy/privacy.php'] as $pg) {
        $b = http('GET', "$base/$pg", ['jar' => $admin])['body'];
        check(str_contains($b, 'data-theme-toggle') && str_contains($b, 'setAttribute("data-theme"') && str_contains($b, 'class="on-navy') , "$pg (signed in) has the theme and keeps the sidebar dark");
    }
    $ov = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    check(substr_count($ov, 'on-navy') >= 2 && str_contains($ov, 'Service health'), 'the sidebar and the service-health card are both dark islands');
    check(str_contains($ov, 'chart-key'), 'the activity chart legend follows the chart colour');
    $login = http('GET', "$base/auth/login.php", ['jar' => $anon])['body'];
    check(str_contains($login, 'on-navy relative hidden flex-col'), 'the login brand panel is a dark island');

    // regressions: nothing may bring back hard-coded theme colours or text that is dimmed with opacity
    $srcFiles = app_source_files();
    $hard = []; $dim = []; $cfgs = [];
    foreach ($srcFiles as $f) {
        $t = (string) file_get_contents($f);
        if (!in_array(basename($f), ['theme.php', 'security.php'], true) && preg_match('/color-scheme:\s*dark|background:\s*#0A1226|ground:\s*\'#0A1226\'/', $t)) { $hard[] = basename($f); }   // security.php: the bare 403 and 429 error pages stay dark on purpose
        if (preg_match('/opacity-[4-7]0/', $t) && basename($f) !== 'bootstrap.php') { $dim[] = basename($f); }
        if (basename($f) !== 'theme.php' && str_contains($t, 'tailwind.config')) { $cfgs[] = basename($f); }
    }
    check(!$hard, 'no page hard-codes the dark page colour or colour scheme any more', implode(', ', $hard));
    check(!$cfgs, 'only includes/theme.php configures Tailwind (one palette for every page)', implode(', ', $cfgs));
    check(!$dim, 'no row or text is dimmed with opacity (it silently lowers contrast); disabled and read rows use a tint', implode(', ', $dim));
    $bs = (string) file_get_contents(TESTS_DIR . '/../includes/core/bootstrap.php');
    check(str_contains($bs, "'info' => 'bg-ink/10 text-ink'") && !str_contains($bs, "bg-white/10 text-ink"), 'the neutral badge uses a tint of the text colour, so it shows in both themes');
