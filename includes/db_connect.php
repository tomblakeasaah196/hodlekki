<?php
// /includes/db_connect.php
//
// The database connection, with a short retry when the server is restarting.
// On 19 Nov 2026 at 04:48:38 the host restarted MariaDB, and one request that
// landed in the gap failed with "SQLSTATE[HY000] [2002] No such file or
// directory" (the socket was not there yet). An error that means "the server
// is not there right now" is tried again after 1 s, then 2 s. A wrong
// password, user or database name fails at once.

if (!function_exists('hod_db_connect')) {
    /** MySQL client errors that mean the server is restarting or unreachable for a moment */
    function hod_db_is_transient(PDOException $e): bool
    {
        $transient = [2002, 2003, 2006, 2013]; // no socket, refused, gone away, lost during the query
        if (in_array((int) $e->getCode(), $transient, true)) {
            return true;
        }

        return (bool) preg_match('/\[(2002|2003|2006|2013)\]/', $e->getMessage());
    }

    /**
     * $connect() returns a PDO or throws. It is tried up to $tries times while the
     * error is transient, waiting $sleep(1), then $sleep(2) (at most 3 s on 3 tries).
     */
    function hod_db_connect(callable $connect, int $tries = 3, ?callable $sleep = null): PDO
    {
        $sleep ??= static function (int $seconds): void { sleep($seconds); };
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connect();
            } catch (PDOException $e) {
                if ($attempt >= $tries || !hod_db_is_transient($e)) {
                    throw $e;
                }
                $sleep($attempt);
            }
        }
    }
}
