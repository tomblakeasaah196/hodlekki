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

echo "== cropped photos sent as data: URLs\n";
// A 1x1 PNG and a 1x1 JPEG, built here so the test needs no image extension.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$jpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$dataUrl = static fn (string $mime, string $bytes): string => 'data:' . $mime . ';base64,' . base64_encode($bytes);

$img = hod_image_from_data_url($dataUrl('image/png', $png));
ok('a PNG is accepted as png', $img !== null && $img['ext'] === 'png' && $img['bytes'] === $png);
$img = hod_image_from_data_url($dataUrl('image/jpeg', $jpg));
ok('a JPEG is accepted as jpg', $img !== null && $img['ext'] === 'jpg');
$img = hod_image_from_data_url($dataUrl('image/png', $jpg));
ok('the extension follows the bytes, not the label', $img !== null && $img['ext'] === 'jpg');
ok('script bytes labelled image/png refused', hod_image_from_data_url($dataUrl('image/png', '<?php echo 1; ?>')) === null);
ok('HTML labelled image/jpeg refused', hod_image_from_data_url($dataUrl('image/jpeg', '<script>alert(1)</script>')) === null);
// Bytes after a real PNG do not change its type: it is still stored as .png,
// never under an extension a server would run.
$r = hod_image_from_data_url($dataUrl('image/png', $png . '<?php x(); ?>'));
ok('a PNG with bytes appended stays a .png', $r !== null && $r['ext'] === 'png');
foreach (['image/gif', 'image/svg+xml', 'image/webp', 'text/html', 'application/x-php'] as $mime) {
    ok("{$mime} refused", hod_image_from_data_url($dataUrl($mime, $png)) === null);
}
ok('not base64 refused', hod_image_from_data_url('data:image/png;base64,@@@notbase64') === null);
ok('no data: prefix refused', hod_image_from_data_url(base64_encode($png)) === null);
ok('a parameter before base64 refused', hod_image_from_data_url('data:image/png;name=x.php;base64,' . base64_encode($png)) === null);
ok('empty refused', hod_image_from_data_url('') === null && hod_image_from_data_url('data:image/png;base64,') === null);
ok('over the size cap refused', hod_image_from_data_url($dataUrl('image/png', $png), strlen($png) - 1) === null);
ok('at the size cap accepted', hod_image_from_data_url($dataUrl('image/png', $png), strlen($png)) !== null);

$imgDir = sys_get_temp_dir() . '/hod_img_' . getmypid() . '/children/';
$r = hod_upload_store_data_image($dataUrl('image/png', '<?php system($_GET[0]); ?>'), $imgDir, '/uploads/profiles/children/');
ok('a refused photo is reported', $r['ok'] === false && $r['url'] === null && (string) $r['error'] !== '');
ok('no folder made for a refused photo', !is_dir($imgDir));
$r = hod_upload_store_data_image($dataUrl('image/png', $png), $imgDir, '/uploads/profiles/children/');
$stored = $r['ok'] ? $imgDir . basename((string) $r['url']) : '';
ok('a photo is stored', $r['ok'] === true && is_file($stored) && file_get_contents($stored) === $png);
ok('under a random name with its real extension', (bool) preg_match('#^/uploads/profiles/children/\d{8}_[a-f0-9]{16}\.png$#', (string) $r['url']));
if (DIRECTORY_SEPARATOR === '/') {
    ok('the folder is 0755', (fileperms($imgDir) & 0777) === 0755);
}
$r2 = hod_upload_store_data_image($dataUrl('image/jpeg', $jpg), $imgDir, '/u/');
ok('a JPEG is stored as .jpg', $r2['ok'] === true && str_ends_with((string) $r2['url'], '.jpg'));
foreach ((array) glob($imgDir . '*') as $f) {
    @unlink($f);
}
@rmdir($imgDir);
@rmdir(dirname($imgDir));

echo "== the Parent Portal photo goes through it\n";
$api = (string) file_get_contents(__DIR__ . '/../../api/parent_portal_api.php');
$start = strpos($api, "case 'update_child_pic'");
$end = $start === false ? false : strpos($api, 'case ', $start + 10);
$block = ($start === false || $end === false) ? '' : substr($api, $start, $end - $start);
ok('update_child_pic found', $block !== '');
ok('update_child_pic stores through hod_upload_store_data_image()', str_contains($block, 'hod_upload_store_data_image('));
ok('update_child_pic decodes nothing itself', !str_contains($block, 'base64_decode') && !str_contains($block, 'file_put_contents'));
ok('update_child_pic makes no 0777 folder', !str_contains($block, '0777'));

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
