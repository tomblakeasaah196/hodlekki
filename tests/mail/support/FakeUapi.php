<?php

namespace HOD\Mail\Tests;

use HOD\Mail\Admin\Uapi;
use HOD\Mail\Admin\UapiException;

/**
 * cPanel's `uapi` in memory, for tests: mailboxes (with passwords, quota, disk, login suspension), forwarders,
 * automatic replies, and the account's limit. Uapi::call() still runs the real allow-list and domain checks first;
 * only run() is replaced. $calls records every call WITHOUT passwords, so tests can check nothing leaks.
 *
 *   $uapi = FakeUapi::install(['system@hodlekki.org', 'info@hodlekki.org']);
 *   $uapi->failNext['add_pop'] = 'You have reached the maximum allowed email accounts.';
 */
class FakeUapi extends Uapi
{
    /** email => ['password' => string, 'quota' => int, 'used' => int, 'suspended' => bool] */
    public array $pops = [];

    /** list of [from, to] */
    public array $forwarders = [];

    /** email => [subject, body, stop] */
    public array $autoReplies = [];

    public int $accountUsed = 40;

    public int $accountMax = 50;

    /** function => message: the next call to it fails with that message */
    public array $failNext = [];

    /** list of [module, function, params without 'password'] */
    public array $calls = [];

    /** Mail server to keep in step (a new password there too), when the test syncs */
    public ?FakeMailServer $mail = null;

    public static function install(array $existing = [], ?FakeMailServer $mail = null): self
    {
        $u = new self();
        foreach ($existing as $email) {
            $u->pops[strtolower($email)] = ['password' => 'old-' . bin2hex(random_bytes(4)), 'quota' => 1024, 'used' => 10, 'suspended' => false];
        }
        $u->mail = $mail;
        Uapi::use($u);

        return $u;
    }

    protected function run(string $module, string $function, array $params): mixed
    {
        $this->calls[] = [$module, $function, array_diff_key($params, ['password' => true])];
        if (isset($this->failNext[$function])) {
            $message = $this->failNext[$function];
            unset($this->failNext[$function]);
            throw new UapiException($message);
        }
        $email = static function (array $p): string {
            $e = strtolower((string) ($p['email'] ?? ''));

            return str_contains($e, '@') ? $e : $e . '@' . strtolower((string) ($p['domain'] ?? ''));
        };

        switch ($function) {
            case 'list_pops':
            case 'list_pops_with_disk':
                return array_map(fn ($e, $p) => ['email' => $e, 'diskused' => $p['used'], 'diskquota' => $p['quota'],
                    'suspended_login' => $p['suspended'] ? 1 : 0], array_keys($this->pops), $this->pops);
            case 'list_forwarders':
                return array_map(fn ($f) => ['dest' => $f[0], 'forward' => $f[1]], $this->forwarders);
            case 'get_stats':
                return [['name' => 'emailaccounts', 'count' => $this->accountUsed + count($this->pops), 'max' => $this->accountMax]];
            case 'add_pop':
                $e = $email($params);
                if (isset($this->pops[$e])) {
                    throw new UapiException("The account {$e} already exists!");
                }
                $this->pops[$e] = ['password' => (string) $params['password'], 'quota' => (int) $params['quota'], 'used' => 0, 'suspended' => false];
                $this->mail?->addMailbox($e, (string) $params['password']);

                return null;
            case 'passwd_pop':
                $e = $email($params);
                $this->need($e);
                $this->pops[$e]['password'] = (string) $params['password'];
                if ($this->mail && isset($this->mail->mailboxes[$e])) {
                    $this->mail->setPassword($e, (string) $params['password']);
                } elseif ($this->mail) {
                    $this->mail->addMailbox($e, (string) $params['password']);
                }

                return null;
            case 'delete_pop':
                $e = $email($params);
                $this->need($e);
                unset($this->pops[$e]);

                return null;
            case 'edit_pop_quota':
                $e = $email($params);
                $this->need($e);
                $this->pops[$e]['quota'] = (int) $params['quota'];

                return null;
            case 'suspend_login':
            case 'unsuspend_login':
                $e = $email($params);
                $this->need($e);
                $this->pops[$e]['suspended'] = $function === 'suspend_login';

                return null;
            case 'add_auto_responder':
                $this->autoReplies[$email($params)] = [$params['subject'], $params['body'], (int) $params['stop']];

                return null;
            case 'delete_auto_responder':
                unset($this->autoReplies[$email($params)]);

                return null;
            case 'add_forwarder':
                $this->forwarders[] = [strtolower((string) $params['email']), strtolower((string) $params['fwdemail'])];

                return null;
            case 'delete_forwarder':
                $this->forwarders = array_values(array_filter($this->forwarders, fn ($f) => $f !== [strtolower((string) $params['address']), strtolower((string) $params['forwarder'])]));

                return null;
        }
        throw new UapiException("FakeUapi does not know {$function}.");
    }

    private function need(string $email): void
    {
        if (!isset($this->pops[$email])) {
            throw new UapiException("The account {$email} does not exist.");
        }
    }
}
