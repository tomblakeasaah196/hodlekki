<?php

namespace HOD\Mail\Admin;

use HOD\Mail\Config;
/**
 * cPanel through the server's own `uapi` command (lead, 11 Nov 2026: no API token in .env). The cron runs as the
 * hosting account's user, and `uapi` acts on that account with no secret at all.
 *
 *   Uapi::make()->call('Email', 'add_pop', ['email' => 'media', 'domain' => 'hodlekki.org', 'password' => …, 'quota' => 2048])
 *
 * Rules, all enforced here:
 *   - CLI only: the web never runs it; pages queue jobs (Jobs) and the minute cron carries them out.
 *   - proc_open() with an ARGUMENT ARRAY, never a shell string: nothing a person types can become a command.
 *   - Only the functions in ALLOWED; only for Config 'domain' (the account also holds lpc.cm, nfelia.com and other
 *     sites): an `email` must be on that domain (or a bare local part) and a `domain` must be it.
 *   - `uapi` takes parameters only as arguments, so for about a second a new password is visible in the process
 *     list to this hosting account's own processes (CageFS walls other accounts off). Those can already read .env
 *     and the database, so this adds no exposure. Never "fix" it into a shell string. Nothing here logs arguments.
 *
 * Tests replace the runner with Uapi::use(new FakeUapi()).
 */
class Uapi
{
    /** module => functions the ERP may call */
    public const ALLOWED = [
        'Email' => [
            'list_pops', 'list_pops_with_disk', 'add_pop', 'passwd_pop', 'delete_pop', 'edit_pop_quota',
            'suspend_login', 'unsuspend_login', 'add_auto_responder', 'delete_auto_responder',
            'add_forwarder', 'delete_forwarder', 'list_forwarders',
        ],
        'StatsBar' => ['get_stats'],
    ];

    private static ?Uapi $instance = null;

    public static function make(): Uapi
    {
        return self::$instance ??= new Uapi();
    }

    public static function use(?Uapi $uapi): void
    {
        self::$instance = $uapi;
    }

    /**
     * @return mixed the result's data
     * @throws UapiException when refused here, when the command fails, or when cPanel answers with errors
     */
    public function call(string $module, string $function, array $params = []): mixed
    {
        self::check($module, $function, $params);

        return $this->run($module, $function, $params);
    }

    /** The argument list for proc_open (public for the tests) @return list<string> */
    public static function argv(string $module, string $function, array $params): array
    {
        $argv = [(string) Config::get('uapi.binary', '/usr/local/cpanel/bin/uapi'), '--output=json', $module, $function];
        foreach ($params as $key => $value) {
            $argv[] = $key . '=' . (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return $argv;
    }

    /** Refuse anything outside the allow-list or the domain, before any process starts */
    public static function check(string $module, string $function, array $params): void
    {
        if (!in_array($function, self::ALLOWED[$module] ?? [], true)) {
            throw new UapiException("{$module}::{$function} is not a function the ERP may call.");
        }
        $domain = strtolower((string) Config::get('domain'));
        foreach ($params as $key => $value) {
            // control characters never (newlines only in an automatic reply's body: an argument list carries them safely)
            $control = $key === 'body' ? '/[\x00-\x09\x0b-\x1f]/' : '/[\x00-\x1f]/';
            if (!preg_match('/^[a-z_]{1,40}$/', (string) $key) || (is_string($value) && preg_match($control, $value))) {
                throw new UapiException('A parameter for cPanel is not in a form the ERP sends.');
            }
        }
        if (isset($params['domain']) && strtolower((string) $params['domain']) !== $domain) {
            throw new UapiException("Only {$domain} is managed from the ERP.");
        }
        // every address named, including a forwarder's destination, is on this domain: church mail is never routed out
        foreach (['email', 'address', 'fwdemail', 'forwarder'] as $key) {
            if (!isset($params[$key])) {
                continue;
            }
            $email = strtolower((string) $params[$key]);
            if (str_contains($email, '@') ? !str_ends_with($email, '@' . $domain) : in_array($key, ['fwdemail', 'forwarder'], true)) {
                throw new UapiException("Only {$domain} is managed from the ERP.");
            }
        }
    }

    protected function run(string $module, string $function, array $params): mixed
    {
        if (PHP_SAPI !== 'cli') {
            throw new UapiException('cPanel actions run only from the cron.');
        }
        $binary = (string) Config::get('uapi.binary');
        if (!is_file($binary) || !is_executable($binary)) {
            throw new UapiException('The server\'s uapi command was not found at ' . $binary . '.');
        }
        $pipes = [];
        $proc = proc_open(self::argv($module, $function, $params), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new UapiException('The uapi command could not be started.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + (int) Config::get('uapi.timeout', 30);
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new UapiException('cPanel did not answer in time.');
            }
            usleep(50000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return self::parse($out, $function);
    }

    /** uapi's JSON answer → its data, or an exception with cPanel's own words (never the arguments) */
    public static function parse(string $json, string $function): mixed
    {
        $doc = json_decode($json, true);
        $result = is_array($doc) ? ($doc['result'] ?? $doc) : null;
        if (!is_array($result)) {
            throw new UapiException("cPanel gave no readable answer to {$function}.");
        }
        if ((int) ($result['status'] ?? 0) !== 1) {
            $errors = array_filter(array_map('strval', (array) ($result['errors'] ?? [])));
            throw new UapiException($errors ? implode(' ', $errors) : "cPanel refused {$function}.");
        }

        return $result['data'] ?? null;
    }
}
