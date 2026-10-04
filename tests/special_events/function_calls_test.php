<?php
// /tests/special_events/function_calls_test.php — every function the module
// calls must exist.
//
// php -l only checks syntax, so a call to a function that was renamed,
// never written or lives in a file nobody loads sails through CI and
// becomes a fatal error on the live site. Two of those shipped: the portal
// called se_portal_program() (never written), which cut every event page
// off halfway, and capacity.php passed the wrong type to se_live_publish().
// This scan catches the first kind: it tokenises the module and reports
// any plain function call that is neither a PHP built-in nor defined
// somewhere in the repository.

$repoRoot = dirname(__DIR__, 2);

/** Every PHP file under $dir (or the file itself), skipping vendor and .git. */
function se_fc_php_files(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    if (!is_dir($path)) {
        return [];
    }

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $name = $file->getPathname();
        if (str_ends_with($name, '.php') && !str_contains($name, '/vendor/') && !str_contains($name, '/.git/')
            && !str_contains($name, '/node_modules/')) {
            $files[] = $name;
        }
    }
    sort($files);

    return $files;
}

/** The previous token that is not whitespace or a comment. */
function se_fc_prev(array $tokens, int $i): mixed
{
    for ($k = $i - 1; $k >= 0; $k--) {
        if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $tokens[$k];
    }

    return null;
}

/** Lower-cased names of every function declared in these sources. */
function se_fc_declared(array $sources): array
{
    $declared = [];
    foreach ($sources as $source) {
        $tokens = token_get_all($source);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if ((is_array($t) && $t[0] === T_WHITESPACE) || $t === '&') {
                    continue;
                }
                if (is_array($t) && $t[0] === T_STRING) {
                    $declared[strtolower($t[1])] = true;
                }
                break;   // a closure: `function (` has no name
            }
        }
    }

    return $declared;
}

/**
 * Plain calls in $source to functions that are neither built in nor in
 * $declared. Returns [name, line] pairs.
 */
function se_fc_unknown_calls(string $source, array $declared): array
{
    static $languageConstructs = ['isset', 'empty', 'list', 'array', 'exit', 'die', 'eval', 'unset', 'match', 'fn',
        'echo', 'print', 'include', 'require', 'include_once', 'require_once', 'declare', 'static', 'self', 'parent'];

    $tokens  = token_get_all($source);
    $n       = count($tokens);
    $unknown = [];

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) {
            continue;
        }

        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || $tokens[$j] !== '(') {
            continue;
        }

        // Methods, static calls, declarations and `new Foo(` are not plain calls.
        $prev = se_fc_prev($tokens, $i);
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                T_FUNCTION, T_NEW, T_CONST, T_NS_SEPARATOR], true)) {
            continue;
        }

        $name  = $t[1];
        $lower = strtolower($name);
        if (isset($declared[$lower]) || function_exists($lower) || in_array($lower, $languageConstructs, true)) {
            continue;
        }
        // A capitalised name without an underscore is a class (catch, instanceof, attributes).
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            continue;
        }

        $unknown[] = [$name, (int) $t[2]];
    }

    return $unknown;
}

echo "    the scanner works\n";
$probeDeclared = se_fc_declared(['<?php function se_fc_known_one(): void {}']);
is_same('a declared function is known', [], se_fc_unknown_calls('<?php se_fc_known_one();', $probeDeclared));
is_same('a built-in is known', [], se_fc_unknown_calls('<?php strlen("x"); array_map(fn($x) => $x, []);', $probeDeclared));
is_same('methods, static calls and new are not plain calls', [],
    se_fc_unknown_calls('<?php $a->nope(); A::nope(); new Nope(); $a?->nope();', $probeDeclared));
is_same('an undefined function is reported with its line', [['se_portal_program', 2]],
    se_fc_unknown_calls("<?php\nse_portal_program(\$pdo);", $probeDeclared));

echo "    every function the module calls exists\n";
$definitionSources = array_map('file_get_contents', array_merge(
    se_fc_php_files($repoRoot . '/includes'),
    se_fc_php_files($repoRoot . '/api'),
    se_fc_php_files($repoRoot . '/e'),
    se_fc_php_files($repoRoot . '/cron'),
    se_fc_php_files($repoRoot . '/modules'),
    glob($repoRoot . '/*.php') ?: []
));
$declared = se_fc_declared($definitionSources);

$moduleFiles = array_merge(
    se_fc_php_files($repoRoot . '/includes/special_events'),
    glob($repoRoot . '/api/special_events_*.php') ?: [],
    se_fc_php_files($repoRoot . '/e'),
    se_fc_php_files($repoRoot . '/modules/special_events'),
    se_fc_php_files($repoRoot . '/cron/special_events.php')
);
ok('the scan found the module files', count($moduleFiles) > 30, count($moduleFiles) . ' files');

$problems = [];
foreach ($moduleFiles as $file) {
    foreach (se_fc_unknown_calls((string) file_get_contents($file), $declared) as [$name, $line]) {
        $problems[] = substr($file, strlen($repoRoot) + 1) . ':' . $line . ' ' . $name . '()';
    }
}
is_same('no call to an undefined function', [], $problems);
