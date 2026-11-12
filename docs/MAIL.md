# Mail: the church's mailboxes in the ERP

Runbook and contracts for the Mail module. PR 2 of 4 (this document grows with each): 1 foundation and
Mailboxes · **2 reading** · 3 writing and signatures · 4 teamwork, alerts and AI. Design and the decisions behind it:
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
| Existing | info@ and embrace@ to bring in, and tom.blake@ as the owner's personal mailbox, **only once reading (PR 2) and writing (PR 3) are both live**: bringing one in resets its password, so phones and Outlook lose it; **system@ refused** (its password is `MAIL_PASSWORD`, used by `hod_send_mail()`); dmarc@ stays outside the Mail page. |

## 2. Where things are

| Path | What |
|---|---|
| `includes/mail/bootstrap.php` | The only entry point (after `includes/db.php`): a loader for `HOD\Mail\`, `includes/permissions.php`, `functions.php`. |
| `includes/mail/functions.php` | `mail_ready()`, **`mail_can()`**, `mail_json_error()`, `mail_nav_visible()`, `mail_nav_unread()`. |
| `includes/mail/{Imap,Smtp,Mime,Sync}/` | The protocol layer and sync (ported from Courrier: no ext-imap, no mail library). |
| `includes/mail/Admin/` | `Uapi` (the `uapi` runner), `Jobs` (the queue), `Quota`, `Lifecycle`, `Grants`, `Exports`, `Nightly`, `Addresses`, `Health`, `Audit`. |
| `includes/mail/*.php` | `MailAccess` (THE decision), `Store`, `Vault`, `MailServer`, `MailActions`, `MailFetch`, `MailTransport`, `MailNotices`, `MailNav`, `People`, `Db`, `Clock`, `Config`, `Events`. |
| `includes/mail/mail_config.php` | Settings (non-secret; `.env` overrides). |
| `modules/mail/index.php`, `mailboxes.php` | The Mail page (three panes, section 7c), the Mailboxes screen. Strict mode. |
| `includes/mail/Read/` | Reading (section 7c): the list, the reader, the sanitizer, the partials. |
| `api/mail_api.php`, `api/mail_admin_api.php`, `api/mail_export.php` | JSON for the Mail page, the Mailboxes screen, the export download. |
| `api/mail_view.php`, `api/mail_file.php` | A message's HTML in its own document (own CSP); attachments and pictures. |
| `assets/ui/js/pages/mail.js`, `mail-mailboxes.js`, `assets/css/mail.css` | Page scripts; the Mail page's styles. |
| `cron/mail_minute.php`, `cron/mail_daily.php` | The crons (section 6). |
| `bin/mail_doctor.php`, `bin/mail_rekey.php` | Health from the shell; moving to a new vault key. |
| `db/migrations/20261128090000_mail_core.sql`, `20261201090000_mail_read.sql` | Every Mail table, plus the one day-one matrix cell; picture trust (PR 2). |
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

## 7b. Sending and the domain's hourly budget

Every send from an ERP mailbox goes through `MailTransport::send()`, which takes one slot per recipient in the
`mailbox` lane of the email foundation's shared budget (`email_budget_take()`, docs/EMAIL.md) before SMTP, gives
the slots back if the send fails, and records the host's own "max emails per hour" refusal
(`email_budget_refused()`), which stops every sender for the rest of the hour. So people's replies, alerts,
automations and campaigns together never pass the host's limit. A send refused for the budget is a temporary
`MailSendException` ("…will go in the next hour"); PR 3's outbox retries it. Mail alerts to personal addresses
(PR 4) go through `email_send_now()` with an alert lane, never `hod_send_mail()` directly.

## 7c. Reading (PR 2)

The Mail page has three panes: mailboxes and folders, the conversation list, the reader. On a phone it shows the
list or the reader. The first screen is drawn in PHP; `assets/ui/js/pages/mail.js` then swaps the list and the
reader with pieces from `api/mail_api.php`, drawn by the same partials (`includes/mail/Read/partials/`), so
nothing is built twice.

| Piece | What |
|---|---|
| `includes/mail/Read/` | `Inbox` (where the person is: mailboxes, folder, open conversation; `allows()` asks `MailAccess`), `ThreadList` (a folder or a search over the ERP's 12 months; filters All, Unread, and on shared mailboxes Mine, Open, Waiting, Done), `Screen` (list and reader pieces), `Reader` (messages, bodies, older mail), `Actions` (archive, spam, trash, inbox, move, read, unread, flag, delete for good, empty; Undo), `HtmlSanitizer`, `DarkPalette`, `TextFormatter`, `ImageTrust`, `Format`, `Partial`. |
| `api/mail_api.php` | GET `list`, `counts` (polled each minute: folder counts and a change stamp), `older`, `server_search`; POST (JSON, CSRF) `thread`, `message`, `trust`, `live`, `live_trust`, `act`, `undo`, `empty`, `refresh`. |
| `api/mail_view.php` | The inside of the reader's frame: one HTML message as its own document. |
| `api/mail_file.php` | Attachments, `cid:` pictures and files of older mail, read from the server when asked for. |
| `assets/css/mail.css` | The page's styles, on the `--ui-*` tokens only (light and dark). |
| `db/migrations/20261201090000_mail_read.sql` | `mail_image_trust`: the senders whose pictures a person always shows. |

Rules:

- **A sender's HTML never runs.** `HtmlSanitizer` keeps an allow-list of tags, attributes and CSS (no scripts,
  forms, frames, SVG, `javascript:` or `data:text/html`, no `@import`, no `expression()`), and it carries the two
  JBS HQ fixes: a link that wraps a table keeps its table, and CSS meant only for a dark device is taken out
  (`DarkPalette`), so light text never lands on the white page. The result loads in a frame from
  `api/mail_view.php` (not `srcdoc`, which would inherit the page's strict CSP). That document has its own policy:
  `default-src 'none'`, pictures from the ERP and `data:` only until the person allows them, no forms, no base,
  `frame-ancestors 'self'`, plus `Referrer-Policy: no-referrer`. The frame's sandbox has no `allow-scripts`.
- **Pictures from the internet wait.** They tell the sender the message was opened. "Show pictures" loads them
  once; "Always show" saves that sender for that person only (`mail_image_trust`).
- **Opening marks read for everyone** on a shared mailbox, on the server too, and the message says who opened it
  ("Opened by"). That is why opening is a POST. `api/mail_view.php` never marks anything.
- **Files are never trusted.** Only pictures (not SVG), PDF and plain text open in the browser, and only when their
  first bytes match what they claim (the host's PHP has no fileinfo); the rest downloads as
  `application/octet-stream` with `nosniff`. The ERP keeps no copy.
- **Older than 12 months**: "Load older" and "Search server" read the server live, a page at a time; nothing is kept.
- **Access**: every call goes through `Inbox` / `MailAccess` (Read to read; Read and send to organise). A mailbox
  the person cannot open answers 404, like one that does not exist.
- **No Reply yet.** Reply, Reply all, Forward and New message appear only once PR 3's composer exists
  (`includes/mail/mounts/composer.php`, `Partial::mounted()`). PR 4's team bar mounts the same way.
- Page events, for PR 3 and PR 4: the page raises `mail:compose`, `mail:thread-opened`, `mail:thread-changed`, and
  follows `mail:sent`, `mail:thread-changed`, `mail:refresh` (CustomEvents on `document`).
- Shortcuts: `j` / `k`, `u` or `Esc`, `/`, `e`, `#`, `!`, `s`, `Shift+U`, `?` (and `c`, `r`, `a`, `f` once the
  composer exists). They stay quiet while a field or a dialog has focus.
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
4. Once reading (PR 2) **and** writing (PR 3) are live, not before (lead, 12 Nov 2026): Mailboxes screen, bring in
   info@ and embrace@ (church) and tom.blake@ (personal, the owner); then grants:
   embrace@ = Embrace department seats `hod` and `deputy_hod` *Read, send and assign*, `worker` *Read and send*;
   info@ = matrix roles Super Admin and Resident Pastor *Read, send and assign*, Administrator *Read*.

## 10. Tests

- `php tests/mail/run.php` (lint job): the module's rules (`unit.php`: every permission key exists, vault, addresses,
  the quota rule, `uapi` refusals, automatic replies, no server-missing extension, no shell, no role name, LF only,
  every class exists, the migrations' shape), Courrier's protocol tests (parsing, and the IMAP and SMTP clients
  against scripted servers over real sockets), and reading (`read_unit.php`: an XSS corpus through the sanitizer,
  the two JBS fixes, the frame's policy, text, dates, search words, the partials, the endpoints, short copy).
- `php tests/mail/integration.php` (integration job, on the database `tests/special_events/db_setup.php` built,
  MySQL 8 and MariaDB 10.11; the migration carries the `_mail_` marker so it must apply on both): access through the
  real `can()`, grants by seat key and role, personal privacy, the quota and the race for the last slot, every job,
  the vault (no password anywhere else; a half-done rotation), sync and automatic replies, the leaving path, the
  export with two people, removal with a forwarder, the nightly access diff; reading: lists, filters, search,
  opening marks read, folder actions refused to a reader and Undo, picture trust per person.
- Fakes: `tests/mail/support/FakeMailServer.php` (behaves like Dovecot; ported from Courrier), `FakeUapi.php`.
