<?php
declare(strict_types=1);

/*
 * A very small PDF writer (stage 10), no library: A4 pages, the built-in Helvetica fonts, text, lines and filled rectangles.
 * The content streams are left uncompressed on purpose, so a PDF can be read by a person with a text editor and checked by a test.
 * Coordinates are in points from the TOP-LEFT corner of the page (1 pt = 1/72 inch); A4 is 595 x 842.
 */
final class Pdf
{
    public const W = 595.0;
    public const H = 842.0;

    /** Character widths (1/1000 em) of Helvetica and Helvetica-Bold for ASCII 32 to 126. */
    private const REG = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const BOLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    /** @var list<string> one content stream per page */
    private array $pages = [];
    private int $cur = -1;
    private string $title;

    public function __construct(string $title = 'Document') { $this->title = $title; }

    public function addPage(): void { $this->pages[] = ''; $this->cur = count($this->pages) - 1; }
    /** Goes back to an earlier page (0 is the first), for things like page numbers that need the final count. */
    public function setPage(int $i): void { $this->cur = $i; }
    public function pageCount(): int { return count($this->pages); }

    /** Converts UTF-8 to the Windows-1252 bytes the PDF font uses. Characters it can not show become "?". */
    public static function encode(string $s): string
    {
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    /** Width of a text in points. Letters outside ASCII are counted as an average character. */
    public static function width(string $s, float $size, bool $bold = false): float
    {
        $w = 0;
        $table = $bold ? self::BOLD : self::REG;
        foreach (str_split(self::encode($s)) as $ch) {
            $o = ord($ch);
            $w += ($o >= 32 && $o <= 126) ? $table[$o - 32] : 556;
        }
        return $w * $size / 1000;
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::encode($s));
    }

    private static function n(float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0'; }

    /** Draws a line of text. $align is left, right (x is the right edge) or center. */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'left', array $rgb = [0, 0, 0]): void
    {
        if ($s === '') { return; }
        $w = self::width($s, $size, $bold);
        if ($align === 'right') { $x -= $w; } elseif ($align === 'center') { $x -= $w / 2; }
        $this->pages[$this->cur] .= sprintf("BT /%s %s Tf %s %s %s rg %s %s Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', self::n($size), self::n($rgb[0]), self::n($rgb[1]), self::n($rgb[2]), self::n($x), self::n(self::H - $y), self::esc($s));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0.6, 0.6, 0.6]): void
    {
        $this->pages[$this->cur] .= sprintf("%s w %s %s %s RG %s %s m %s %s l S\n", self::n($width), self::n($rgb[0]), self::n($rgb[1]), self::n($rgb[2]), self::n($x1), self::n(self::H - $y1), self::n($x2), self::n(self::H - $y2));
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->pages[$this->cur] .= sprintf("%s %s %s rg %s %s %s %s re f\n", self::n($rgb[0]), self::n($rgb[1]), self::n($rgb[2]), self::n($x), self::n(self::H - $y - $h), self::n($w), self::n($h));
    }

    /** The finished file as bytes. */
    public function output(): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $n = count($this->pages);
        for ($i = 0; $i < $n; $i++) { $kids[] = (5 + $i * 2) . ' 0 R'; }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $content) {
            $pageNo = 5 + $i * 2;
            $objs[$pageNo] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ($pageNo + 1) . ' 0 R >>';
            $objs[$pageNo + 1] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
        }
        $infoNo = 5 + $n * 2;
        $objs[$infoNo] = '<< /Title (' . self::esc($this->title) . ') /Producer (Telecomlijn Admin) /CreationDate (D:' . date('YmdHis') . ') >>';
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $no => $body) {
            $offsets[$no] = strlen($pdf);
            $pdf .= $no . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($infoNo + 1) . "\n0000000000 65535 f \n";
        for ($no = 1; $no <= $infoNo; $no++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$no]); }
        return $pdf . "trailer\n<< /Size " . ($infoNo + 1) . ' /Root 1 0 R /Info ' . $infoNo . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}

/**
 * The PDF of one invoice. $inv is a row of invoice_base_sql(), $items its lines, $co the supplier details (includes/company.php).
 * With $quote = true the same layout is a quote: $inv is then a row of quote_base_sql() (valid_until instead of a due date, nothing paid).
 */
function invoice_pdf(array $inv, array $items, array $co, bool $quote = false): string
{
    $word = $quote ? 'Quote' : 'Invoice';
    if ($quote) { $inv['due_date'] = $inv['valid_until']; $inv['paid_cents'] = 0; $inv['balance_cents'] = 0; }   // a quote has nothing to pay yet
    $money = fn(int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
    $navy = [0.067, 0.114, 0.212]; $grey = [0.4, 0.4, 0.45]; $line = [0.8, 0.8, 0.82];
    $pdf = new Pdf($word . ' ' . $inv['number']);
    $L = 50.0; $R = 545.0;

    $header = function (bool $first) use ($pdf, $inv, $co, $L, $R, $navy, $grey, $line, $word): float {
        $pdf->addPage();
        $pdf->rect(0, 0, Pdf::W, 8, $navy);
        $pdf->text($L, 52, $co['name'], 17, true, 'left', $navy);
        if ($first) {
            $y = 68;
            foreach ($co['address_lines'] as $a) { $pdf->text($L, $y, $a, 9, false, 'left', $grey); $y += 12; }
            if ($co['email'] !== '' || $co['phone'] !== '') { $pdf->text($L, $y, trim($co['email'] . ($co['email'] !== '' && $co['phone'] !== '' ? '  |  ' : '') . $co['phone']), 9, false, 'left', $grey); }
            $pdf->text($R, 52, strtoupper($word), 20, true, 'right', $navy);
            $pdf->text($R, 68, $inv['number'], 11, true, 'right');
            return 130;
        }
        $pdf->text($R, 52, $word . ' ' . $inv['number'] . ' (continued)', 10, false, 'right', $grey);
        $pdf->line($L, 64, $R, 64, 0.5, $line);
        return 90;
    };

    $y = $header(true);

    // billed to, and the dates
    $pdf->text($L, $y, $quote ? 'QUOTE FOR' : 'BILLED TO', 8, true, 'left', $grey);
    $pdf->text($L, $y + 15, $inv['customer'], 11, true);
    $by = $y + 29;
    foreach (array_filter([$inv['customer_address'] !== '' ? $inv['customer_address'] : null, $inv['email'] !== '' ? $inv['email'] : null]) as $t) { $pdf->text($L, $by, (string) $t, 9.5, false, 'left', $grey); $by += 12.5; }
    $meta = [['Invoice date', date('j F Y', strtotime($inv['issue_date']))], ['Supply date', date('j F Y', strtotime($inv['supply_date'] ?? $inv['issue_date']))], ['Due date', date('j F Y', strtotime($inv['due_date']))], ['BTW rate', (int) $inv['vat_rate'] . '%']];
    if ($quote) { $meta = [['Quote date', date('j F Y', strtotime($inv['issue_date']))], ['Valid until', date('j F Y', strtotime($inv['valid_until']))], ['BTW rate', (int) $inv['vat_rate'] . '%']]; }
    $my = $y;
    foreach ($meta as [$k, $v]) { $pdf->text(340, $my, $k, 9, false, 'left', $grey); $pdf->text($R, $my, $v, 9.5, true, 'right'); $my += 14; }
    $y = max($by, $my) + 18;

    // supplier identifiers: a line is only printed when there is a real value, never a made-up one
    $ids = [];
    if ($co['kvk'] !== '') { $ids[] = 'KvK ' . $co['kvk']; }
    if ($co['btw'] !== '') { $ids[] = 'BTW-id ' . $co['btw']; }
    if ($co['iban'] !== '') { $ids[] = 'IBAN ' . $co['iban']; }
    if ($ids) { $pdf->text($L, $y, implode('    ', $ids), 9, false, 'left', $grey); $y += 22; }

    // the table
    $cols = ['desc' => $L + 6, 'qty' => 360, 'unit' => 450, 'tot' => $R - 6];
    $tableHead = function (float $y) use ($pdf, $L, $R, $cols, $navy): float {
        $pdf->rect($L, $y - 12, $R - $L, 20, $navy);
        $pdf->text($cols['desc'], $y + 1, 'Description', 9, true, 'left', [1, 1, 1]);
        $pdf->text($cols['qty'], $y + 1, 'Qty', 9, true, 'right', [1, 1, 1]);
        $pdf->text($cols['unit'], $y + 1, 'Unit price', 9, true, 'right', [1, 1, 1]);
        $pdf->text($cols['tot'], $y + 1, 'Total', 9, true, 'right', [1, 1, 1]);
        return $y + 22;
    };
    $y = $tableHead($y);
    foreach ($items as $it) {
        $descLines = [];
        $words = preg_split('/\s+/', (string) $it['description']) ?: [];
        $cur = '';
        foreach ($words as $w) {
            if ($cur !== '' && Pdf::width($cur . ' ' . $w, 9.5) > 290) { $descLines[] = $cur; $cur = $w; } else { $cur = $cur === '' ? $w : $cur . ' ' . $w; }
        }
        $descLines[] = $cur;
        $need = 14 * count($descLines) + 6;
        if ($y + $need > 700) { $y = $header(false); $y = $tableHead($y); }
        $qty = rtrim(rtrim(number_format((float) $it['qty'], 2, ',', '.'), '0'), ',');
        $lineTotal = (int) round((float) $it['qty'] * (int) $it['unit_cents']);
        foreach ($descLines as $k => $dl) { $pdf->text($cols['desc'], $y + 14 * $k, $dl, 9.5); }
        $pdf->text($cols['qty'], $y, $qty, 9.5, false, 'right');
        $pdf->text($cols['unit'], $y, $money((int) $it['unit_cents']), 9.5, false, 'right');
        $pdf->text($cols['tot'], $y, $money($lineTotal), 9.5, false, 'right');
        $y += $need - 2;
        $pdf->line($L, $y - 9, $R, $y - 9, 0.4, $line);
    }

    // totals
    if ($y + 120 > 760) { $y = $header(false); }
    $y += 8;
    $row = function (string $k, string $v, bool $bold = false) use ($pdf, &$y, $R): void {
        $pdf->text(430, $y, $k, $bold ? 10.5 : 9.5, $bold, 'right');
        $pdf->text($R, $y, $v, $bold ? 10.5 : 9.5, $bold, 'right');
        $y += $bold ? 18 : 15;
    };
    $row('Subtotal', $money((int) $inv['subtotal_cents']));
    $row('BTW ' . (int) $inv['vat_rate'] . '%', $money((int) $inv['vat_cents']));
    $pdf->line(380, $y - 10, $R, $y - 10, 0.8, $navy);
    $row('Total', $money((int) $inv['total_cents']), true);
    if (!$quote) {
        $row('Paid', $money((int) $inv['paid_cents']));
        $row('Balance due', $money(max(0, (int) $inv['balance_cents'])), true);
    }

    $y += 14;
    if ($inv['notes'] !== '') { $pdf->text($L, $y, 'Note: ' . $inv['notes'], 9, false, 'left', $grey); $y += 16; }
    if ($quote) {
        if ((string) ($inv['title'] ?? '') !== '') { $pdf->text($L, $y, 'Subject: ' . $inv['title'], 9, false, 'left', $grey); $y += 16; }
        $pdf->text($L, $y, 'This quote is valid until ' . date('j F Y', strtotime($inv['valid_until'])) . '. Prices exclude BTW unless stated otherwise.', 9, false, 'left');
    }
    $pay = 'Please pay ' . $money(max(0, (int) $inv['balance_cents']));
    $pay .= $co['iban'] !== '' ? ' to IBAN ' . $co['iban'] . ' in the name of ' . $co['name'] : ' to ' . $co['name'];
    $pay .= ', quoting ' . $inv['number'] . ', no later than ' . date('j F Y', strtotime($inv['due_date'])) . '.';
    if ((int) $inv['balance_cents'] > 0) { $pdf->text($L, $y, $pay, 9, false, 'left'); }
    elseif (!$quote) { $pdf->text($L, $y, 'This invoice has been paid in full. Thank you.', 9, true, 'left', $navy); }

    // footer on every page, now that the page count is known
    $n = $pdf->pageCount();
    for ($p = 0; $p < $n; $p++) {
        $pdf->setPage($p);
        $pdf->line($L, 800, $R, 800, 0.4, $line);
        $pdf->text($L, 814, $co['name'] . ($co['kvk'] !== '' ? '  |  KvK ' . $co['kvk'] : ''), 8, false, 'left', $grey);
        $pdf->text($R, 814, 'Page ' . ($p + 1) . ' of ' . $n, 8, false, 'right', $grey);
    }
    return $pdf->output();
}
