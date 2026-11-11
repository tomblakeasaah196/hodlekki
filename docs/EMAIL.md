# Email: personal addresses, consent, the sending queue and automations

Email is replacing most SMS at Household of David Lekki (it costs nothing per
message). This guide covers the backend that everything email-shaped stands on:

- a **personal email** for every member and first timer, confirmed by a link;
- **NDPA consent** records for church news, separate from account mail;
- **one queue and one hourly budget** for every email the domain sends;
- emails written as **block documents** and rendered to email-safe HTML;
- **one-click unsubscribe**, bounce and complaint handling;
- **one automation engine** for Embrace (first-timer journey), Assimilation and
  Charis (absence ladders), with email first, SMS through SMS Studio second.

Design and the plan for the screens: the campaigns design note shared with the
lead (screens, the sign-in prompt and sign-in by personal email are later PRs).

| Path | What |
| ---- | ---- |
| `includes/campaigns/bootstrap.php` | The only entry point, after `includes/db.php`. Map of the module at the top. |
| `db/migrations/20261201090000_campaigns_core.sql` | Every table (`email_*`, `automation_*`). |
| `cron/email_worker.php` | Every minute: sends the queue under the budget. `--status`, `--dry-run`, `--prune`. |
| `cron/automation_tick.php` | Every 5 minutes: runs due automations. `--status`, `--dry-run`, `--rule=ID`, `--at="…"`. |
| `includes/mailer.php` | `hod_send_mail()`: SMTP from `.env` (system@), now also with custom headers, a given Message-ID and one shared connection. |
| `tests/campaigns/run.php` | 250+ checks on SQLite built from the real migration (CI lint job). |
| `tests/campaigns/integration.php` | The parts SQLite cannot vouch for, on MySQL 8 and MariaDB 10.11 (CI integration job). |

---

## 1. Turning it on

1. **The migration** runs on the next deploy. Until it has, every function
   answers `FEATURE_NOT_READY` or does nothing; nothing throws into a page.
2. **The crons** (cPanel → Cron Jobs; lines also in `README.md`):
   ```
   * * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/email_worker.php >> /home/smartqaq/logs/hod-email.log 2>&1
   */5 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/automation_tick.php >> /home/smartqaq/logs/hod-email.log 2>&1
   30 3 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/email_worker.php --prune >> /home/smartqaq/logs/hod-email.log 2>&1
   ```
3. **The hourly cap.** Set `email_settings.hourly_cap` to the number cPanel
   shows for hodlekki.org (default 100 until someone confirms it). If the host
   ever refuses for its cap, the worker reads the real number from Exim's own
   sentence and plans below it from then on (`learned_cap`).
4. **Automations** are created **switched off** the first time the tick runs.
   Nothing is sent to anyone until someone switches a rule on (Automation tabs,
   PR 4). `php cron/automation_tick.php --dry-run --rule=ID --at="2026-11-16 10:05"`
   lists exactly who would get what, by which channel, and why.

Mail settings stay in `.env` (`MAIL_*`, see `includes/mailer.php`); nothing
here builds SMTP settings in code.

---

## 2. Rules that are not negotiable

**Personal email**
- It lives in `email_addresses`, **never `users.email`**. `users.email` is the
  members' sign-in name (`grace@hodlc.com`) and the address first timers typed
  on Connect, and Embrace, Congregation and Profile all overwrite it. On
  confirmation the address is copied into `users.email` only when that column
  is empty or already a personal address — a sign-in name is never replaced
  here (that is the sign-in PR's job). Nothing reads that copy back.
- **Never `@hodlekki.org`** (or a subdomain) and never `@hodlc.com`:
  `email_address_problem()` is the only validator; every screen calls it.
  Reset links must reach someone who is locked out of the ERP.
- One confirmed address per person and one person per confirmed address are
  the UNIQUE keys on `confirmed_user_id` / `confirmed_email`, set only while
  a row is confirmed. Never check-then-insert around them.
- Only the sha256 of a link token is stored. The confirm page's button POSTs;
  the GET only shows the address (mail scanners open links).

**Consent (NDPA)**
- Account mail (confirm links, password resets, security notices) needs none
  and carries no unsubscribe link. Church news (automations, campaigns) needs
  `news`/`email` consent = granted, logged by `email_consent_record()` with the
  exact wording shown (`EMAIL_CONSENT_NEWS_TEXT`, stored once by hash).
- The log is append-only; the latest row wins. "Withdrawn" is not "never asked":
  an unsubscribed person is **skipped, not texted instead**.
- Erasing a person: `email_forget_user()` (addresses, consent rows, outbox
  bodies; queued mail cancelled).

**Sending**
- Every email the system sends goes through `email_enqueue()` /
  `email_send_doc()` / `email_send_now()` — never `hod_send_mail()` directly
  from a feature. The worker re-checks suppression and consent at the last
  moment, sets our Message-ID (recorded at queue time), `X-HOD-Ref`,
  `Auto-Submitted`, and for church news the RFC 8058 one-click unsubscribe
  headers (https only).
- **One budget for the domain.** Anything that sends as @hodlekki.org — the ERP
  mailboxes included — takes a slot with `email_budget_take($pdo, $lane, $n)`
  first. Bulk lanes (automation, campaign) may only use `bulk_share` of the
  usable budget, so resets, alerts and people's replies always get through.
- A send that died mid-way becomes `unknown` and is **never sent again**.
- A host cap refusal is never the recipient's fault: the email goes back in
  the queue untouched and nothing more is sent that hour.
- Church news is released only inside the send window (07:00–20:00 Lagos).

**Emails are block documents, never HTML**
- `email_doc_validate()` is the sanitiser: known block types and fields only,
  rich text as a JSON tree (paragraphs and lists of bold/italic/link runs),
  links `https:`/`mailto:`/`tel:` only, pictures only from this site's
  `/uploads/email/` (or the church's own assets), merge fields only from
  `EMAIL_MERGE_FIELDS`, exactly one footer and it is last.
- Text is escaped after merge fields are filled. Tracked links are rewritten
  to `/email/c.php?r=REF&l=N`; the redirect only ever serves the targets
  stored with that email, so it can never be an open redirect.
- **Scripture blocks store a reference only.** The words come from the Bible
  module's KJV (`includes/bible/kjv/`) at render time; no scripture is typed
  into code or defaults.

**Automations**
- One engine (`automation.php`); modules only say who is due (`journey.php`,
  `absence.php`).
- An occasion runs once (`automation_runs` unique), a step reaches a person
  once per week/streak (`automation_sends` unique, claimed **before** anything
  is queued), and a person gets **one member-facing automation message a day**
  across all modules (`automation_person_day`). A date-bound reminder that
  loses the day is skipped; anything else is deferred and retried the next day
  while the person is still due.
- **Absence streaks come only from `charis_compute_awol_list()`**, called with
  Charis's own `service_rules`. Never count absences here; the AWOL contract in
  AGENTS.md applies unchanged (Sunday counts, midweek clears, skipped services,
  registration anchor, `awol_exempt`, departed statuses).
- The first-timer audience is `embrace_pipeline_sql()`; "was in church" is
  `assim_attendance_union_sql()`.
- Email when `email_may_send_news()`; else SMS through **SMS Studio's queue**
  (`sms_campaigns` audience `automation` + `sms_queue`; never `sms_log`); else
  skip. Staff steps are `system_notifications` rows (which are already pushes).
- Switching a rule on with **Start fresh** baselines everyone already past a
  step, so day one is not a flood of pastor alerts.

---

## 3. For other modules

```php
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';

member_confirmed_email($pdo, $userId);              // ?string
member_by_confirmed_email($pdo, 'ada@gmail.com');   // ?int
campaign_mail_lookup($pdo, email_message_id_hash($inReplyTo));   // which automation a reply answers
email_budget_take($pdo, 'mailbox', $recipients);    // before every SMTP send from an ERP mailbox
email_budget_status($pdo);                          // "your message leaves in N minutes"

// An alert (error monitor), sent straight after the response, counted in the budget:
email_send_now($pdo, ['lane' => 'alert', 'purpose' => 'account', 'flow' => 'monitor.alert',
    'to_email' => $address, 'subject' => $subject, 'html' => $html, 'text' => $text]);

// Bounces read from system@'s inbox (the mailbox's IMAP classes, later):
email_record_bounce($pdo, email_dsn_parse($rawMessage));
```

`email_message_id_hash()` is sha256 of the lower-case Message-ID without angle
brackets.

---

## 4. Operations

- `php cron/email_worker.php --status` — this hour's budget, the queue by lane
  and status, whether sending is paused, whether `.env` has mail settings.
- **Kill switch**: `email_settings.paused = 1` stops the worker (queued mail
  waits). Automations are switched off per rule.
- `php cron/automation_tick.php --status` — each rule, on or off, and its last
  runs with counts.
- Bodies are pruned after 90 days (`--prune`); the rows stay, because their
  `ref` is the unsubscribe token and their Message-ID answers the mailbox.
- Suppressed addresses: `email_suppressions` (hard bounce, complaint, three
  soft bounces → 7 days). Release with `email_suppression_release()`.

---

## 5. Tests

```bash
php tests/campaigns/run.php
php tests/special_events/db_setup.php --host=127.0.0.1 --db=campaigns_it --fresh
SE_TEST_DB_HOST=127.0.0.1 SE_TEST_DB_NAME=campaigns_it php tests/campaigns/integration.php
```

The first needs only pdo_sqlite. The migration carries the `_campaigns_`
marker, so `tests/special_events/db_setup.php` treats a failure as fatal on
both engines.
