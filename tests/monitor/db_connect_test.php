<?php
// The database connection's retry while MariaDB restarts (includes/db_connect.php).

require_once dirname(__DIR__, 2) . '/includes/db_connect.php';

$noSocket = new PDOException('SQLSTATE[HY000] [2002] No such file or directory', 2002);
$denied = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'x'@'localhost'", 1045);

$waits = [];
$sleep = function (int $s) use (&$waits): void { $waits[] = $s; };

// The server comes back on the second try: one wait of 1 s, then the connection
$tries = 0;
$pdo = hod_db_connect(function () use (&$tries, $noSocket) {
    if (++$tries < 2) {
        throw $noSocket;
    }
    return new PDO('sqlite::memory:');
}, 3, $sleep);
eq('a restarting server is tried again and the connection comes back', [2, [1]], [$tries, $waits]);
eq('…as a working PDO', true, $pdo instanceof PDO);

// Still down after 3 tries: the error goes up as before, after 1 s and 2 s
[$tries, $waits] = [0, []];
try {
    hod_db_connect(function () use (&$tries, $noSocket) { $tries++; throw $noSocket; }, 3, $sleep);
    $thrown = null;
} catch (PDOException $e) {
    $thrown = $e->getMessage();
}
eq('down for longer: three tries, then the error', [3, [1, 2]], [$tries, $waits]);
has('…the original message', (string) $thrown, '[2002]');

// A wrong password is not a restart: no wait, no retry
[$tries, $waits] = [0, []];
try {
    hod_db_connect(function () use (&$tries, $denied) { $tries++; throw $denied; }, 3, $sleep);
} catch (PDOException $e) {
}
eq('access denied fails at once', [1, []], [$tries, $waits]);

eq('gone away and refused count as a restart, by code or message', [true, true, false],
    [hod_db_is_transient(new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away')),
     hod_db_is_transient(new PDOException('x', 2003)),
     hod_db_is_transient(new PDOException("SQLSTATE[HY000] [1049] Unknown database 'x'", 1049))]);
