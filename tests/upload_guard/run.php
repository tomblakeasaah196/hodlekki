<?php
// /tests/upload_guard/run.php
//
// Uploads are data, never code: the allow-list and random names of
// includes/upload_guard.php, and the .htaccess block bin/uploads_guard.sh
// writes (run for real on a temporary docroot when bash is available).
//   php tests/upload_guard/run.php

require_once __DIR__ . '/../../includes/upload_guard.php';

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL: {$name}\n";
    }
}

$all = array_merge(HOD_UPLOAD_DOCS, HOD_UPLOAD_IMAGES, HOD_UPLOAD_MEDIA);
echo "== allow-list\n";
ok('pdf allowed', hod_upload_extension('Review.PDF', $all) === 'pdf');
ok('docx allowed', hod_upload_extension('my essay.docx', $all) === 'docx');
ok('jpg allowed', hod_upload_extension('photo.JPG', $all) === 'jpg');
foreach (['shell.php', 'x.PHP', 'x.php7', 'x.phtml', 'x.phar', 'x.pht', 'x.phps', 'a.jpg.php', 'x.cgi', 'x.pl',
          'x.py', 'x.sh', 'x.shtml', '.htaccess', '.user.ini', 'noext', 'x.svg', 'x.html', 'x.js'] as $bad) {
    ok("{$bad} refused", hod_upload_extension($bad, $all) === '');
}
ok('double extension keeps only the last', hod_upload_extension('a.php.pdf', $all) === 'pdf');

echo "== random names\n";
$a = hod_upload_name('pdf');
$b = hod_upload_name('pdf');
ok('name shape', (bool) preg_match('/^\d{8}_[a-f0-9]{16}\.pdf$/', $a));
ok('names differ', $a !== $b);

echo "== store refuses before touching the disk\n";
$tmpDir = sys_get_temp_dir() . '/hod_up_' . getmypid();
$r = hod_upload_store(['name' => 'evil.php', 'tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_OK], $tmpDir, '/u/', $all);
ok('php upload refused', $r['ok'] === false && str_contains((string) $r['error'], 'not allowed'));
ok('no folder made for a refused file', !is_dir($tmpDir));
$r = hod_upload_store(['name' => 'a.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE], $tmpDir, '/u/', $all);
ok('failed upload reported', $r['ok'] === false);

echo "== bin/uploads_guard.sh\n";
$onWindows = DIRECTORY_SEPARATOR !== '/';
$bash = $onWindows ? '' : trim((string) @shell_exec('command -v bash 2>/dev/null'));
if ($bash === '') {
    echo "  (skipped: no bash here; CI runs it)\n";
} else {
    $root = sys_get_temp_dir() . '/hod_guard_' . getmypid();
    @mkdir($root . '/uploads', 0755, true);
    @mkdir($root . '/assets/uploads', 0755, true);
    file_put_contents($root . '/uploads/.htaccess', "# kept line\n");
    $script = escapeshellarg(__DIR__ . '/../../bin/uploads_guard.sh');
    exec("bash {$script} " . escapeshellarg($root) . ' 2>&1', $out1, $c1);
    exec("bash {$script} " . escapeshellarg($root) . ' 2>&1', $out2, $c2);
    $ht = (string) file_get_contents($root . '/uploads/.htaccess');
    ok('guard runs', $c1 === 0 && $c2 === 0);
    ok('block written once after two runs', substr_count($ht, '# hod-uploads-guard-begin') === 1);
    ok('existing lines kept', str_contains($ht, '# kept line'));
    ok('assets/uploads guarded too', str_contains((string) @file_get_contents($root . '/assets/uploads/.htaccess'), 'Require all denied'));
    if (preg_match('/<FilesMatch "([^"]+)">/', $ht, $m)) {
        $re = '#' . $m[1] . '#';
        foreach (['a.php', 'a.PHP', 'a.Php7', 'a.phtml', 'a.phar', 'a.pht', 'a.phps', 'a.cgi', 'a.pl', 'a.py', 'a.sh', 'a.shtml'] as $f) {
            ok("guard pattern refuses {$f}", (bool) preg_match($re, $f));
        }
        foreach (['a.pdf', 'a.jpg', 'a.mp3', 'a.docx', 'photo.png'] as $f) {
            ok("guard pattern serves {$f}", !preg_match($re, $f));
        }
    } else {
        ok('FilesMatch present', false);
    }
    exec('rm -rf ' . escapeshellarg($root));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
