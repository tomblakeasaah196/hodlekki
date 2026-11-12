<?php
// /tests/mail/unit.php
//
// Mail, the parts that need no server and no database: the permission keys the module calls, the vault, addresses,
// the quota rule, the `uapi` runner's refusals, automatic replies, and static rules over the module's code.
//
//   php tests/mail/unit.php

require __DIR__ . '/support/lib.php';

use HOD\Mail\Admin\Addresses;
use HOD\Mail\Admin\Quota;
use HOD\Mail\Admin\Uapi;
use HOD\Mail\Admin\UapiException;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\Mime\Headers;
use HOD\Mail\Sync\MessageWriter;
use HOD\Mail\Vault;

$root = $GLOBALS['__mail_root'];

section('Every permission key the module calls exists in the registry');
perm_set_strict(true);
$modules = perm_modules();
foreach (MailAccess::ABILITIES as $ability => [$module, $level, $breakGlass]) {
    check("{$ability}: {$module} / {$level}", isset($modules[$module]) && perm_known($module, $level));
}
check('the pastoral export side does not accept break-glass', MailAccess::ABILITIES['export_pastoral'][2] === false);
$navSections = array_keys(ui_nav_sections_for_test($root));
foreach ($modules as $key => $def) {
    if (str_starts_with($key, 'mail')) {
        check("{$key} sits in a nav section", in_array($def['group'], $navSections, true));
    }
}

section('Vault');
$id = 7;
$enc = Vault::encrypt('pa55-word!', $id, 'info@hodlekki.org');
check('round trip', Vault::decrypt($enc, $id, 'info@hodlekki.org') === 'pa55-word!');
check('stored as v1:<key id>:<payload>, no plain text', str_starts_with($enc, 'v1:') && !str_contains($enc, 'pa55'));
check('another row cannot read it (associated data)', Vault::decrypt($enc, 8, 'info@hodlekki.org') === null
    && Vault::decrypt($enc, $id, 'embrace@hodlekki.org') === null);
check('a damaged value reads as null', Vault::decrypt(substr($enc, 0, -4) . 'AAAA', $id, 'info@hodlekki.org') === null && Vault::decrypt('plain', $id, 'x') === null);
Vault::useKeys(str_repeat('b2', 32), str_repeat('a1', 32));
check('after a key change, the previous key still reads it', Vault::decrypt($enc, $id, 'info@hodlekki.org') === 'pa55-word!');
check('and it is reported as not current', !Vault::isCurrent($enc) && Vault::isCurrent(Vault::encrypt('x', 1, 'a@b')));
Vault::useKeys(str_repeat('c3', 32));
check('an unknown key reads nothing', Vault::decrypt($enc, $id, 'info@hodlekki.org') === null);
Vault::useKeys('not-hex');
check('a malformed key is not ready and refuses to encrypt', !Vault::ready() && throws(fn () => Vault::encrypt('x', 1, 'a@b')) !== null);
Vault::useKeys(str_repeat('a1', 32));
$pw = Vault::newPassword();
check('new passwords: 24 characters, every class', strlen($pw) === 24 && preg_match('/[A-Z]/', $pw) && preg_match('/[a-z]/', $pw)
    && preg_match('/[0-9]/', $pw) && preg_match('/[-_.!@#%+=]/', $pw));
check('and different every time', Vault::newPassword() !== $pw);

section('Addresses');
check('Yoruba and French names fold to ASCII', Addresses::fold('Adébáyọ̀') === 'adebayo' && Addresses::fold('Ọlá-Olúwa') === 'ola-oluwa' && Addresses::fold('Chloé') === 'chloe');
check('firstname.lastname', Addresses::suggest('Tom-Blake', 'Asaah') === 'tom.asaah' && Addresses::suggest('Grace Ada', 'Okafor-Bello') === 'grace.okafor-bello');
check('an empty last name gives the first name only', Addresses::suggest('Ifeoluwa', '') === 'ifeoluwa');

section('Quota rule');
$e = throws(fn () => Quota::judge('personal', 15, 15, 40, 50, 0));
check('the 16th mailbox is refused', $e instanceof MailError && str_contains($e->getMessage(), '15 of its 15'));
$e = throws(fn () => Quota::judge('personal', 5, 15, 50, 50, 0));
check('a full hosting account is refused', $e instanceof MailError && str_contains($e->getMessage(), 'hosting account'));
check('room is room', throws(fn () => Quota::judge('church', 5, 15, 45, 50, 0)) === null);
$e = throws(fn () => Quota::judge('department', 5, 15, 45, 50, 10));
check('a department mailbox may not use the slots kept for personal ones', $e instanceof MailError && str_contains($e->getMessage(), 'kept for personal'));
check('a personal mailbox may', throws(fn () => Quota::judge('personal', 5, 15, 45, 50, 10)) === null);
check('a church mailbox fits while more slots are free than kept', throws(fn () => Quota::judge('church', 4, 15, 45, 50, 10)) === null);

section('uapi runner: refused before any process starts');
$refused = static fn (string $m, string $f, array $p): bool => throws(fn () => Uapi::check($m, $f, $p)) instanceof UapiException;
check('a function outside the allow-list', $refused('Email', 'delete_domain', []) && $refused('Mysql', 'list_databases', []) && $refused('Fileman', 'save_file_content', []));
check('another domain', $refused('Email', 'add_pop', ['email' => 'x', 'domain' => 'lpc.cm']) && $refused('Email', 'passwd_pop', ['email' => 'info@nfelia.com']));
check('a forwarder pointing outside the domain', $refused('Email', 'add_forwarder', ['domain' => 'hodlekki.org', 'email' => 'old@hodlekki.org', 'fwdopt' => 'fwd', 'fwdemail' => 'me@gmail.com'])
    && $refused('Email', 'add_forwarder', ['domain' => 'hodlekki.org', 'email' => 'old@hodlekki.org', 'fwdemail' => 'info']));
check('control characters and odd parameter names', $refused('Email', 'add_pop', ['email' => "x\nquota=0", 'domain' => 'hodlekki.org']) && $refused('Email', 'add_pop', ['--output' => 'x']));
check('an allowed call passes', throws(fn () => Uapi::check('Email', 'add_forwarder', ['domain' => 'hodlekki.org', 'email' => 'old@hodlekki.org', 'fwdopt' => 'fwd', 'fwdemail' => 'info@hodlekki.org'])) === null);
$argv = Uapi::argv('Email', 'add_pop', ['email' => 'a b', 'password' => 'p;rm -rf', 'quota' => 2048]);
check('arguments are a list (no shell): odd characters stay inside one argument', $argv[2] === 'Email' && $argv[3] === 'add_pop'
    && in_array('password=p;rm -rf', $argv, true) && in_array('email=a b', $argv, true) && $argv[1] === '--output=json');
check('a uapi answer: data', Uapi::parse('{"result":{"status":1,"data":[{"email":"a@hodlekki.org"}]}}', 'list_pops') === [['email' => 'a@hodlekki.org']]);
$e = throws(fn () => Uapi::parse('{"result":{"status":0,"errors":["You have reached the maximum allowed email accounts."]}}', 'add_pop'));
check("a uapi refusal carries cPanel's words", $e instanceof UapiException && str_contains($e->getMessage(), 'maximum allowed'));
check('garbage is a clear failure', throws(fn () => Uapi::parse('Segmentation fault', 'add_pop')) instanceof UapiException);

section('Automatic replies are recognised');
$h = static fn (string $raw): array => Headers::parse($raw);
check('Auto-Submitted: auto-replied', MessageWriter::isAutomatic($h("Auto-Submitted: auto-replied\r\n")));
check('Auto-Submitted: no is a person', !MessageWriter::isAutomatic($h("Auto-Submitted: no\r\n")));
check('X-Autoreply and Precedence: auto_reply', MessageWriter::isAutomatic($h("X-Autoreply: yes\r\n")) && MessageWriter::isAutomatic($h("Precedence: auto_reply\r\n")));
check('a delivery report', MessageWriter::isAutomatic($h("Content-Type: multipart/report; report-type=delivery-status\r\n")));
check('a newsletter is not an automatic reply', !MessageWriter::isAutomatic($h("Precedence: bulk\r\nList-Unsubscribe: <https://x>\r\n")));

section('Static rules over the module');
$files = [];
foreach (['includes/mail', 'tests/mail/support'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}
foreach (array_merge(glob("$root/api/mail_*.php"), glob("$root/modules/mail/*.php"), glob("$root/cron/mail_*.php"), ["$root/bin/mail_doctor.php", "$root/bin/mail_rekey.php"]) as $f) {
    $files[] = $f;
}
$engine = array_filter($files, fn ($f) => str_contains(str_replace('\\', '/', $f), '/includes/mail/'));
$code = static fn (string $f): string => implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all(file_get_contents($f))));
$missingOnServer = '/\b(Normalizer|Transliterator|NumberFormatter|Collator|IntlDateFormatter|finfo|mime_content_type|sodium_\w+|gmp_\w+|imap_\w+)\s*(\(|::)/';
$bad = array_filter($engine, fn ($f) => preg_match($missingOnServer, $code($f)));
check('no intl, fileinfo, sodium, gmp or imap calls (the web PHP lacks them)', !$bad, implode(', ', array_map('basename', $bad)));
$shell = array_filter($files, fn ($f) => preg_match('/(?<![\w:>$])(?<!function )(shell_exec|exec|system|passthru|popen)\s*\(/', $code($f)) && basename($f) !== 'mail_doctor.php');
check('no shell: only Uapi starts a process (proc_open with an argument list)', !$shell, implode(', ', array_map('basename', $shell)));
$roleNames = array_filter($engine, fn ($f) => preg_match("/'(Super_Admin|Resident_Pastor|Assoc_Pastor|Director|HOD|Sub_Unit_Head|Worker|Administrator)'/", $code($f)));
check('no role or seat NAME in the engine (ids and keys only)', !$roleNames, implode(', ', array_map('basename', $roleNames)));
$activeRole = array_filter($files, fn ($f) => str_contains($code($f), "active_role"));
check("never \$_SESSION['active_role']", !$activeRole, implode(', ', array_map('basename', $activeRole)));
$cr = array_filter(array_merge($files, glob("$root/db/migrations/*_mail_*.sql"), glob("$root/assets/ui/js/pages/mail*.js")), fn ($f) => str_contains(file_get_contents($f), "\r"));
check('LF only', !$cr, implode(', ', array_map('basename', $cr)));

$classes = [];
foreach ($files as $f) {
    preg_match_all('/(?:use\s+|new\s+\\\\?|\\\\?)(HOD\\\\Mail\\\\[A-Za-z\\\\]+)/', $code($f), $m);
    foreach ($m[1] as $c) {
        $classes[rtrim($c, '\\')] = true;
    }
}
$missing = [];
foreach (array_keys($classes) as $class) {
    if (str_starts_with($class . '\\', 'HOD\\Mail\\Tests\\')) {
        continue;
    }
    if (!class_exists($class) && !interface_exists($class) && !is_dir("$root/includes/mail/" . str_replace('\\', '/', substr($class, 9)))) {
        $missing[] = $class;
    }
}
check('every HOD\\Mail class the module names exists', !$missing, implode(', ', $missing));

$migrations = glob("$root/db/migrations/*_mail_*.sql");
check('the Mail migrations carry the _mail_ marker (core, reading, writing)', array_map('basename', $migrations) === ['20261128090000_mail_core.sql', '20261201090000_mail_read.sql', '20261203090000_mail_compose.sql']);
foreach ($migrations as $migration) {
    $sql = (string) preg_replace('/^--.*$/m', '', (string) file_get_contents($migration));
    check(basename($migration) . ': idempotent DDL only, no DELIMITER', !preg_match('/\bDELIMITER\b|CREATE TABLE (?!IF NOT EXISTS)|\bDROP |ALTER TABLE/', $sql));
}
check('the shared integration harness requires it on both engines', str_contains((string) file_get_contents("$root/tests/special_events/db_setup.php"), "'_mail_'"));

mail_test_done();

/** The nav sections, read without booting the shell */
function ui_nav_sections_for_test(string $root): array
{
    require_once $root . '/includes/ui/nav.php';

    return ui_nav_sections();
}
