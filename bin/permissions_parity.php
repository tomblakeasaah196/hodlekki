<?php
// /bin/permissions_parity.php
//
// "Does the day-one permission matrix give everyone exactly what they have
// today?" — answered person by person, on one screen. Read-only: it changes
// nothing and writes no file. Run by the owner in cPanel Terminal before the
// gates are switched over:
//
//     cd ~/public_html/hodlekki && php bin/permissions_parity.php
//
// Exit code: 0 = only the changes the owner and the lead decided,
//            1 = someone would GAIN access unexpectedly,
//            2 = someone would LOSE access unexpectedly.
//
// Options:
//   --detail=<category>  list one category in full, person by person
//                        (closed-hole, lost-role, combined, break-glass, lead,
//                        academy, dead-links, links-added, unexpected-gain,
//                        unexpected-loss)
//   --no-names           counts only (for working on a copy of the data)
//
// Development against a local copy (never production): set PERM_DB_DSN,
// PERM_DB_USER and PERM_DB_PASS instead of using the site's .env.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$opts = getopt('', ['detail:', 'no-names']);
$aliases = [
    'closed-hole' => 'closed hole', 'lost-role' => 'lost global role', 'combined' => 'combined roles',
    'break-glass' => 'break-glass', 'lead' => 'lead decision', 'academy' => 'academy roster',
    'dead-links' => 'dead link removed', 'links-added' => 'link added',
    'unexpected-gain' => 'UNEXPECTED gain', 'unexpected-loss' => 'UNEXPECTED loss',
];
$detail = null;
if (isset($opts['detail'])) {
    $detail = $aliases[$opts['detail']] ?? null;
    if ($detail === null) {
        fwrite(STDERR, 'Unknown --detail. One of: ' . implode(', ', array_keys($aliases)) . "\n");
        exit(64);
    }
}

if (getenv('PERM_DB_DSN')) {
    $pdo = new PDO((string) getenv('PERM_DB_DSN'), (string) getenv('PERM_DB_USER'), (string) getenv('PERM_DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} else {
    // db.php loads .env from DOCUMENT_ROOT, which is empty under CLI.
    $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
    require_once __DIR__ . '/../includes/db.php';
}

require_once __DIR__ . '/../includes/permissions/parity.php';

perm_use_pdo($pdo);
if (!perm_ready($pdo)) {
    fwrite(STDERR, "The permission tables are not there yet: run php db/migrate.php first.\n");
    exit(3);
}

$run = perm_parity_run($pdo);
echo perm_parity_render($pdo, $run, !isset($opts['no-names']), $detail);
exit(perm_parity_exit_code($run));
