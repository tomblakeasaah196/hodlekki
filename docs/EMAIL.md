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
| `db/migrations/20261127090000_campaigns_core.sql` | Every table (`email_*`, `automation_*`). |
| `cron/email_worker.php` | Every minute: sends the queue under the budget. `--status`, `--dry-run`, `--prune`. |
| `cron/automation_tick.php` | Every 5 minutes: runs due automations. `--status`, `--dry-run`, `--rule=ID`, `--at="…"`. |
| `cron/email_bounces.php` | Every 10 minutes: the bounce reader (`includes/campaigns/bounces.php`). `--dry-run`, `--limit=N`. |
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
   */10 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/email_bounces.php >> /home/smartqaq/logs/hod-email.log 2>&1
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
- It lives in `email_addresses`. On confirmation `email_sync_users_email()`
  copies it to `users.real_email`, and into `users.email` when that is EMPTY or
  a church-issued `@hodlc.com` sign-in name (owner's decision, 13 Nov 2026:
  those names die out). The old name is kept in `auth_retired_signin_names`, so
  typing it on the sign-in page says where the person signs in now. A personal
  address already in `users.email` (one a first timer typed on Connect) stays.
  Sign-in never depends on the copy: `email_signin_lookup()` tries the
  confirmed address first.
- **No new `@hodlc.com` names.** Congregation's add-member no longer makes one
  and the Security Centre's "Generate emails" is retired; new members sign in
  with their phone until they confirm their own email.
  `db/backfill_signin_names.php` (every deploy, after the migrations) swaps
  everyone who confirmed before this changed; once done it finds nothing.
- **Never `@hodlekki.org`** (or a subdomain) and never `@hodlc.com`:
  `email_address_problem()` is the only validator; every screen calls it.
  Reset links must reach someone who is locked out of the ERP.
- One confirmed address per person and one person per confirmed address are
  the UNIQUE keys on `confirmed_user_id` / `confirmed_email`, set only while
  a row is confirmed. Never check-then-insert around them.
- Only the sha256 of a link token is stored. The confirm page's button POSTs;
  the GET only shows the address (mail scanners open links).

- **One exception to "confirmed only"** (owner's decision, 20 Nov 2026: after a
  migration many members' real Gmail/Yahoo/Outlook addresses sit in
  `users.email`). Departments > Messages (flows in `EMAIL_PROFILE_ADDRESS_FLOWS`,
  `dept-msg-`) writes to `email_news_address()`: the confirmed address, else
  `users.real_email`, else `users.email`, never `@hodlc.com` or `@hodlekki.org`,
  never a suppressed address or one another person confirmed; consent exactly
  as `email_may_send_news()`. The worker re-checks that same lookup at send
  time for those flows only. Campaigns and automations are unchanged.

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
- A host refusal ("exceeded the max emails per hour", or "max defers and
  failures per hour") is never the recipient's fault: the email goes back in
  the queue untouched and nothing more is sent that hour. Only the server's own
  SMTP answer may teach a cap (10 or more, forgotten after 7 days); a bounce
  report never does. Our mail server being unreachable stops the run without
  spending anyone's attempts.
- **Bounce and complaint reports are believed only when they name an email we
  sent** (our `X-HOD-Ref` or its Message-ID), and act only on the address we
  sent it to — anyone can write to system@.
- Church news is released only inside the send window (07:00–20:00 Lagos), and
  is held entirely until the public `/email/` pages exist. A date-bound
  reminder carries `send_by` and is dropped rather than arriving after its day.

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
  loses the day is skipped; anything else is deferred and retried on a later
  day — inside the send window and not before its original time of day — while
  the person is still due. A failed occasion is picked up again (at most every
  30 minutes, within its 24 hours); the ledger keeps that from double-sending.
- An absence streak's identity is the person's last day present at a service
  that clears it (any time in their history), never the first missed date the
  engine reports, which slides forward for people away longer than its window.
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

## 2b. Sign-in and reset (`includes/campaigns/identity.php`)

| Page | What |
| ---- | ---- |
| `auth/add_email.php` | The required prompt. `includes/header.php` sends a signed-in person here while `email_prompt_state()` says `required`; it never includes header.php (it would loop), like `change_password.php`. |
| `email/confirm.php` | The link in the confirmation email. GET shows the address and one button; the POST confirms (mail scanners open links, they do not press buttons). `email/.htaccess` serves only the listed pages. |
| `auth/forgot_password.php` | "Forgot your password?" (linked from the login page). Always the same answer. |
| `email/unsubscribe.php` | Stop church emails. The RFC 8058 one-click POST (`List-Unsubscribe=One-Click`, from the mail app, no cookies or token; exempt in `includes/csrf.php`) unsubscribes at once; the page (GET) has one button and an Undo, both CSRF-checked. Only church news stops. |
| `email/c.php` | A tracked link: records the click and redirects only to a target stored with that email (never an open redirect); anything unknown goes home. |
| `email/o.php` | The open pixel (campaigns that ask for it only): counted once, always a 1×1 GIF. |
| `auth/reset_password.php` | The reset link's page: new password, the one policy, every session signed out. |
| `modules/profile/index.php` | The **Your email** card (`includes/campaigns/profile_card.php`, engine in `profile.php`, actions `email_*` in `api/profile_api.php`): the confirmed address, a waiting one (Resend, or drop it), Change email (asks for the current password, sharing the Password card's lockout: `login_attempts`, context `password_change`; the new address waits beside the old and mail keeps going to the confirmed one until its link is tapped; once it is, `email_capture_confirm()` emails the OLD address in the alert lane, "Your HOD Lekki email was changed to t•••@gmail.com on …", and writes `email_changed` to the security log, whichever screen started the change), and the **Church news** switch. The switch writes the consent log (method `profile`; switching on records the wording beside it). Profile no longer saves `users.real_email` as a free field. |

All four are **standalone strict pages** (`includes/ui/standalone.php`): the
foundation's tokens, components, `ui.js` and strict CSP, one centred card,
plain POST forms with the CSRF field (no page script).

- **The prompt** (`email_prompt_state()`, cached 5 minutes in the session):
  no confirmed address, no link sent in the last 7 days, and no "I don't have
  an email" in the last 90 days → **required**; a link pending under 7 days →
  in, with a banner on every page (resend in one tap, or change it);
  confirmed → nothing. `email_settings.signin_prompt = 0` switches the prompt
  off without a deploy.
- **Sign-in** (`signin_candidates()` and `signin_check_password()`, used by
  `api/auth_api.php`): what was typed is a personal email, a church sign-in
  name or a phone number. An email: a confirmed personal email first, then
  `users.email`, then a retired `@hodlc.com` name. A church sign-in name of
  someone who has confirmed a personal email gets, only after the right
  password, "You now sign in with your personal email (g•••@gmail.com) or your
  phone". A phone number (`signin_is_phone()`; 0803…, +234 803… and 803… are
  one number, `signin_phone_key()`): every account whose record has it, at
  most five. Several people can share one number (spouses, a parent and
  child): the password decides whose account opens; a wrong one counts towards
  each account's lockout; locked accounts are not tried; one number AND one
  password on two accounts is refused ("Sign in with your email"). The one
  answer for anything that does not match is "Wrong email, phone or password."
- **Forgot password** accepts a phone number too: each active account on it
  with a confirmed email gets its own link, at its own address.
- **Reset**: the link goes only to the confirmed personal email of an active
  account (`email_reset_request()`), throttled 10 an hour per requester and 3
  per identifier (both stored as hashes), token sha256-stored, 30 minutes,
  once; a newer link or any password change makes older links useless.
  `email_reset_complete()` uses `security_password_problems()` and the AGENTS
  credential-change rule (password_changed_at, lockout cleared,
  `security_revoke_sessions()`), then emails a "password changed" notice.
  `setup_password.php` stays first-time only.
- Schema: `db/migrations/20261129090000_campaigns_identity.sql`
  (`auth_reset_tokens`, `auth_reset_requests`, `email_prompt_skips`) and
  `20261214090000_campaigns_signin_names.sql` (`auth_retired_signin_names`).

**Capture from forms** (`email_capture_after_save()`): the Connect card and
Embrace add/edit start confirmation for a new or changed address (saving again
never re-sends). Their "Stay in touch with us" box **starts ticked** (owner's
decision, 11 Nov 2026, on record after the NDPA point was raised) and is
recorded honestly as method `prechecked` with the exact wording shown
(`EMAIL_CONSENT_VISITOR_TEXT`): Connect with the visitor's IP and user agent,
Embrace with the worker's id — never as a typed or spoken yes. A pre-ticked box
never undoes an unsubscribe.

**Members get no box** (owner's decision): for Member, Worker and Pastor,
church messages come with membership, with one-tap unsubscribe in every email.
`email_may_send_news()` allows them with a confirmed address unless they
withdrew or unsubscribed; visitors still need the box. The sign-in prompt and
Profile show no news box; Profile's switch is how anyone turns church news
off or back on (a visitor turning it on is a "yes").

**Referrer-Policy:** never `no-referrer` on a folder whose pages post their own
CSRF-checked forms: browsers then send `Origin: null` and `csrf_same_origin()`
refuses the post (#120). `email/.htaccess` uses `same-origin`; the tests check
every folder.

**The Connect card cannot be used to mail strangers.** Confirmation links are
limited to 3 a day per address (whoever the "person" is), and links started
from Connect to 5 an hour per requester (hashed IP) and 20 an hour in all
(`email_link_throttle`, `db/migrations/20261130090000_campaigns_throttle.sql`;
it fails closed). Over a limit the card is still saved and nothing is queued.
The card also carries (`includes/campaigns/formguard.php`):
- a honeypot field (filled → an ordinary-looking success, nothing saved);
- a signed render time **bound to the visitor's session and spent once a card
  is saved**: posting within 4 seconds or replaying a page that already saved
  a card → silent success, nothing saved; a token from another session (a
  script dropping cookies, an invented time) → "Please send the card again";
  **every error reply carries a fresh token** (`form_ts`), which the page puts
  back in its form, so a visitor who mistyped never reloads. Key
  `CONNECT_FORM_KEY`, or derived one-way from `.env`;
- **Cloudflare Turnstile** when `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY`
  are in `.env`: verified server-side after every field is valid (its token is
  single-use) and before anything is saved; the widget resets on every error.
  Without the keys the card works as before; if Cloudflare cannot be reached the
  card is saved and the miss is logged. When Connect is rebuilt on the strict
  CSP, add `https://challenges.cloudflare.com` to its `script-src` and
  `frame-src`.

## 2c. The Automation tab (`includes/campaigns/automation_tab.php`)

One panel, server-rendered on the foundation components, shown as a tab in
**Embrace** (the first-timer journey), **Assimilation** and **Charis** (the
absence ladders) to people with the module's `manage` right. Actions:
`api/automation_api.php` (`api_require_can(<module>, 'manage')` + CSRF) through
`automation_tab_action()`; script: `assets/ui/js/pages/automation.js`.

| What | How |
| ---- | ---- |
| Switch on / off | Rules start OFF. "Switch on" first shows `automation_preview()` in words ("23 people are already past a step (14 by email, 7 by SMS, 2 unreachable)"); absence rules then choose **Start fresh** (baseline) or **Include them**. |
| Settings | Who (journey: stop status and weeks; absence: statuses, weekly check day/time), sender name and reply-to, each step's day/time or missed Sundays, each SMS (character and text count), each team/pastor alert. `automation_tab_apply()` changes only those; `automation_rule_save()` validates and refuses a stale version. |
| Edit email | Each email step's **Edit** opens the block editor (below) in the panel's dialog with that step's (or week's) document (`email_doc`); **Save email** validates it on the server and writes it into the rule with the version check (`save_email`, `automation_tab_with_email()`). |
| Preview | `api/email_preview.php?module=&step=&week=`: the real render for the viewer (example streak/week), untracked, in a `sandbox=""` frame (never `srcdoc`), with its own CSP (`default-src 'none'; style-src 'unsafe-inline'; img-src data: <site>`). Embrace's strict CSP adds `frame-src 'self'` for it. |
| Send test | The step's email to the tester's own confirmed address: lane `test`, account mail (always arrives, no unsubscribe link), subject "Test: …". |
| Recent runs | The last runs with their counts; **Who** lists each person, the step and the result from `automation_sends`. |
| Overlap | A warning when Assimilation and Charis cover the same status. |

## 2d. The block editor (`assets/ui/js/email-editor.js`, `includes/campaigns/editor.php`)

`window.HODEmail.mount(root, {doc, onSave, storageKey, savedAt})`: framework-free, strict CSP (no inline script or
style). Used by the Automation tab and Campaigns.

- **Blocks** in a column; text blocks are edited in place (`contenteditable`) with Bold, Italic, Link (Ctrl+K),
  bulleted and numbered lists and merge-field chips, and turned back into the JSON tree on every change
  (`toContent`); every other block is edited in the side panel. Pasting inserts plain text only; dropping is
  refused. Move: the arrows, the drag handle, or Alt+↑/↓ (announced). The footer is added for you.
- **Saving** asks the server (`api/email_editor_api.php` `check` = `email_doc_validate()`) first and lists the
  reasons it refuses; the caller's `onSave` gets the cleaned document.
- **Preview**: `draft` keeps a validated copy in the session (five at most) and `api/email_draft_preview.php?d=`
  renders it for the viewer in a `sandbox=""` frame, with its own CSP. Never `srcdoc`.
- **Pictures** (`upload`): 5 MB at most, JPG/PNG/GIF/WebP by content, re-encoded with GD (pixels only, at most
  1200 px wide; PNG stays PNG, the rest become JPEG for every mail app), named by us under
  `uploads/email/YYYY/MM/`, whose `.htaccess` serves pictures only and runs nothing.
- **Templates** (`email_templates`): save, list, use (replaces after a confirm), remove (archived).
- **Autosave** in the browser (`localStorage`, per email): a newer unsaved copy is offered back (Restore / Discard).
- Access: anyone with Embrace, Assimilation or Charis `manage`, or `campaigns` Create (`email_editor_allowed()`),
  plus CSRF.

## 2e. Campaigns (`modules/campaigns/`, `includes/campaigns/campaigns.php`)

One email to many people. Screen: `modules/campaigns/index.php` (the list, **Reach** = `email_coverage()`, and
**Settings** for `campaigns` Manage) and `?id=N` (one campaign); script `assets/ui/js/pages/campaigns.js`; every
action `api/campaigns_api.php` → `campaign_action()` (`includes/campaigns/campaign_actions.php`), where all access
decisions live.

| Step | What happens |
| ---- | ---- |
| Start | A name and **From**: the whole church or a department. A draft (`email_campaigns`). |
| Who gets it | Everyone, by status, departments (with their sub-units, live roster only), regional hubs, or first timers (`embrace_pipeline_sql()`). People who left (Relocated, Attends Another Church) are never in it. The card counts it live: "212 people · 168 by email · 31 by text · 13 not reached", with the reasons and how long the email takes at the hour's budget. |
| Email | The block editor (§2d); **Save email** validates it on the server. **Preview** is the real render (`api/email_draft_preview.php`). |
| Text the rest | Optional: one SMS for people email cannot reach, handed to SMS Studio as one `sms_campaigns` row (`audience = 'email_campaign'`) and one `sms_queue` row each (never `sms_log`). |
| Send test | To your own confirmed address: lane `test`, account mail, subject "Test: …". |
| Send | Now (status `queued`) or at a time 5 minutes to 60 days ahead (`scheduled`). A scheduled campaign can still change; **Back to draft** unschedules it. |
| Going out | `cron/email_worker.php` runs `campaigns_tick()` first each minute: it claims a due campaign (`queued`, or `scheduled` and due) with one conditional UPDATE and expands it in ONE transaction: one `email_campaign_people` row per person (UNIQUE campaign + person), one outbox row per email (lane `campaign`, church news: List-Unsubscribe, consent and suppression checked again just before it leaves, the send window), the texts. Five campaigns a minute at most. An expansion that throws rolls back and marks the campaign `failed` (nothing sent; **Back to draft**). When no email is waiting it becomes `sent`. |
| Stop | Cancels the emails and texts that have not left (`cancelled`). What left stays sent. |
| Report | Sent, waiting, opened (only when **Count opens** was ticked; approximate), clicked (people, per link), bounced, unsubscribed, texts by status, and every person's line. |

**Who gets what** is `campaign_reach()`: the automations' order (`automation_choose_channel()`) for a whole
audience in a few queries — unsubscribed from church news by email → nobody (never texted instead); email when
`email_may_send_news()` allows it; else a text when the campaign has one and the number is usable and not
blocked; else nobody, with the reason. The tests compare it person by person with the per-person functions.

**Access** (`campaigns` in Roles & permissions; migration `20261207090100_perm_campaigns.sql`): a campaign's target
is its department, else `'church'`. See: View on the target, or you wrote it. Change, send, stop: Edit on it, or
you wrote it and may still Create for it. Delete (drafts that never went out): Delete, or yours. Church campaigns
need Create church-wide; everyone else sends department campaigns, and those go to that department's people only.
Day one: Super Admin (everything + Manage, as a plain grant), Resident Pastor (everything church-wide), Assoc.
Pastor role (create, edit, send church-wide), Pastor in charge / Director / HOD seats (everything for their own
department). Manage = the email settings: emails per hour, sending hours, the postal address in every footer,
pause all email.

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
- **Bounces** (`cron/email_bounces.php`, every 10 minutes): logs in to system@
  (`MAIL_USERNAME` / `MAIL_PASSWORD`, the sending account) on the mailbox
  module's IMAP client and reads each **unread** message once.
  `email_dsn_parse()` + `email_record_bounce()`: a report counts only when it
  names an email we sent (our `X-HOD-Ref`, else its Message-ID), and acts only
  on the address we sent it to. Hard bounce or complaint → suppressed (a
  complaint also withdraws church-news consent); soft → counted; the host's
  hourly cap → our email re-queued for the next hour. Reports (matched or not)
  are moved to **INBOX.Bounces**; anything else is marked read and left for a
  person; a message that fails is flagged (`\Flagged`) and skipped from then on.
  At most 300 a run. `--dry-run` reads and changes nothing. The last run is in
  `email_settings.bounces_last_at` / `bounces_last`.

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
