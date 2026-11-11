<?php
// /tests/permissions/hardcoding_scan.php
//
// THE HARD-CODING SWEEP. Access is decided by can() and the permission matrix,
// so code must not decide it by a role NAME, a department NUMBER or NAME, the
// switchable session "active role", or the session's role list.
//
// Scans every .php file outside vendor/, tests/, db/ and the permission engine
// itself, with comments stripped, for:
//   - $_SESSION['active_role'] or $_SESSION['roles'] (read anywhere but the
//     sign-in code that fills them)
//   - the role names Super_Admin / Resident_Pastor / Sub_Unit_Head
//   - role_name compared with a literal in SQL
//   - role_in_dept compared with a literal in SQL
//   - department_id compared with a number
//   - a department matched by name (d.name LIKE / = '…')
//
// Known leftovers that are NOT access decisions — who gets a notification
// (moved to perm_holders() in permissions PR 3) and the Departments roster
// editor's own seat lists (moved to the seat-type table in PR 3) — are listed
// in hardcoding_allowlist.txt, one "path | reason" per line. A file on that
// list must still not read the session roles or the active role.
//
// Returns a list of "path:line: what" findings (empty = clean).

function perm_hardcoding_findings(string $root): array
{
    $allow = [];
    foreach (file(__DIR__ . '/hardcoding_allowlist.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $allow[trim(explode('|', $line, 2)[0])] = true;
    }

    $always = [
        'active role' => "/\\\$_SESSION\\s*\\[\\s*'active_role'\\s*\\]/",
        'session role list' => "/\\\$_SESSION\\s*\\[\\s*'roles'\\s*\\]/",
    ];
    $patterns = [
        'role name' => "/'(Super_Admin|Resident_Pastor|Sub_Unit_Head)'/",
        'role_name compared with a literal' => "/role_name\\s*(=|IN\\s*\\()\\s*'/i",
        'role_in_dept compared with a literal' => "/role_in_dept\\s*(=|IN\\s*\\(|NOT\\s+IN\\s*\\()\\s*'/i",
        'department id as a number' => "/department_id\\s*(=|IN\\s*\\()\\s*\\d/i",
        'department matched by name' => "/\\bd\\.name\\s+(LIKE|=)\\s*'/i",
    ];
    $skip = '#^/(vendor|tests|db|node_modules|\.git|\.claude|includes/permissions|includes/hymnal/corpus)(/|$)#';
    // The sign-in code fills the session; the header and shell only DISPLAY the
    // role label under the person's name.
    $sessionWriters = ['/includes/auth_helpers.php', '/includes/header.php', '/includes/ui/shell.php'];

    $out = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
        if (preg_match($skip, $path) || $path === '/includes/permissions.php') {
            continue;
        }
        // Comments stripped, line numbers kept.
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $code .= is_array($tok) ? $tok[1] : $tok;
        }
        $lines = explode("\n", str_replace("\r\n", "\n", $code));
        $rel = ltrim($path, '/');
        foreach ($lines as $i => $text) {
            $checks = in_array($path, $sessionWriters, true) ? [] : $always;
            if (!isset($allow[$rel])) {
                $checks += $patterns;
            }
            foreach ($checks as $what => $re) {
                if (preg_match($re, $text)) {
                    $out[] = $rel . ':' . ($i + 1) . ': ' . $what;
                }
            }
        }
    }
    sort($out);
    return $out;
}
