# Mail: the church's mailboxes in the ERP

Runbook and contracts for the Mail module. PR 1 of 4 (this document grows with each): **1 foundation and
Mailboxes** · 2 reading · 3 writing and signatures · 4 teamwork, alerts and AI. Design and the decisions behind it:
the lead's design notes (11 Nov 2026). The engine is ported from bureau.lpc.cm's Courrier, which runs on this same
cPanel server.

## 1. Decisions (owner and lead, 11 Nov 2026)

| | |
|---|---|
| Kinds | **Department** mailboxes (media@, finance@ …), **church-wide** ones (info@, embrace@ …), **personal** ones (`firstname.lastname@`) for people the permissions matrix allows. |
| Who manages | The ERP: mail admins create, bring in, suspend, reset and remove mailboxes on the Mailboxes screen. **No cPanel token**: the minute cron does the work with the server's own `uapi` command. |
| Passwords | Made by the ERP, encrypted (`MAIL_VAULT_KEY`), **never shown to anyone**. Mail is read in the ERP only (PWA + push). |
| Quota | hodlekki.org holds at most **15** of the hosting account's 50; counted from cPanel; the 16th is refused. `quota.reserved_personal` (10 today) keeps slots for personal mailboxes. |
| Access | Matrix abilities through `can()`; who works from a shared mailbox is that mailbox's **grants** (matrix roles, department seat-type keys, departments, people) at Read / Read and send / Read, send and assign / Manage. **Mail admins do not read** a mailbox without a grant. A personal mailbox opens to its holder only. |
| Disk | 2 GB department and church, 1 GB personal; a warning at 80 %. |
| Holder leaves | Access ends, password reset, login suspended, auto-reply pointing to info@ for 30 days, then removed with a forwarder to info@. Nobody reads the past mail except through an **export approved by two different people** (admin side, pastoral side). |
| Existing | info@ and embrace@ brought in; tom.blake@ brought in as the owner's personal mailbox; **system@ refused** (its password is `MAIL_PASSWORD`, used by `hod_send_mail()`); dmarc@ stays outside the Mail page. |

## 2. Where things are

| Path | What |
|---|---|
| `includes/mail/bootstrap.php` | The only entry point (after `includes/db.php`): a loader for `HOD\Mail\`, `includes/permissions.php`, `functions.php`. |
| `includes/mail/functions.php` | `mail_ready()`, **`mail_can()`**, `mail_json_error()`, `mail_nav_visible()`, `mail_nav_unread()`. |
| `includes/mail/{Imap,Smtp,Mime,Sync}/` | The protocol layer and sync (ported from Courrier: no ext-imap, no mail library). |
| `includes/mail/Admin/` | `Uapi` (the `uapi` runner), `Jobs` (the queue), `Quota`, `Lifecycle`, `Grants`, `Exports`, `Nightly`, `Addresses`, `Health`, `Audit`. |
| `includes/mail/*.php` | `MailAccess` (THE decision), `Store`, `Vault`, `MailServer`, `MailActions`, `MailFetch`, `MailTransport`, `MailNotices`, `MailNav`, `People`, `Db`, `Clock`, `Config`, `Events`. |
| `includes/mail/mail_config.php` | Settings (non-secret; `.env` overrides). |
| `modules/mail/index.php`, `mailboxes.php` | The Mail page (PR 1: the latest conversations), the Mailboxes screen. Strict mode. |
| `api/mail_api.php`, `api/mail_admin_api.php`, `api/mail_export.php` | JSON for the Mail page, the Mailboxes screen, the export download. |
| `assets/ui/js/pages/mail.js`, `mail-mailboxes.js` | Page scripts. |
| `cron/mail_minute.php`, `cron/mail_daily.php` | The crons (section 6). |
| `bin/mail_doctor.php`, `bin/mail_rekey.php` | Health from the shell; moving to a new vault key. |
| `db/migrations/20261128090000_mail_core.sql` | Every Mail table, plus the one day-one matrix cell. |
| `tests/mail/` | `run.php` (no database), `integration.php` (MySQL 8 / MariaDB 10.11). |

## 3. Access (contract)

**One decision:** `HOD\Mail\MailAccess::decide($userId, $action, $mailbox = null)`, procedural front
`mail_can($action, $mailbox = null, $userId = null)`. Nothing else in the module looks at roles, and no role, seat
or department **name** appears in code (`tests/mail/unit.php` checks).

| Action | Means |
|---|---|
| `admin` | `can('mail', 'manage')`: the Mailboxes screen. Not reading. |
| `hold_personal` | `can('mail.personal', 'view')`: may hold a personal mailbox. |
| `ai` | `can('mail.ai', 'view')` (PR 4). |
| `export_admin` | `can('mail.export_admin', 'approve')`. |
| `export_pastoral` | `mail.export_pastoral` approve, **break-glass not counted**: one Super Admin can never give both sides. Day one: Resident Pastor (seeded by the migration). |
| `read` < `send` (= `organise`) < `assign` < `manage` | On a mailbox: from its grants (shared) or `owner` (personal). |

Grants (`mail_grants`): `subject_type` `role` (roles.id), `seat` (department id + seat-type key from
`perm_seat_types`: `worker`, `deputy_hod`, `hod`, `sub_unit_head`, `director`, `pastor_in_charge` …), `department`
(any live seat there), `user`. Resolved live with `perm_person_roles()`, `perm_person_seats()`,
`perm_seat_holders()`, `perm_live_seats()`: a roster move or a role change applies on the next page load.
`MailAccess::readers($mailbox)` lists who can open it now (alerts, pickers, the nightly snapshot).

Module keys in `includes/permissions/modules.php`: `mail`, `mail.personal`, `mail.ai`, `mail.export_admin`,
`mail.export_pastoral`. A new key the module calls must be added there (unknown modules fail closed).

## 4. cPanel through `uapi` (no token)

- Pages only **queue** a job (`mail_admin_jobs`); `cron/mail_minute.php` runs jobs first, one per mailbox at a
  time, in order. The screen shows "waiting for the server" and polls `api/mail_admin_api.php?action=job`.
- `HOD\Mail\Admin\Uapi`: CLI only; `proc_open()` with an **argument list** (never a shell string); only the
  functions in `Uapi::ALLOWED`; every address, including a forwarder's destination, on `MAIL_DOMAIN` (the account
  also holds lpc.cm, nfelia.com and others). Never make it a shell string.
- `uapi` takes parameters only as arguments, so a new password is visible for about a second to the hosting
  account's own processes (CageFS walls other accounts off). They can already read `.env` and the database: no new
  exposure. Accepted by the lead. **Check `uapi --help` on the server** (`bin/mail_doctor.php` does) and switch
  the runner to stdin input if it exists.
- A job left running 10 minutes is retried once; handlers check cPanel's real state first (an existing mailbox is
  given its password again rather than made twice).

## 5. Vault

`MAIL_VAULT_KEY` (64 hex, its own key). AES-256-GCM, stored `v1:<key id>:<base64>`, associated data
`hod-mail:<mailbox id>:<address>` (a value copied onto another row will not decrypt). Passwords are made only in
the cron's jobs, stored as `secret_pending` **before** cPanel takes them, then moved to `secret`; `MailServer`
tries a pending password when the current one is refused and keeps whichever works. Never in JSON, logs,
exceptions, the session, the audit log or the page. Key change: `MAIL_VAULT_KEY_PREVIOUS` + `bin/mail_rekey.php`;
key lost: `bin/mail_rekey.php --reset-all`.

## 6. Crons (the owner adds them in cPanel)

```
* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/mail_minute.php >/dev/null 2>&1
45 6 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/mail_daily.php >> /home/smartqaq/logs/hod-mail.log 2>&1
```

- **Minute**: jobs; the mailbox count every 5 minutes or after a job; (PR 3) the outbox; sync within 45 s. A folder
  whose MESSAGES, UNSEEN, UIDNEXT and UIDVALIDITY have not moved skips the full pass (one at least hourly).
  `--verbose`, `--account=<id>`, `--jobs-only`.
- **Daily**: disk and the 80 % warnings; mailboxes gone from the server; the access diff (someone lost access to a
  shared mailbox -> a new password; a personal holder lost `mail.personal` or their account -> the leaving path);
  removals that are due; export downloads past 7 days; pruning.

## 7. Sync (from Courrier)

12 months copied (headers, text, FULLTEXT); bodies over 1 MB, older mail and every attachment read live. Batches
of 40, newest first; CONDSTORE flags; a changed UIDVALIDITY re-reads the folder; one sync per mailbox at a time
(`GET_LOCK`); a blip (timeout, unreachable) is retried once. A refused password marks the mailbox `needs_reset` and
tells the mail admins once. `mail_messages.is_auto` marks out-of-office answers and delivery reports.
`mail_accounts.change_stamp` moves on every change a list shows. Event `mail.received` (new Inbox mail, not a first
copy, not older than 48 h) for PR 4's listeners in `includes/mail/Listeners/`.

## 8. Export of a departed holder's mail

Requested on the Mailboxes screen (reason required). Two approvals by two different people, one per side. Then a job
lifts the login suspension; the requester alone downloads each folder as mbox (`api/mail_export.php`), streamed
from the server, never stored here, for 7 days; then a job suspends the login again. Every step:
`mail_export_events` and `security_audit_log`. The removal waits for an open request at most 30 more days.

## 9. Before the migration, and on deploy

The tree reaches production before `db/migrate.php`: every entry asks `mail_ready()` and shows "Mail is being set
up". After the deploy (lead + owner):

1. `MAIL_VAULT_KEY` in the docroot `.env` (made on the server; never in chat).
2. The two cron lines (section 6).
3. `/usr/local/bin/ea-php83 bin/mail_doctor.php`: tables, key, `uapi`, the stdin question, the TLS names.
4. Mailboxes screen: bring in info@ and embrace@ (church) and tom.blake@ (personal, the owner); then grants:
   embrace@ = Embrace department seats `hod` and `deputy_hod` *Read, send and assign*, `worker` *Read and send*;
   info@ = matrix roles Super Admin and Resident Pastor *Read, send and assign*, Administrator *Read*.

## 10. Tests

- `php tests/mail/run.php` (lint job): the module's rules (`unit.php`: every permission key exists, vault, addresses,
  the quota rule, `uapi` refusals, automatic replies, no server-missing extension, no shell, no role name, LF only,
  every class exists, the migration's shape), and Courrier's protocol tests (parsing, and the IMAP and SMTP clients
  against scripted servers over real sockets).
- `php tests/mail/integration.php` (integration job, on the database `tests/special_events/db_setup.php` built,
  MySQL 8 and MariaDB 10.11; the migration carries the `_mail_` marker so it must apply on both): access through the
  real `can()`, grants by seat key and role, personal privacy, the quota and the race for the last slot, every job,
  the vault (no password anywhere else; a half-done rotation), sync and automatic replies, the leaving path, the
  export with two people, removal with a forwarder, the nightly access diff.
- Fakes: `tests/mail/support/FakeMailServer.php` (behaves like Dovecot; ported from Courrier), `FakeUapi.php`.
