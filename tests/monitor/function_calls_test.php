<?php
// /tests/monitor/function_calls_test.php
//
// Every plain function the monitor's files call must exist: a PHP built-in,
// or defined somewhere in this repository. `php -l` cannot see a call to a
// function never written, and the capture runs on every request, so a typo
// there would only show up as a fatal error in production.

$files = array_merge(
    glob(__DIR__ . '/../../includes/monitor/*.php') ?: [],
    [__DIR__ . '/../../api/monitor_api.php', __DIR__ . '/../../api/monitor_intake.php', __DIR__ . '/../../api/monitor_probe.php',
     __DIR__ . '/../../cron/monitor.php', __DIR__ . '/../../bin/monitor.php', __DIR__ . '/../../monitor_confirm.php']
);

// Functions defined anywhere in the repository (outside vendor/ and tests/).
$defined = array_map('strtolower', get_defined_functions()['internal']);
$repo = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2), FilesystemIterator::SKIP_DOTS));
foreach ($repo as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!str_ends_with($p, '.php') || preg_match('#/(vendor|tests|node_modules|\.git)/#', $p)) {
        continue;
    }
    if (preg_match_all('/function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', (string) file_get_contents($p), $m)) {
        foreach ($m[1] as $name) {
            $defined[] = strtolower($name);
        }
    }
}
$defined = array_flip($defined);
// Optional, guarded by function_exists() at the call: provided by other modules or SAPIs.
foreach (['can', 'csrf_require', 'fastcgi_finish_request', 'litespeed_finish_request', 'opcache_get_status'] as $optional) {
    $defined[$optional] = true;
}

$missing = [];
foreach ($files as $file) {
    $tokens = token_get_all((string) file_get_contents($file));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
            continue;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '(') {
            continue;
        }
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
            $k--;
        }
        $prev = $tokens[$k] ?? null;
        $prevId = is_array($prev) ? $prev[0] : $prev;
        if (in_array($prevId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
            continue;
        }
        $name = strtolower($tokens[$i][1]);
        if (!isset($defined[$name]) && !in_array($name, ['array', 'list', 'isset', 'unset', 'empty', 'match', 'fn', 'exit', 'die', 'eval', 'self', 'static', 'parent'], true)) {
            $missing[] = basename($file) . ':' . $tokens[$i][2] . ' ' . $tokens[$i][1] . '()';
        }
    }
}
eq('every function the monitor calls exists', [], array_values(array_unique($missing)));
