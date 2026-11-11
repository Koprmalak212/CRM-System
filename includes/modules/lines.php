<?php
declare(strict_types=1);

/*
 * The editable list of lines (description, quantity, unit price) that quotes and contracts share: reading it from a form, drawing it,
 * and the small script that adds and removes lines and keeps the totals up to date. Contracts add a "service" choice per line.
 */

if (!function_exists('parse_money')) { require __DIR__ . '/invoices.php'; }

const LINES_MAX = 50;

/** The next number in a yearly series such as OFF-2026-1001. $table and $prefix are constants from the code, never user input. */
function next_doc_number(PDO $pdo, string $table, string $prefix): string
{
    $prefix .= '-' . date('Y') . '-';
    $s = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) FROM `$table` WHERE number LIKE ?");
    $s->execute([strlen($prefix) + 1, $prefix . '%']);
    return $prefix . str_pad((string) max((int) $s->fetchColumn() + 1, 1001), 4, '0', STR_PAD_LEFT);
}

/**
 * Reads the lines of a posted form. Returns [items, rows to show again, errors]; an item is
 * ['description' => string, 'qty' => float, 'unit_cents' => int, 'service' => string]. $services is the list of allowed service keys
 * (empty when the form has no service column).
 */
function lines_read(array $post, array $services = []): array
{
    $items = []; $old = []; $err = [];
    $desc = array_values((array) ($post['desc'] ?? []));
    $qty = array_values((array) ($post['qty'] ?? []));
    $price = array_values((array) ($post['price'] ?? []));
    $svc = array_values((array) ($post['service'] ?? []));
    if (count($desc) > LINES_MAX) { $err[] = 'There can be at most ' . LINES_MAX . ' lines.'; $desc = array_slice($desc, 0, LINES_MAX); }
    foreach ($desc as $k => $d) {
        $d = trim((string) $d);
        $qRaw = (string) ($qty[$k] ?? '1');
        $pRaw = (string) ($price[$k] ?? '');
        $sv = (string) ($svc[$k] ?? 'other');
        if (!in_array($sv, $services, true)) { $sv = 'other'; }
        $old[] = ['description' => $d, 'qty' => $qRaw, 'price' => $pRaw, 'service' => $sv];
        if ($d === '' && trim($pRaw) === '') { continue; }
        $qn = (float) str_replace(',', '.', $qRaw);
        $pc = parse_money($pRaw);
        if ($d === '' || $pc === null || $pc > 100000000 || $qn <= 0 || $qn > 10000) { $err[] = 'Line ' . ($k + 1) . ' needs a description, a quantity and a price above zero.'; continue; }
        $items[] = ['description' => mb_substr($d, 0, 200), 'qty' => $qn, 'unit_cents' => $pc, 'service' => $sv];
    }
    $sum = 0;
    foreach ($items as $it) { $sum += $it['qty'] * $it['unit_cents']; }
    if ($sum > 1000000000) { $err[] = 'The total is too large. The limit is € 10.000.000.'; }
    if (!$items && !$err) { $err[] = 'Add at least one line.'; }
    if (!$old) { $old = [lines_blank()]; }
    return [$items, $old, $err];
}

function lines_blank(): array { return ['description' => '', 'qty' => '1', 'price' => '', 'service' => 'other']; }

/** Stored lines (rows of quote_items or contract_items) as rows for the form. */
function lines_from_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['description' => (string) $r['description'], 'qty' => rtrim(rtrim(number_format((float) $r['qty'], 2, ',', ''), '0'), ','), 'price' => number_format((int) $r['unit_cents'] / 100, 2, ',', ''), 'service' => (string) ($r['service'] ?? 'other')];
    }
    return $out ?: [lines_blank()];
}

/** Sum of the lines in cents, each line rounded on its own (the same way an invoice does it). */
function lines_subtotal(array $items): int
{
    $sub = 0;
    foreach ($items as $it) { $sub += (int) round((float) $it['qty'] * (int) $it['unit_cents']); }
    return $sub;
}

/** A quantity as people write it: 2 or 2,5. */
function qty_text(float|string $q): string
{
    return rtrim(rtrim(number_format((float) $q, 2, ',', '.'), '0'), ',');
}

/** The lines section of a form. $services (key => label) adds a choice per line; $priceLabel is the heading of the price column. */
function lines_fields(array $old, string $priceLabel, string $example, array $services = []): string
{
    $field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $cols = $services ? 'sm:grid-cols-[150px_1fr_80px_120px_110px_36px]' : 'sm:grid-cols-[1fr_88px_120px_110px_36px]';
    $headCols = $services ? 'grid-cols-[150px_1fr_80px_120px_110px_36px]' : 'grid-cols-[1fr_88px_120px_110px_36px]';
    $line = function (array $it, bool $tpl) use ($field, $cols, $services, $example): string {
        $h = '<div class="line grid grid-cols-2 gap-3 rounded-xl ' . $cols . ' sm:items-center">';
        if ($services) {
            $h .= '<select name="service[]" aria-label="Service" class="' . $field . ' col-span-2 sm:col-span-1">';
            foreach ($services as $k => $l) { $h .= '<option value="' . e($k) . '"' . ($it['service'] === $k ? ' selected' : '') . '>' . e($l) . '</option>'; }
            $h .= '</select>';
        }
        return $h . '<input name="desc[]" value="' . e($it['description']) . '" maxlength="200" placeholder="' . e($example) . '" aria-label="Description" class="' . $field . ' col-span-2 sm:col-span-1">'
            . '<input name="qty[]" value="' . e($it['qty']) . '" inputmode="decimal" aria-label="Quantity" class="' . $field . ' tabular-nums">'
            . '<input name="price[]" value="' . e($it['price']) . '" inputmode="decimal" placeholder="0,00" aria-label="Unit price in euros" class="' . $field . ' tabular-nums">'
            . '<p class="lt text-right text-sm font-semibold tabular-nums notranslate">€ 0,00</p>'
            . '<button type="button" class="rm grid h-9 w-9 place-items-center justify-self-end rounded-lg text-soft hover:bg-[#FFD3CC] hover:text-[#7E160A]" aria-label="Remove line">' . icon('x', 'h-4 w-4') . '</button></div>';
    };
    $h = '<div class="flex items-center justify-between gap-3"><h2 id="lines-h" class="text-lg font-bold tracking-tight">Lines</h2>'
        . '<button type="button" id="addLine" class="inline-flex h-10 items-center gap-2 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy">' . icon('plus', 'h-4 w-4') . 'Add line</button></div>'
        . '<div class="mt-4 hidden ' . $headCols . ' gap-3 px-1 text-xs font-semibold text-soft sm:grid">' . ($services ? '<span>Service</span>' : '') . '<span>Description</span><span>Qty</span><span>' . e($priceLabel) . '</span><span class="text-right">Line total</span><span></span></div>'
        . '<div id="lines" class="mt-2 space-y-3">';
    foreach ($old as $it) { $h .= $line($it + lines_blank(), false); }
    return $h . '</div><template id="lineTpl">' . $line(lines_blank(), true) . '</template>';
}

/**
 * The script for lines_fields(). It fills #subOut, and #vatOut, #vatPct and #totOut when the form has a BTW choice with id "vat".
 * A select with id "company_id" whose options carry data-name, data-email and data-address fills the fields with those ids.
 */
function lines_script(string $formId): string
{
    return '<style nonce="' . csp_nonce() . '">
  @keyframes line-in { from { opacity: 0; transform: translateY(-8px) scale(.98); } 60% { opacity: 1; transform: translateY(2px); } to { transform: none; } }
  @keyframes line-out { 30% { transform: translateX(-8px); } to { opacity: 0; transform: translateX(40px); } }
  .line.new { animation: line-in .45s cubic-bezier(.34, 1.56, .64, 1) backwards; }
  .line.gone { animation: line-out .3s ease-in-out forwards; }
  @media (prefers-reduced-motion: reduce) { .line.new, .line.gone { animation-duration: .001ms; } }
</style>
<script nonce="' . csp_nonce() . '">
(function () {
  var lines = document.getElementById("lines"), tpl = document.getElementById("lineTpl"), $ = function (id) { return document.getElementById(id); };
  var fmt = function (c) { return "€ " + (c / 100).toLocaleString("nl-NL", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var num = function (s) { s = String(s).trim().replace(/[€\s]/g, ""); if (s.indexOf(",") > -1 && s.indexOf(".") > -1) s = s.replace(/\./g, ""); var n = parseFloat(s.replace(",", ".")); return isNaN(n) ? 0 : n; };
  function recalc() {
    var sub = 0;
    lines.querySelectorAll(".line").forEach(function (l) {
      var c = Math.round(num(l.querySelector("[name=\'qty[]\']").value) * num(l.querySelector("[name=\'price[]\']").value) * 100); sub += c; l.querySelector(".lt").textContent = fmt(c);
    });
    if ($("subOut")) $("subOut").textContent = fmt(sub);
    if ($("vat") && $("totOut")) {
      var rate = parseInt($("vat").value, 10), vat = Math.round(sub * rate / 100);
      if ($("vatPct")) $("vatPct").textContent = rate;
      if ($("vatOut")) $("vatOut").textContent = fmt(vat);
      $("totOut").textContent = fmt(sub + vat);
    }
  }
  $("addLine").addEventListener("click", function () {
    var n = tpl.content.firstElementChild.cloneNode(true); n.classList.add("new"); lines.appendChild(n); n.querySelector("input").focus(); recalc();
  });
  lines.addEventListener("click", function (e) {
    var b = e.target.closest(".rm"); if (!b) return;
    var l = b.closest(".line");
    if (lines.querySelectorAll(".line").length === 1) { l.querySelectorAll("input").forEach(function (i) { i.value = i.name === "qty[]" ? "1" : ""; }); recalc(); return; }
    l.classList.add("gone"); setTimeout(function () { l.remove(); recalc(); }, 280);
  });
  $(' . json_encode($formId) . ').addEventListener("input", recalc);
  var co = $("company_id");
  if (co && $("customer")) co.addEventListener("change", function (e) {
    var o = e.target.selectedOptions[0]; if (!o || !o.dataset.name) return;
    $("customer").value = o.dataset.name; if ($("email")) $("email").value = o.dataset.email; if ($("address")) $("address").value = o.dataset.address;
  });
  recalc();
})();
</script>';
}
