<?php
// /tests/special_events/preload_test.php — SE_PRELOAD must match what each
// entry module actually imports (guide §8.6.1, §22.1).
//
// A preload list that drifts is worse than none: the browser fetches modules
// the page no longer needs, and misses the ones it does.

$assetsRoot = dirname(__DIR__, 2) . '/assets/se';

/**
 * Follow the static import graph of an entry module, resolving the "@se/"
 * prefix and relative paths exactly as the import map does.
 *
 * @return array<string> web paths, e.g. /assets/se/js/core/api.js
 */
function se_import_graph(string $entryWebPath, string $assetsRoot): array
{
    $seen  = [];
    $queue = [$entryWebPath];

    while ($queue) {
        $webPath = array_shift($queue);
        if (isset($seen[$webPath])) {
            continue;
        }
        $seen[$webPath] = true;

        $file = dirname($assetsRoot, 2) . $webPath;
        if (!is_file($file)) {
            continue;
        }

        $source = (string) file_get_contents($file);
        preg_match_all('/\bfrom\s+[\'"]([^\'"]+)[\'"]/', $source, $matches);

        foreach ($matches[1] as $specifier) {
            if (str_starts_with($specifier, '@se/')) {
                $queue[] = '/assets/se/js/' . substr($specifier, 4);
            } elseif (str_starts_with($specifier, '.')) {
                $resolved = realpath(dirname($file) . '/' . $specifier);
                if ($resolved !== false) {
                    $queue[] = str_replace(dirname($assetsRoot, 2), '', $resolved);
                }
            }
            // A bare specifier (preact, htm, …) is a vendored library, which
            // the import map resolves and the page never preloads.
        }
    }

    return array_keys($seen);
}

echo "    every preloaded file exists\n";
foreach (SE_PRELOAD as $surface => $modules) {
    foreach ($modules as $module) {
        ok("{$surface}: {$module} exists", is_file(dirname($assetsRoot, 2) . $module));
    }
}

echo "    every preloaded file is really imported\n";
foreach (SE_PRELOAD as $surface => $modules) {
    $entry = $modules[0] ?? null;
    ok("{$surface}: the first entry is the surface's own main.js",
        $entry !== null && str_ends_with($entry, "/{$surface}/main.js"), (string) $entry);

    if ($entry === null || !is_file(dirname($assetsRoot, 2) . $entry)) {
        continue;
    }

    $graph = se_import_graph($entry, $assetsRoot);

    foreach ($modules as $module) {
        ok("{$surface}: {$module} is in the import graph",
            in_array($module, $graph, true),
            'graph: ' . implode(', ', $graph));
    }
}

echo "    the graph's own core modules are preloaded\n";
foreach (SE_PRELOAD as $surface => $modules) {
    $entry = $modules[0] ?? null;
    if ($entry === null || !is_file(dirname($assetsRoot, 2) . $entry)) {
        continue;
    }

    // Everything the entry pulls in synchronously from @se/core is on the
    // critical path, so it belongs in the preload list.
    foreach (se_import_graph($entry, $assetsRoot) as $module) {
        if (!str_contains($module, '/js/core/')) {
            continue;
        }
        ok("{$surface}: critical module {$module} is preloaded",
            in_array($module, $modules, true),
            'add it to SE_PRELOAD[' . $surface . ']');
    }
}

echo "    no vendored library is preloaded by path\n";
foreach (SE_PRELOAD as $surface => $modules) {
    foreach ($modules as $module) {
        ok("{$surface}: {$module} is app code, not a vendor file",
            !str_contains($module, '/vendor/'));
    }
}
