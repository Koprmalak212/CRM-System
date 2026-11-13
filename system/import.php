<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/csv.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();
if (!is_admin()) { deny_access('import'); }

$type = (string) ($_POST['type'] ?? $_GET['type'] ?? 'companies');
if (!isset(CSV_IMPORT_TYPES[$type])) { $type = 'companies'; }
$result = null;
$problem = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { $problem = 'Choose a CSV file first.'; }
    elseif (is_array($f['error']) || $f['error'] !== UPLOAD_ERR_OK) { $problem = $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'The file is too large. The limit is ' . (CSV_MAX_BYTES / 1024) . ' KB.' : 'The upload failed. Try again.'; }
    elseif (!is_uploaded_file($f['tmp_name']) || !preg_match('/\.csv$/i', (string) $f['name'])) { $problem = 'Only .csv files are accepted.'; }
    elseif ($f['size'] > CSV_MAX_BYTES) { $problem = 'The file is too large. The limit is ' . (CSV_MAX_BYTES / 1024) . ' KB.'; }
    else {
        // The file is only read, never moved, stored or executed: PHP deletes the temporary copy at the end of the request.
        [$rows, $problem] = csv_parse((string) file_get_contents($f['tmp_name']));
        if (!$problem) {
            $result = csv_import($pdo, $type, $rows);
            $problem = $result['error'];
            if (!$problem) {
                log_activity('info', 'Data imported', $type . ' · ' . $result['added'] . ' added, ' . count($result['skipped']) . ' skipped, ' . count($result['rejected']) . ' rejected');
            }
        }
    }
    if ($problem) { $result = null; }
}

page_start('Import', $type === 'users' ? 'users' : 'companies', 'Add people or companies from a spreadsheet. Existing records are never changed.');
$field = 'w-full rounded-lg border border-line bg-panel px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-lime file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-navy focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<a href="<?= $type === 'users' ? 'users/users.php' : 'companies/companies.php' ?>" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>Back to <?= $type === 'users' ? 'users' : 'companies' ?></a>

<?php if ($problem): ?><div role="alert" class="mb-6 rounded-lg bg-[#FFD3CC] px-4 py-3 text-sm font-semibold text-[#7E160A]"><?= e($problem) ?></div><?php endif; ?>

<?php if ($result): $nS = count($result['skipped']); $nR = count($result['rejected']); ?>
  <section class="rise mb-6 rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="res-h" role="status">
    <h2 id="res-h" class="text-base font-bold">Import finished</h2>
    <p class="mt-2 text-sm"><span class="font-semibold text-lime"><?= $result['added'] ?> added</span> · <?= $nS ?> skipped as duplicate · <span class="<?= $nR ? 'font-semibold text-[#FF9C8C]' : '' ?>"><?= $nR ?> rejected</span></p>
    <?php foreach (['rejected' => 'Rejected rows (fix these and import again)', 'skipped' => 'Skipped rows (already in the system)'] as $k => $title): if (!$result[$k]) continue; ?>
      <h3 class="mt-4 text-sm font-semibold"><?= e($title) ?></h3>
      <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-soft">
        <?php foreach (array_slice($result[$k], 0, 100) as [$line, $why]): ?><li>Line <?= (int) $line ?>: <?= e($why) ?></li><?php endforeach; ?>
        <?php if (count($result[$k]) > 100): ?><li>…and <?= count($result[$k]) - 100 ?> more</li><?php endif; ?>
      </ul>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="rise max-w-2xl rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" novalidate><?= csrf_field() ?>
  <div><label for="type" class="text-sm font-semibold">What are you importing?</label>
    <select id="type" name="type" class="mt-1.5 h-11 w-full rounded-lg border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"><?php foreach (CSV_IMPORT_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="mt-4"><label for="file" class="text-sm font-semibold">CSV file</label>
    <span class="mt-1.5 flex min-w-0 items-center gap-3">
      <input id="file" name="file" type="file" accept=".csv,text/csv" required class="peer sr-only" data-file-name="file-name">
      <label for="file" class="inline-flex h-11 shrink-0 cursor-pointer items-center rounded-lg border border-line bg-panel px-4 text-sm font-semibold hover:bg-butter-50 peer-focus-visible:ring-2 peer-focus-visible:ring-lime">Choose a file</label>
      <span id="file-name" class="min-w-0 truncate text-sm text-soft notranslate" aria-live="polite">No file chosen</span>
    </span></div>
  <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-soft">
    <li>Maximum <?= CSV_MAX_BYTES / 1024 ?> KB and <?= CSV_MAX_ROWS ?> rows. The server refuses larger uploads.</li>
    <li>UTF-8, comma or semicolon separated, first line = column names. Export a file first to see the exact columns.</li>
    <li><strong class="text-ink">Users:</strong> name and email are required; role, status, country, company (by name), job_title and phone are optional. Import companies first.</li>
    <li><strong class="text-ink">Companies:</strong> name is required; address, postcode, city, country, kvk, vat_number, email, phone, website and notes are optional.</li>
    <li>A row whose email (or KvK number) already exists is skipped, never overwritten. Rows with a problem are listed with the reason; good rows are still added.</li>
    <li>The file is read and discarded. It is not stored.</li>
  </ul>
  <button class="pressable mt-6 h-12 rounded-lg bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Import</button>
</form>
<?php page_end(); ?>
