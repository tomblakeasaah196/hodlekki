<?php
// /tests/mail/run.php
//
// Every Mail test that needs no database (CI's lint job): the module's own rules (unit.php), the IMAP / MIME
// parsing ported from Courrier (protocol_unit.php), and the IMAP and SMTP clients against scripted servers over
// real sockets on 127.0.0.1 (protocol_wire.php). Each runs in its own process; any failure fails the run.
// The database half is tests/mail/integration.php (CI's integration job).
//
//   php tests/mail/run.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$failed = 0;
foreach (['unit.php', 'protocol_unit.php', 'protocol_wire.php', 'read_unit.php', 'compose_unit.php'] as $file) {
    echo "=== tests/mail/{$file}\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $file), $code);
    if ($code !== 0) {
        $failed++;
        echo "!!! tests/mail/{$file} failed (exit {$code})\n";
    }
}
echo $failed ? "\nMail tests: {$failed} file(s) failed\n" : "\nMail tests: all passed\n";
exit($failed ? 1 : 0);
