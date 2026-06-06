<?php
/**
 * Merge-only suburb backfill: reads scripts/data/import_main_life.csv and
 * scripts/data/import_associates.csv, matches each row to a live member by
 * member_number_base + member_number_suffix, and fills in members.suburb (and
 * members.city for legacy code paths) ONLY where both columns are currently
 * empty. Never overwrites existing data.
 *
 * Usage (browser):
 *   GET  /admin/members/merge_suburbs.php          → HTML page with dry-run + apply buttons
 *   POST /admin/members/merge_suburbs.php          → JSON result
 *     csrf_token=<token>
 *     mode=dry-run | apply
 *
 * Returns JSON: { matched, would_update | updated, skipped_has_data, not_found, samples[] }
 *
 * DELETE THIS FILE once the merge is complete.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\Database;

require_permission('admin.members.import_export');

$csvPaths = [
    'main_life'  => __DIR__ . '/../../../scripts/data/import_main_life.csv',
    'associates' => __DIR__ . '/../../../scripts/data/import_associates.csv',
];

function ms_parseMemberId(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (strpos($raw, '.') !== false) {
        [$baseStr, $suffixStr] = explode('.', $raw, 2);
        $base = (int) $baseStr;
        $suffix = (int) $suffixStr;
    } else {
        $base = (int) $raw;
        $suffix = 0;
    }
    if ($base <= 0) {
        return null;
    }
    return ['base' => $base, 'suffix' => $suffix];
}

function ms_readCsvRows(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) {
        return $rows;
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return $rows;
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    $idxMemberId = array_search('member_id', $header, true);
    $idxSuburb   = array_search('suburb', $header, true);
    if ($idxMemberId === false || $idxSuburb === false) {
        fclose($fh);
        return $rows;
    }
    while (($row = fgetcsv($fh)) !== false) {
        if (!$row) {
            continue;
        }
        $memberIdRaw = (string) ($row[$idxMemberId] ?? '');
        $suburb      = trim((string) ($row[$idxSuburb] ?? ''));
        if ($suburb === '') {
            continue;
        }
        $parsed = ms_parseMemberId($memberIdRaw);
        if ($parsed === null) {
            continue;
        }
        $rows[] = [
            'member_id_raw' => $memberIdRaw,
            'base'          => $parsed['base'],
            'suffix'        => $parsed['suffix'],
            'suburb'        => $suburb,
        ];
    }
    fclose($fh);
    return $rows;
}

function &ms_columnCache(): array
{
    static $cache = [];
    return $cache;
}

function ms_hasColumn(PDO $pdo, string $column): bool
{
    $cache = &ms_columnCache();
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }
    $stmt = $pdo->query('SHOW COLUMNS FROM members LIKE ' . $pdo->quote($column));
    return $cache[$column] = (bool) $stmt->fetchColumn();
}

function ms_resetColumnCache(): void
{
    $cache = &ms_columnCache();
    $cache = [];
}

function ms_runMerge(array $csvPaths, bool $apply): array
{
    $pdo = Database::connection();

    // Self-heal: if the `suburb` column is missing (migration never ran on this
    // env), add it now. Mirrors database/members_module.sql:120.
    $columnAdded = false;
    if (!ms_hasColumn($pdo, 'suburb')) {
        $pdo->exec('ALTER TABLE members ADD COLUMN suburb VARCHAR(120) NULL AFTER address_line2');
        ms_resetColumnCache();
        $columnAdded = true;
    }

    $hasSuburb = ms_hasColumn($pdo, 'suburb');
    $hasCity   = ms_hasColumn($pdo, 'city');

    $allRows = [];
    foreach ($csvPaths as $key => $path) {
        $resolved = realpath($path);
        if (!$resolved || !is_readable($resolved)) {
            continue;
        }
        foreach (ms_readCsvRows($resolved) as $row) {
            $row['source'] = $key;
            $allRows[] = $row;
        }
    }

    $selectCols = ['id', 'first_name', 'last_name'];
    if ($hasSuburb) { $selectCols[] = 'suburb'; }
    if ($hasCity)   { $selectCols[] = 'city'; }
    $lookupStmt = $pdo->prepare(
        'SELECT ' . implode(', ', $selectCols) . '
           FROM members
          WHERE member_number_base = :base
            AND member_number_suffix = :suffix
          LIMIT 1'
    );

    $updateParts = [];
    if ($hasSuburb) { $updateParts[] = 'suburb = :suburb'; }
    if ($hasCity)   { $updateParts[] = 'city = :city'; }
    $updateStmt = null;
    if ($updateParts) {
        $updateStmt = $pdo->prepare(
            'UPDATE members SET ' . implode(', ', $updateParts) . ', updated_at = NOW() WHERE id = :id'
        );
    }

    $result = [
        'apply'              => $apply,
        'suburb_column_added' => $columnAdded,
        'total_csv_rows'     => count($allRows),
        'matched'           => 0,
        'updated'           => 0,
        'would_update'      => 0,
        'skipped_has_data'  => 0,
        'not_found'         => 0,
        'samples_updated'   => [],
        'samples_skipped'   => [],
        'samples_missing'   => [],
    ];

    $pdo->beginTransaction();
    try {
        foreach ($allRows as $row) {
            $lookupStmt->execute(['base' => $row['base'], 'suffix' => $row['suffix']]);
            $member = $lookupStmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                $result['not_found']++;
                if (count($result['samples_missing']) < 10) {
                    $result['samples_missing'][] = $row['member_id_raw'];
                }
                continue;
            }

            $result['matched']++;
            $existingSuburb = trim((string) ($member['suburb'] ?? ''));
            $existingCity   = trim((string) ($member['city'] ?? ''));

            if ($existingSuburb !== '' || $existingCity !== '') {
                $result['skipped_has_data']++;
                if (count($result['samples_skipped']) < 10) {
                    $result['samples_skipped'][] = [
                        'id'        => (int) $member['id'],
                        'member_id' => $row['member_id_raw'],
                        'name'      => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                        'existing'  => $existingSuburb !== '' ? $existingSuburb : $existingCity,
                        'csv'       => $row['suburb'],
                    ];
                }
                continue;
            }

            if ($apply && $updateStmt) {
                $params = ['id' => (int) $member['id']];
                if ($hasSuburb) { $params['suburb'] = $row['suburb']; }
                if ($hasCity)   { $params['city']   = $row['suburb']; }
                $updateStmt->execute($params);
                $result['updated']++;
            } else {
                $result['would_update']++;
            }
            if (count($result['samples_updated']) < 10) {
                $result['samples_updated'][] = [
                    'id'        => (int) $member['id'],
                    'member_id' => $row['member_id_raw'],
                    'name'      => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                    'suburb'    => $row['suburb'],
                ];
            }
        }
        if ($apply) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    $mode = $_POST['mode'] ?? 'dry-run';
    try {
        $result = ms_runMerge($csvPaths, $mode === 'apply');
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// GET → simple admin page with two buttons.
$csrf = Csrf::token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Merge member suburbs</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: -apple-system, system-ui, sans-serif; max-width: 860px; margin: 2rem auto; padding: 0 1rem; color: #1f2937; }
  h1 { font-size: 1.4rem; }
  .card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; margin: 1rem 0; }
  button { background: #111827; color: #fff; border: 0; padding: .65rem 1.1rem; border-radius: 8px; font-weight: 600; cursor: pointer; margin-right: .5rem; }
  button.apply { background: #b45309; }
  button:disabled { opacity: .5; cursor: not-allowed; }
  pre { background: #111827; color: #f3f4f6; padding: 1rem; border-radius: 8px; overflow: auto; max-height: 480px; }
  .muted { color: #6b7280; font-size: .9rem; }
</style>
</head>
<body>
  <h1>Merge member suburbs from import CSVs</h1>
  <p class="muted">
    Source: <code>scripts/data/import_main_life.csv</code> + <code>scripts/data/import_associates.csv</code>.
    Updates <code>members.suburb</code> and <code>members.city</code> only where <strong>both</strong>
    are currently empty. Never overwrites existing data. Match by
    <code>member_number_base</code> + <code>member_number_suffix</code>.
  </p>

  <div class="card">
    <button id="btnDry">1. Dry run</button>
    <button id="btnApply" class="apply" disabled>2. Apply</button>
    <p class="muted">Run the dry run first; the Apply button enables once you've reviewed the preview.</p>
  </div>

  <div id="output"></div>

<script>
  const csrf = <?= json_encode($csrf) ?>;
  const output = document.getElementById('output');
  const btnDry = document.getElementById('btnDry');
  const btnApply = document.getElementById('btnApply');

  async function run(mode) {
    btnDry.disabled = btnApply.disabled = true;
    output.innerHTML = '<p>Running ' + mode + '…</p>';
    try {
      const body = new URLSearchParams({ csrf_token: csrf, mode });
      const res = await fetch(location.pathname, { method: 'POST', body });
      const data = await res.json();
      const heading = mode === 'apply' ? 'Applied' : 'Dry run preview';
      output.innerHTML = '<div class="card"><h2>' + heading + '</h2><pre>' + JSON.stringify(data, null, 2) + '</pre></div>';
      if (mode === 'dry-run' && !data.error) {
        btnApply.disabled = false;
      }
    } catch (err) {
      output.innerHTML = '<div class="card"><strong>Error:</strong> ' + err.message + '</div>';
    } finally {
      btnDry.disabled = false;
      if (mode === 'apply') {
        btnApply.disabled = true;
      }
    }
  }

  btnDry.addEventListener('click', () => run('dry-run'));
  btnApply.addEventListener('click', () => {
    if (confirm('Apply suburb merge to the live database?')) {
      run('apply');
    }
  });
</script>
</body>
</html>
