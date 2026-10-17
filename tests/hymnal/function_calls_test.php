<?php
// /tests/hymnal/function_calls_test.php
//
// Every function the module calls must exist.
//
// php -l checks syntax only, so a call to a function that was renamed, never
// written, or lives in a file nobody requires sails through CI and becomes a
// fatal error on the live site. The Special Events module shipped two of those
// (AGENTS.md), which is why its suite scans for this; the Hymnary is scanned
// the same way. The approach is deliberately the same as
// tests/special_events/function_calls_test.php — tokenise the module and
// report any plain function call that is neither a PHP built-in nor defined
// somewhere in this repository.

$repoRoot = dirname(__DIR__, 2);

/** Every PHP file under $path, skipping vendor and .git. */
function hy_fc_files(string $path): array
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
        if (str_ends_with($name, '.php')
            && !str_contains($name, '/vendor/')
            && !str_contains($name, '/.git/')
            && !str_contains($name, '/node_modules/')) {
            $files[] = $name;
        }
    }
    sort($files);

    return $files;
}

/** The previous token that is not whitespace or a comment. */
function hy_fc_prev(array $tokens, int $i): mixed
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
function hy_fc_declared(array $sources): array
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

/** @return list<array{0:string,1:int}> name and line of each unknown call */
function hy_fc_unknown(string $source, array $declared): array
{
    static $constructs = [
        'isset', 'empty', 'list', 'array', 'exit', 'die', 'eval', 'unset', 'match', 'fn',
        'echo', 'print', 'include', 'require', 'include_once', 'require_once', 'declare',
        'static', 'self', 'parent',
    ];

    $tokens = token_get_all($source);
    $n = count($tokens);
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
        $prev = hy_fc_prev($tokens, $i);
        if (is_array($prev) && in_array($prev[0], [
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
            T_FUNCTION, T_NEW, T_CONST, T_NS_SEPARATOR,
        ], true)) {
            continue;
        }
        $name = $t[1];
        $lower = strtolower($name);
        if (isset($declared[$lower]) || function_exists($lower) || in_array($lower, $constructs, true)) {
            continue;
        }
        // A capitalised name with no underscore is a class (catch, instanceof).
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            continue;
        }
        $unknown[] = [$name, (int) $t[2]];
    }

    return $unknown;
}

section('the scanner itself works');
$probe = hy_fc_declared(['<?php function hy_fc_known(): void {}']);
eq('a declared function is known', [], hy_fc_unknown('<?php hy_fc_known();', $probe));
eq('a built-in is known', [], hy_fc_unknown('<?php strlen("x"); array_map(fn($x) => $x, []);', $probe));
eq('methods, static calls and new are not plain calls', [],
    hy_fc_unknown('<?php $a->no(); A::no(); new No(); $a?->no();', $probe));
eq('an undefined function is reported with its line', [['hymnal_never_written', 2]],
    hy_fc_unknown("<?php\nhymnal_never_written(\$pdo);", $probe));

section('every function the module calls exists');
$definitions = array_map('file_get_contents', array_merge(
    hy_fc_files($repoRoot . '/includes'),
    hy_fc_files($repoRoot . '/api'),
    hy_fc_files($repoRoot . '/modules'),
    hy_fc_files($repoRoot . '/bin'),
    glob($repoRoot . '/*.php') ?: []
));
$declared = hy_fc_declared($definitions);

$moduleFiles = array_merge(
    hy_fc_files($repoRoot . '/includes/hymnal'),
    hy_fc_files($repoRoot . '/modules/hymnal'),
    glob($repoRoot . '/api/hymnal_*.php') ?: [],
    glob($repoRoot . '/bin/build_hymnal.php') ?: []
);
ok('the scan found the module files', count($moduleFiles) >= 12, count($moduleFiles) . ' files');

$problems = [];
foreach ($moduleFiles as $file) {
    foreach (hy_fc_unknown((string) file_get_contents($file), $declared) as [$name, $line]) {
        $problems[] = substr($file, strlen($repoRoot) + 1) . ':' . $line . ' ' . $name . '()';
    }
}
eq('no call to an undefined function', [], $problems);
