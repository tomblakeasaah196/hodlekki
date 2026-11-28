<?php
// /includes/person_name.php
//
// A person's middle name (owner, 28 Nov 2026): optional, but important.
// users.middle_name (migration 20261226090000) is typed wherever a person's
// record is made or edited: Congregation, Embrace, the public Connect card,
// the Events "Can't find them?" wizard and their own Profile (the member may
// fill a blank one; changing it after that is a name correction).
//
// The FULL name is first, middle, last: person_full_name(). It is what the
// register, a person's details, Profile, exports, search and the training
// certificate show. SHORT names stay as they are on purpose: "Ada O." on
// public screens, first names on Charis and in greetings, SMS merge fields.
//
// Degrade before the migration: the tree is copied to production before
// db/migrate.php runs, so every query asks person_middle_sql() for the column
// (it selects NULL until the column exists) and writers call
// person_middle_ready() before touching it.
//
// Tests: php tests/person_name_test.php (SQLite).

/** Longest middle name kept (the column is VARCHAR(100)). */
if (!defined('PERSON_MIDDLE_MAX')) {
    define('PERSON_MIDDLE_MAX', 100);
}

if (!function_exists('person_middle_ready')) {

    /** Has users.middle_name arrived (the migration ran)? */
    function person_middle_ready(PDO $pdo): bool
    {
        static $ready = [];
        $key = spl_object_id($pdo);
        if (!array_key_exists($key, $ready)) {
            try {
                $pdo->query('SELECT middle_name FROM users WHERE 1 = 0');
                $ready[$key] = true;
            } catch (Throwable $e) {
                $ready[$key] = false;
            }
        }
        return $ready[$key];
    }

    /**
     * The column for a SELECT list, e.g. "{$mid} AS middle_name": the real
     * column once it exists, NULL before. $alias is the users table's alias
     * in the query ('' for none).
     */
    function person_middle_sql(PDO $pdo, string $alias = 'u'): string
    {
        if (!person_middle_ready($pdo)) {
            return 'NULL';
        }
        return ($alias === '' ? '' : $alias . '.') . 'middle_name';
    }

    /**
     * A typed middle name as it is stored: trimmed, inner spaces single, no
     * control characters, at most PERSON_MIDDLE_MAX characters. Blank = NULL.
     */
    function person_middle_clean($raw): ?string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($raw ?? ''));
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) $s));
        if ($s === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            $s = rtrim(mb_substr($s, 0, PERSON_MIDDLE_MAX, 'UTF-8'));
        } else {
            $s = rtrim(substr($s, 0, PERSON_MIDDLE_MAX));
        }
        return $s === '' ? null : $s;
    }

    /** "Ada Chiamaka Obi": first, middle, last, blanks skipped, single-spaced. */
    function person_full_name($first, $middle = null, $last = null): string
    {
        $parts = [];
        foreach ([$first, $middle, $last] as $p) {
            $p = trim((string) ($p ?? ''));
            if ($p !== '') {
                $parts[] = $p;
            }
        }
        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /** person_full_name() for a row that has first_name / middle_name / last_name keys. */
    function person_row_name(array $row, string $prefix = ''): string
    {
        return person_full_name(
            $row[$prefix . 'first_name'] ?? null,
            $row[$prefix . 'middle_name'] ?? null,
            $row[$prefix . 'last_name'] ?? null
        );
    }
}
