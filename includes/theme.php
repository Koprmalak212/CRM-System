<?php
declare(strict_types=1);

/*
 * Light and dark theme (stage 11).
 *
 * Colours are CSS variables (space-separated RGB triplets, so Tailwind's opacity modifiers like bg-ground/95 keep working).
 * The dark values are exactly the colours the dashboard always had. The light values are chosen so that text meets WCAG AA contrast;
 * tests/run.php computes the contrast of every pair listed in THEME_PAIRS from these same tables, so the palette and the check can not drift apart.
 *
 * "Islands": the sidebar and the service-health card keep the dark palette in both themes. Anything with the class on-navy
 * redeclares the dark variables for itself and its children.
 * The page decides the theme before it paints (boot script): the person's saved choice, else the system setting, else dark.
 */

const THEME_DARK = [
    'ground' => '10 18 38', 'panel' => '17 29 54', 'raised' => '27 44 80', 'deep' => '7 14 31', 'line' => '35 53 89',
    'ink' => '238 242 250', 'soft' => '165 178 207',
    'limetext' => '199 243 107', 'buttertext' => '255 241 168', 'warntext' => '255 156 140', 'focus' => '199 243 107', 'placeholder' => '132 147 180',
    'chartlime' => '199 243 107', 'scroll' => '44 66 114',
];
const THEME_LIGHT = [
    'ground' => '244 246 251', 'panel' => '255 255 255', 'raised' => '232 237 247', 'deep' => '226 232 244', 'line' => '214 222 236',
    'ink' => '17 29 54', 'soft' => '77 91 122',
    'limetext' => '63 98 18', 'buttertext' => '122 79 0', 'warntext' => '180 35 24', 'focus' => '63 98 18', 'placeholder' => '95 107 136',
    'chartlime' => '77 124 15', 'scroll' => '184 195 217',
];
const THEME_OTHER = [
    'dark' => ['butter50' => 'rgba(255,241,168,.06)', 'butter100' => 'rgba(255,241,168,.12)', 'shadow' => '0 1px 2px rgba(0,0,0,.35), 0 10px 24px -12px rgba(0,0,0,.55)'],
    'light' => ['butter50' => 'rgba(17,29,54,.045)', 'butter100' => 'rgba(245,176,0,.16)', 'shadow' => '0 1px 2px rgba(17,29,54,.07), 0 10px 24px -14px rgba(17,29,54,.24)'],
];

/**
 * The text/background combinations that must be readable, for each theme: [text token, background token, minimum ratio, what it is].
 * 4.5 is WCAG AA for normal text, 3 for large text and user-interface parts.
 */
const THEME_PAIRS = [
    ['ink', 'ground', 4.5, 'body text on the page'], ['ink', 'panel', 4.5, 'text on a card'], ['ink', 'raised', 4.5, 'text on a raised surface'], ['ink', 'deep', 4.5, 'text in a code box'],
    ['soft', 'ground', 4.5, 'secondary text on the page'], ['soft', 'panel', 4.5, 'secondary text on a card'], ['soft', 'raised', 4.5, 'secondary text on a raised surface'], ['soft', 'deep', 4.5, 'secondary text in a code box'],
    ['limetext', 'panel', 4.5, 'accent text on a card'], ['limetext', 'raised', 4.5, 'accent text on an avatar'], ['limetext', 'ground', 4.5, 'accent text on the page'],
    ['buttertext', 'panel', 4.5, 'warning title on a card'], ['buttertext', 'ground', 4.5, 'warning text on the page'],
    ['warntext', 'panel', 4.5, 'problem text on a card'], ['warntext', 'ground', 4.5, 'problem text on the page'],
    ['placeholder', 'panel', 4.5, 'placeholder in an input'], ['placeholder', 'ground', 4.5, 'placeholder on the page'],
    ['focus', 'panel', 3.0, 'focus ring on a card'], ['focus', 'ground', 3.0, 'focus ring on the page'],
    ['chartlime', 'panel', 3.0, 'chart bars on a card'],
    ['line', 'panel', 1.0, 'card border (decorative)'],
];

/** WCAG relative luminance of an "r g b" triplet. */
function theme_luminance(string $rgb): float
{
    [$r, $g, $b] = array_map(fn($v) => (int) $v / 255, preg_split('/\s+/', trim($rgb)));
    $f = fn($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
}

/** WCAG contrast ratio between two "r g b" triplets (1 to 21). */
function theme_contrast(string $a, string $b): float
{
    $la = theme_luminance($a); $lb = theme_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

function theme_vars(string $which): string
{
    $t = $which === 'light' ? THEME_LIGHT : THEME_DARK;
    $o = THEME_OTHER[$which === 'light' ? 'light' : 'dark'];
    $css = '';
    foreach ($t as $k => $v) { $css .= '--c-' . $k . ':' . $v . ';'; }
    return $css . '--c-butter50:' . $o['butter50'] . ';--c-butter100:' . $o['butter100'] . ';--shadow-panel:' . $o['shadow'] . ';';
}

/** The CSS for the theme: variables, island palette, and the few rules that map hard-coded colours onto the variables. */
function theme_style(): string
{
    return '<style nonce="' . csp_nonce() . '">'
        . ':root,html[data-theme=dark]{' . theme_vars('dark') . 'color-scheme:dark}'
        . 'html[data-theme=light]{' . theme_vars('light') . 'color-scheme:light}'
        . '.on-navy{' . theme_vars('dark') . 'color-scheme:dark}'   // sidebar and service card keep the dark palette in both themes
        . 'html{background:rgb(var(--c-ground))}'
        . '::selection{background:#C7F36B;color:#111D36}'
        . ':focus-visible{outline:2px solid rgb(var(--c-focus));outline-offset:2px;border-radius:6px}'
        . '*{scrollbar-width:thin;scrollbar-color:rgb(var(--c-scroll)) transparent}'
        . 'input,select,textarea{caret-color:rgb(var(--c-focus));color:rgb(var(--c-ink))}'
        . 'input::placeholder,textarea::placeholder{color:rgb(var(--c-placeholder))}'
        . 'select option{background:rgb(var(--c-panel));color:rgb(var(--c-ink))}'
        // accent colours that are used as TEXT follow the theme; as a background (buttons, badges) lime and butter stay bright
        . 'html .text-lime,html .hover\\:text-lime:hover{color:rgb(var(--c-limetext))}'
        . 'html .text-butter{color:rgb(var(--c-buttertext))}'
        . 'html .text-\\[\\#FF9C8C\\]{color:rgb(var(--c-warntext))}'
        . 'html .focus\\:ring-lime:focus{--tw-ring-color:rgb(var(--c-focus))}'
        . 'html .focus\\:border-lime:focus{border-color:rgb(var(--c-focus))}'
        . 'html .chart .bg-lime,html .chart-key.bg-lime{background-color:rgb(var(--c-chartlime))}'
        . 'html[data-theme=light] body::before{background:radial-gradient(closest-side,rgba(140,170,230,.30),transparent)}html[data-theme=light] body::after{background:radial-gradient(closest-side,rgba(170,195,240,.32),transparent)}'   // the slow background lights turn pale blue
        . 'html[data-theme=light] .logo-on-dark{display:none}html:not([data-theme=light]) .logo-on-light{display:none}'
        . 'html[data-theme=light] .theme-sun{display:none}html:not([data-theme=light]) .theme-moon{display:none}'
        . '</style>';
}

/** Tailwind (CDN) configuration shared by every page, with the themed colours pointing at the variables. */
function theme_tailwind_script(): string
{
    $v = fn(string $n) => "'rgb(var(--c-$n) / <alpha-value>)'";
    return '<script nonce="' . csp_nonce() . '">tailwind.config={theme:{extend:{'
        . "fontFamily:{sans:['\"Hanken Grotesk\"','ui-sans-serif','system-ui','sans-serif']},"
        . "colors:{navy:{DEFAULT:'#111D36',800:'#18284A',700:'#22355E',600:'#334A7A'},lime:{DEFAULT:'#C7F36B',600:'#A8D84A'},butter:{DEFAULT:'#FFF1A8',50:'var(--c-butter50)',100:'var(--c-butter100)'},"
        . 'ground:' . $v('ground') . ',panel:' . $v('panel') . ',raised:' . $v('raised') . ',deep:' . $v('deep') . ',line:' . $v('line') . ',ink:' . $v('ink') . ',soft:' . $v('soft') . ",coral:'#F0654F'},"
        . "boxShadow:{panel:'var(--shadow-panel)'}"
        . '}}};</script>';
}

/** Runs before the page paints, so there is no flash of the wrong theme. Saved choice, else the system setting, else dark. */
function theme_boot_script(): string
{
    return '<script nonce="' . csp_nonce() . '">(function(){var t;try{t=localStorage.getItem("theme")}catch(e){}if(t!=="light"&&t!=="dark"){t=window.matchMedia&&matchMedia("(prefers-color-scheme: light)").matches?"light":"dark"}document.documentElement.setAttribute("data-theme",t)})();</script>';
}

/** The sun / moon button. It shows the sun in dark mode (click for light) and the moon in light mode. */
function theme_toggle_button(string $cls = 'grid h-10 w-10 place-items-center rounded-xl hover:bg-ink/10'): string
{
    $svg = fn(string $c, string $p) => '<svg class="' . $c . ' h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    return '<button type="button" data-theme-toggle class="' . e($cls) . '" aria-label="Switch to light mode" title="Switch to light mode">'
        . $svg('theme-sun', '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>')
        . $svg('theme-moon', '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>') . '</button>';
}

/** Click handling for every toggle button on the page, and following the system setting while no choice has been saved. */
function theme_toggle_script(): string
{
    return '<script nonce="' . csp_nonce() . '">(function(){var H=document.documentElement,B=document.querySelectorAll("[data-theme-toggle]");'
        . 'function cur(){return H.getAttribute("data-theme")==="light"?"light":"dark"}'
        . 'function label(){var l=cur()==="light",t=l?"Switch to dark mode":"Switch to light mode";B.forEach(function(b){b.setAttribute("aria-label",t);b.setAttribute("title",t)})}'
        . 'function saved(){try{var t=localStorage.getItem("theme");return t==="light"||t==="dark"?t:null}catch(e){return null}}'
        . 'B.forEach(function(b){b.addEventListener("click",function(){var n=cur()==="light"?"dark":"light";H.setAttribute("data-theme",n);try{localStorage.setItem("theme",n)}catch(e){}label()})});'
        . 'if(window.matchMedia){var m=matchMedia("(prefers-color-scheme: light)"),f=function(){if(!saved()){H.setAttribute("data-theme",m.matches?"light":"dark");label()}};if(m.addEventListener)m.addEventListener("change",f)}'
        . 'label()})();</script>';
}

/** Everything a page head needs, in order: boot script, Tailwind config, theme CSS. */
function theme_head(): string
{
    return theme_boot_script() . theme_tailwind_script() . theme_style();
}
