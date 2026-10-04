<?php
// /tests/special_events/dev_router.php
//
// Router for PHP's built-in server, so /e/<slug> works locally without
// Apache. It mimics e/.htaccess (guide §8.8): real files pass through,
// everything else under /e/ goes to e/index.php with __slug and __path.
//
// Usage, from the repository root:
//   php -S localhost:8080 -t . tests/special_events/dev_router.php
//
// This file is for local development only. `tests/` is excluded from the
// deploy by .deployignore, and the CLI guard below keeps it inert anyway if a
// copy ever reaches a docroot.

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit("This router is only for PHP's built-in development server.\n");
}

$root = dirname(__DIR__, 2);
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri  = rawurldecode($uri);

// Never serve the dotfiles Apache denies, or anything outside the tree.
if (str_contains($uri, '..')) {
    http_response_code(400);
    exit('Bad request.');
}
if (preg_match('#(^|/)\.(git|env|htaccess|user\.ini|deployignore)#i', $uri)) {
    http_response_code(403);
    exit('Forbidden.');
}

$file = $root . $uri;

// /live/*.json is served statically in production by LiteSpeed (§23.1).
if (is_file($file)) {
    // Let the built-in server stream it, but fix the types it gets wrong.
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'mjs') {
        header('Content-Type: text/javascript');
        readfile($file);
        return true;
    }
    return false;
}

// /e/ and /e/<slug>/<path...>
if (preg_match('#^/e/?$#', $uri)) {
    $_GET['__slug'] = '';
    $_GET['__path'] = '';
    require $root . '/e/index.php';
    return true;
}

if (preg_match('#^/e/([A-Za-z0-9][A-Za-z0-9-]{0,39})(?:/(.*))?$#', $uri, $m)) {
    $_GET['__slug'] = $m[1];
    $_GET['__path'] = $m[2] ?? '';
    require $root . '/e/index.php';
    return true;
}

// Directory index, as DirectoryIndex does.
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    require rtrim($file, '/') . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found: {$uri}\n";
return true;
