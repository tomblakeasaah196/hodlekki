# The system monitor

Errors, health and real-user speed for hodlekki.org, in one place: every PHP
error, browser error and CSP violation the site meets, grouped and counted;
every background job, queue, certificate and backup checked; the site seen from
outside; members' real loading times on phones and computers. When something
breaks, the right people hear about it once, and every problem can be handed to
Claude as a complete, private brief ("Fix with AI").

**The engine** (capture, storage, checks, the outside probe, alerts, the AI
briefs, a JSON API, a command line) and **the screens**:

- **System Health** (`modules/monitor/index.php`, menu → System, Super Admins):
  Overview, Errors (each problem in a drawer with **Copy for AI** / **Download
  brief**, status, notes, the 14-day trend, pages, occurrences, the stack),
  Health, Speed (phones / computers), Uptime and Settings (alert emails, the
  morning summary hour, the live wall link, a test alert). Strict mode on the
  shared UI (docs/UI.md); behaviour in `assets/ui/js/pages/monitor.js`, which
  puts every word that came from an error into the page as text, never HTML.
- **The live wall** (`modules/monitor/wall.php`), full screen for a TV, no
  sign-in: the key is in the link's fragment (section 7).
- The member-facing guide is `modules/monitor/how_to_use.md`.

Models it learned from: JBS HQ's System Health (`ErrorMonitor`, `LogScanner`,
`HealthChecks` with the warm OPcache rate, `MonitorAlerts`, the watchdog) and
nfelia.com's plain-PHP monitor (`app/helpers/errors.php`, `app/ops/monitor.php`).

---

## 1. Setting it up (owner)

The code and the tables arrive with a normal deploy; recording starts at once.
Four steps make it complete, in this order:

1. **Cron** (cPanel → Cron Jobs), every minute:
   ```
   * * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlekki/cron/monitor.php >/dev/null 2>&1
   ```
   Check it from cPanel → Terminal: `cd ~/public_html/hodlekki && /usr/local/bin/ea-php83 cron/monitor.php` prints one line.
2. **Who gets emails.** From cPanel → Terminal (the Config screen does the same later):
   ```
   /usr/local/bin/ea-php83 bin/monitor.php recipient-add you@example.com
   ```
   The address gets a confirmation link and nothing else until it is clicked.
   Super Admins get the bell and phone notifications without any setup.
   Prove both with `bin/monitor.php test-alert`.
3. **The outside probe.** Make a secret (`php -r "echo bin2hex(random_bytes(32));"`), put it in the docroot
   `.env` as `MONITOR_PROBE_SECRET=…` and in GitHub → Settings → Secrets and variables → Actions as the secret
   `MONITOR_PROBE_SECRET`. Optionally add the server's IP as the secret `ORIGIN_IP` (enables the origin-lock and
   origin-certificate checks). Run it once from GitHub → Actions → "Outside probe" → Run workflow.
   Then add the Actions variable `MONITOR_PROBE_ENABLED` = `1` to start the hourly schedule (until then
   the scheduled run is skipped and uses no Actions minutes).
   GitHub skips many scheduled runs (on 12 Nov 2026 one of twelve fired), so also put a fine-grained GitHub
   token in `.env` as `MONITOR_PROBE_KICK_TOKEN=…`: this repository only, permission *Actions: Read and
   write*. When no report has come for 70 minutes, `cron/monitor.php` asks GitHub to run the probe now
   (at most once in 55 minutes; `monitor_probe_kick()` in `includes/monitor/probe.php`). The probe still runs
   on GitHub's machines, so it still sees the site from outside. A refused ask (an expired token) shows in
   the cron's line as "HTTP 401".
4. **Optional, recommended:** Cloudflare → Notifications → *Passive origin monitoring* (free). It emails within
   minutes when Cloudflare cannot reach the server — the one failure this server cannot report itself.
5. **Optional:** the prepend (section 3) for full detail on the dozen public pages that never load `db.php`.

`php bin/monitor.php status` shows every check and the open problems at any time.

---

## 2. What it watches

| Group | Check | Red when | Alerts at once |
|---|---|---|---|
| Errors | Errors | a fatal in the last hour, or more than 5 new problems in a day | (errors alert themselves, see 5) |
| From outside | hodlekki.org, connect.hodlekki.org | down from GitHub's machines | yes |
| | Certificates | Cloudflare's or the origin's under 5 days (amber under 14) | yes |
| | DNS and mail records | no A, MX or SPF (amber: no DKIM or DMARC) | yes |
| | Origin lock | a request around Cloudflare is answered (amber: no `EDGE_TOKEN`) | yes |
| | Outside probe | (amber) no report for 3 runs | — |
| Background jobs | SMS queue worker, push worker, email queue worker (every minute), email automation, Special Events (every 5 min), email bounces (every 10 min), Reach lost souls (07:00), Assimilation watchlists (07:15) | stopped, or a daily run missed; amber when late, stuck or failed | yes |
| Messages | SMS sending | queue not moving for 15 min, stuck "sending", wallet under ~100 texts (amber under 500) | yes |
| | Push notifications | cannot send, or jobs waiting over 15 min | yes |
| | Email sending | not configured, SMTP refuses the sign-in (tested hourly without sending), 5+ failed sends a day | yes |
| | Mail sync | an active mailbox not synced for 10 minutes (one brought in under 10 minutes ago gets that long); amber when one needs a new password or is not on the server | yes |
| Server | Database speed | unreachable, or a members query over 1 s | yes |
| | Disk space | cPanel quota 95% (amber 85%); when cPanel answers "0 used" (server quotas off) the home folder is measured with `du` (hourly, 90 s at most; when it runs out of time, the last measure from the past day stands with its age); a figure that cannot be read is amber "Unknown", never 0% | yes |
| | Google (Meet) | Google withdrew access (`invalid_grant`); amber when the client or `INTEGRATIONS_VAULT_KEY` is missing, not connected, or the last call was refused for the client or a permission (docs/INTEGRATIONS.md) | yes |
| | Backups | no database backup in `~/backups/hodlc` within 26 h (amber: half the size of the last, uploads over 8 days) | yes |
| | PHP (web) | below 8.3, a required extension missing, or `display_errors` on | yes |
| | Server speed settings | (amber only) OPcache off, full, or below 95% warm hit rate; no early response | — |
| | Log files | (amber) over 100 MB in all, or one over 50 MB | — |
| | Last deploy | the last deploy never finished; amber when main is not on the server 30 min later | yes |
| Real users | Speed on phones, on computers | p75 of LCP, INP, CLS or TTFB in the "poor" band over 7 days (amber: needs improvement) | — |

Facts that are slow to gather (SMTP sign-in, BulkSMS balance, cPanel quota, the
real-user roll-up) refresh hourly; the rest every 5 minutes. PHP and OPcache are
read **from a web request** (a page writes `web.json` about every 10 minutes),
because the cron's command-line PHP has different settings — on this host the
web PHP has no `fileinfo` and the CLI does.

---

## 3. Capture: how it catches everything

| Layer | Covers | Where |
|---|---|---|
| PHP handlers | warnings, notices, deprecations, uncaught exceptions, fatals (memory, time limit), pages slower than 3 s, a heartbeat for every `cron/*.php` | `includes/monitor/capture.php`, installed on line 2 of `includes/db.php` — every API, every ERP page (header.php loads db.php), every cron |
| Log reader | parse errors, pages that never load db.php, anything before the handlers, every `error_log()` line the code writes (`[mailer] send failed`, BulkSMS problems…) | `includes/monitor/logscan.php`: every `error_log` in the docroot and three levels below (not vendor/ or uploads/), PHP's `error_log` setting, `MONITOR_EXTRA_LOGS` |
| Prepend (optional) | full detail on the public pages without db.php | cPanel → MultiPHP INI Editor → `auto_prepend_file = "/home/smartqaq/public_html/hodlekki/includes/monitor/prepend.php"` |
| Browser | JavaScript errors, unhandled rejections, failed script/style/image loads, Core Web Vitals | `assets/js/monitor.js`, one tag printed by `includes/pwa_head.php` on every page with the app shell (ERP, public pages, `/e/`) |
| CSP reports | violations of a page's Content-Security-Policy | `report-uri /api/monitor_intake.php?k=csp&t=<token>` (`monitor_csp_report_uri()`), or another endpoint calls `monitor_intake_csp($body, $contentType)` |
| Outside probe | the site as members reach it | `.github/workflows/monitor-probe.yml` → `api/monitor_probe.php` |

**Why db.php and not only `auto_prepend_file`:** db.php deploys itself and
already runs first on everything that matters, CLI included (a prepend set in
`.user.ini` never reaches cron jobs); `.user.ini` and `php.ini` are cPanel's
files and never deployed. The prepend stays an optional extra, and the two are
idempotent together.

The intake allows 60 reports a minute per address (checked before anything is
read) and 3,000 a minute in all, counting only reports with a valid token. Each
record is stored on its own: one the database refuses is skipped with a log
line, never blocking the rest, and when the database goes away part-way only
what was not yet written is spooled, so nothing is counted twice.

**Rules the capture keeps** (it runs on every request): it never prints and
never changes what a visitor gets. An uncaught exception is recorded and then
handed straight back to PHP, which ends the request exactly as before — the 500
(CLI: exit 255), PHP's own log line, and `error_get_last()` for the shutdown
functions that check it (the push dispatcher sends nothing after a crashed
request; the SMS worker logs why its run ended). Nothing is written during
the request: problems are kept in memory (at most 25, the same one once) and
written **after the response has been sent**, on the monitor's **own database
connection**, so a page's rolled-back transaction or a fatal mid-transaction
cannot take the record with it. A warning the monitor recorded is logged as one
short `HODMON [E7KQ2M9X] …` line instead of PHP's own (the reader skips the
marker); fatals and uncaught exceptions, which PHP logs itself, are matched to
the recorded one within 10 seconds, so nothing is counted twice. The write runs
as the very last shutdown function, so it also records warnings raised by the
others (the push dispatcher, a page's own hooks); anything raised after it
(destructors, the session write) is left to PHP's own log line, which the log
reader picks up. `@`-silenced calls stay silent. When the
database is the problem ("too many connections", a refused connection), events
go to a spool file outside the docroot and the cron stores them when it
answers again; after a failed connection the flush does not try again for a
minute.

The browser script is a plain same-origin file with its settings in `data-*`
attributes: no inline code, no nonce, so it works under the strictest CSP
(`script-src 'self'; connect-src 'self'`). It is deliberately not deferred, so
it sees errors in the inline scripts below it; it is about 6 KB and cached by
its `?v=`.

**A failed load of one of the site's own files** (19 Nov 2026) is checked
against the server:
- **File present.** The visitor's connection failed: a phone switched apps or
  lost signal mid-load. On 18 Nov, three scripts of an event page failed for
  one iPhone in the same second. These are filed as a notice, "Could not load
  … (visitor's connection)", in a group of their own. The Errors list keeps
  them, but the Errors check does not count them.
- **File missing.** A deploy lost it, or the path is wrong. It stays a warning.
- Failures while the page is being left are dropped in the browser.

**The database connection** (`includes/db_connect.php`) is tried again after
1 s, then 2 s, when the error means the server is restarting: MySQL client
errors 2002, 2003, 2006 and 2013. The host restarted MariaDB at 04:48:38 on
19 Nov, and one request failed in that gap. A wrong password or database name
still fails at once.

---

## 4. Privacy

This database holds real church members. Everything the monitor keeps goes
through `includes/monitor/redact.php` before it is stored: messages, traces,
addresses, browser stacks, CSP addresses, log lines — and again the code
excerpts in a brief.

- Out: email addresses, Nigerian and international phone numbers, long tokens
  and JWTs, `password=…`/`token=…`/`api_key=…`, `Bearer …`, the session and
  "stay signed in" cookies, every `.env` value whose name says PASS / SECRET /
  KEY / TOKEN / PEPPER / VAULT, and the values a database error quotes back
  (`Duplicate entry '?'`).
- Page addresses keep their path and the **names** of their parameters only
  (`/api/events_api.php?action=…&id=…`); tokens, emails and phone numbers in a
  path become `:token` / `:value`.
- Stack traces never carry function arguments.
- People are counted, never named: a signed-in person is a keyed hash of their
  id; a visitor who is not signed in is a hash of the day and address (not the
  browser, so one sender cannot pass for several people), and cannot be followed
  from one day to the next. Events keep the **role**
  (`Super_Admin`, `Church_Member`, `guest`) — that is what "which role was
  affected" needs.
- No IP address, cookie, request body, header or full user agent is stored
  (the browser is kept as "Chrome 131 · Android"). Rate limiting hashes the
  address with the minute, in files that are deleted after three minutes.
- The live wall and the alert emails carry counts, states and redacted titles,
  nothing else.

**Text from browsers is untrusted.** Anyone can send a browser report (the
intake token is on every public page), so its text is treated as hostile:
- it never reaches a bell or an email subject or body — those say "A browser
  error on /route" (`monitor_safe_title()`), and everything written to the bell
  is made plain text whatever its source (`monitor_plain()`), since the bell has
  rendered notification text as HTML;
- in a "Fix with AI" brief it sits in a fence longer than any backticks inside
  it (`monitor_md_fence()`), under a note that it is data, not instructions;
- at most 200 new browser problems open an hour, and browser problems quiet for
  30 days are cleared, so a flood of made-up errors cannot fill the tables;
- the screens must show titles, messages and stacks as text (`textContent`,
  `htmlspecialchars`), never as HTML.

**Keys.** Intake tokens, the people hash and email stop links use
`MONITOR_SECRET` from `.env`, or else a random key the monitor makes once in its
own folder (`~/.hodlekki_monitor/secret.key`, 0600). Never one derived from the
database password: every page prints an HMAC made with this key, which would let
anyone test password guesses offline. Health → "Monitor key" says which is in use.

---

## 5. Alerts — never a storm

Two channels:

- **The bell, and so a phone notification**, for every active Super_Admin: a
  `system_notifications` row each, which the normal fan-out pushes (never a
  direct push — AGENTS.md).
- **Email**, through `hod_send_mail()`, to the addresses in `monitor_recipients`
  their owners **confirmed**. Adding an address sends a link; opening it shows a
  button, and only the button's POST confirms (a mail scanner that follows
  links cannot). Every email has a personal stop link. Owner only for now.

What is sent:

| What | When |
|---|---|
| New or returning errors | one message per cron run listing all of them: server fatal/error at once; a browser error once 3 people met it; warnings, CSP reports and slow pages only in the summary. A problem marked fixed that happens again reopens and is announced again. Lines found in the logs over an hour old, and everything the first scan of a fresh install finds, are listed, not announced. |
| Spikes | at least 20 in the last hour and 5× the usual rate (old log lines never count); at most every 6 hours per problem |
| Health | an urgent check (table in 2) turning red, again every 6 hours while red, and when it recovers |
| Morning summary | 08:00 Lagos (`digest_hour`), once a day: checks not green, new problems, the most frequent open ones, yesterday's deploys |
| Watchdog | the monitor's own cron silent for 20 minutes (or never run, 2 hours after install) — noticed by ordinary page visits, at most every 6 hours |
| Test | `bin/monitor.php test-alert` or the Config screen |

Caps: 6 alert emails and 3 bells an hour (summary, watchdog, confirmations and
tests aside). What does not fit waits for the next run, in one message. An
alert counts as sent once either channel reached someone, so a broken mailer
never repeats the same bell (the Email check shows the failed sends).

---

## 6. Fix with AI

`monitor_brief($db, $id)` (`bin/monitor.php brief <id>`, API `?action=brief&id=`)
turns a problem into a Markdown prompt to paste into Claude:

- the system in one paragraph (plain PHP 8.3, file-per-URL, cPanel/LiteSpeed,
  Cloudflare; read AGENTS.md first);
- **what happened** — class and redacted message;
- **where** — page / API / cron / browser, the request (`POST /api/x.php?action=…`),
  `file:line`, the pages it happened on;
- **when and how often** — first and last seen with their releases, totals for
  24 h and 7 days, the hourly shape, "started N minutes after the deploy of
  `61b8516` …", regressions, before/after a fix;
- **who** — number of people and the roles (never a person), browsers;
- a plain-English hint for errors this site meets (missing migration, fileinfo
  on the web PHP, an API answering HTML, …);
- recent occurrences with their reference codes, the stack trace, **the code
  around each of the site's own frames** (±6 lines, as deployed now), recent
  deploys, the team's notes;
- the task: root cause, smallest safe fix, keep AGENTS.md's rules (degrade
  before the migration, prepared statements, no personal data), a test, how to
  confirm it stopped.

`monitor_export_open()` (`bin/monitor.php export file.md`, API `?action=export`)
puts every open problem in one file, worst first, with a numbered list on top.

---

## 7. Live wall

Shared hosting has no websockets, and a held-open connection per screen would
pin one of the host's few PHP processes. So the monitor writes a small JSON
snapshot to `live/monitor/<key>.json` whenever something new is recorded (at
most every 5 seconds) and on every cron run, and LiteSpeed serves it as a
static file (`live/.htaccess`: JSON only, no-cache) — the Special Events polling
design. The wall polls it every few seconds without starting PHP, through
Cloudflare. The key is random (`monitor_state.wall_key`), known to the signed-in
wall and to a revocable TV link (key in the URL fragment); "rotate" makes a new
one and deletes the old file. Ably (`SE_REALTIME_DRIVER`) stays unused.

---

## 8. Outside probe

`.github/workflows/monitor-probe.yml`, hourly at :17 and on demand: status and
first-byte time of both sites (a "Database Connection Failed" page counts as
down), Cloudflare's certificate and — with `ORIGIN_IP` — the origin
certificate, A/MX/SPF/DKIM/DMARC through 1.1.1.1 and 8.8.8.8 (the server's own
resolver may answer from cPanel's local zone), and the origin lock (a request
to `ORIGIN_IP` with the site's name must get 403). It posts the result to
`api/monitor_probe.php` signed with `MONITOR_PROBE_SECRET`
(`X-Monitor-Signature: sha256=HMAC("<timestamp>\n<body>")`, within 10 minutes,
each signature once), along with main's commit (so "deploy behind main" can be
seen). The job fails when a site is down, which GitHub emails.

Cost: one ~20-second job an hour, billed as a minute: ~720 Actions minutes a
month on this private repository (`*/30` doubles it). A Cloudflare challenge on
GitHub's address would show as HTTP 403 "down"; allow the probe's user agent
(`hodlekki-outside-probe/1.0`) in a WAF skip rule if that happens.

---

## 9. Real-user speed

`monitor.js` measures each page view as Google's web-vitals library does: LCP
(the last candidate before the first input), INP (the slowest interaction,
leaving out one in 50), CLS (the largest session window), TTFB and FCP, with
the device class (phone, tablet, desktop) and connection type, and sends them
once when the page is hidden. Pages restored from the back/forward cache and
prerendered time are handled. Reports go to the spool (no database connection
per page view); the cron stores them in `monitor_rum` (14 days) and rolls them
up hourly into `monitor_rum_daily` (p75 and good / needs improvement / poor
per day, page, device, metric). `MONITOR_RUM_SAMPLE` thins them if traffic grows.

---

## 10. Data

`db/migrations/20261125090000_monitor_core.sql` — forward-only, idempotent, all
tables prefixed `monitor_`, nothing altered elsewhere; times are Lagos
`DATETIME`s written by PHP, so the same SQL runs on MySQL, MariaDB and SQLite.

| Table | Holds | Kept |
|---|---|---|
| `monitor_error_groups` | one row per problem (fingerprint: kind, class, normalised message, file, line), status new / investigating / fixed / ignored, counts, releases, regressions, spikes | fixed/ignored: 90 days after last seen |
| `monitor_error_events` | occurrences: reference code, request, role, hashed person, device, browser, trace (≤ 50 per problem, ≤ 20 an hour; fatal always) | 30 days |
| `monitor_error_hourly` / `_people` / `_paths` | trend lines, distinct people per day, pages | 90 days |
| `monitor_error_notes` | the team's notes and the monitor's own (regressions, spikes) | with the problem |
| `monitor_checks`, `monitor_check_history` | each check's state, and every change | history 90 days |
| `monitor_probes` | the outside probe's results | 90 days |
| `monitor_rum`, `monitor_rum_daily` | real-user speed | 14 / 400 days |
| `monitor_recipients`, `monitor_alert_log` | who gets emails; every alert sent | log 90 days |
| `monitor_state` | settings and bookkeeping (log positions, wall key, digest hour) | — |

Outside the database, in `/home/smartqaq/.hodlekki_monitor/` (never under
`public_html`; `MONITOR_DATA_DIR`): the spool, cron heartbeats (`beats/*.json`),
`web.json`, `release.json` (the deployed commit, read from `~/deploy.log`),
rate-limit counters, the cron lock.

Everything degrades before the migration: until the tables exist, the capture
spools, the API answers `FEATURE_NOT_READY`, the cron says so and exits.

---

## 11. API (for the screens)

`api/monitor_api.php` — signed-in, `monitor_user_can($pdo, 'monitor.view')`
(Super_Admin from the live roles table until the permissions module defines
`can()`; changes need `monitor.manage`). Writes are POST with the platform's
`csrf_require()` once it exists, else `X-Monitor-CSRF` (from `summary`).

GET: `summary` (the wall snapshot + recipients count + csrf), `errors`
(status, kind, q, sort, page), `error&id=` (group, stats, events, notes, hint),
`brief&id=`, `export` (.md download), `checks`, `speed` (days, device),
`uptime` (days), `deploys`, `recipients`, `settings`, `wall`.
POST: `set_status` (new / investigating / fixed / ignored, note), `add_note`,
`scan_now`, `run_checks`, `recipient_add`, `recipient_remove`,
`settings_save` (digest hour), `wall_rotate`, `test_alert`.
Recipient, settings and wall changes go to the Security Centre's audit log.

---

## 12. Command line

`php bin/monitor.php status | errors [all|fixed|ignored] | brief <id> | export [file] | fixed <id> [note] | checks | scan | recipients | recipient-add <email> | recipient-remove <id> | test-alert | digest | wall`
(from the docroot, `/usr/local/bin/ea-php83 bin/monitor.php …`).
`php cron/monitor.php --status | --checks | --digest`.

---

## 13. Switches and troubleshooting

- `MONITOR_ENABLED=0` in `.env`: nothing is recorded or sent; the browser tag is left out. No deploy needed.
- `MONITOR_BROWSER=0`: only the browser script is left out.
- The monitor's own problems: `HODMON …` lines in the error logs; its cron's
  output with `php cron/monitor.php`.
- A check says "unknown": read its detail — usually it is waiting for its first
  facts (a page visit for PHP, the probe's first report, a daily job's first run).

---

## 14. Tests

`php tests/monitor/run.php` — about 420 checks, no MySQL, no network, no email:
the migration's shape; redaction; grouping, regressions, spikes, caps and the
spool; **the capture in real child PHP processes** (warnings, `@`, deprecations,
an uncaught exception and exit code 255, out of memory, cron heartbeats, the log
reader not counting anything twice); log parsing and incremental reading; every
health check's verdict; the browser intake, CSP formats and rate limit; the
probe's signatures and replays; alerts (confirmation, one message per run, the
hourly cap under an error storm, health transitions, the summary, stop links);
the AI brief (sections, code excerpts, no personal data); the wall snapshot;
and that every function the monitor calls exists. CI runs it in the lint job on
SQLite, and again in the integration job on **MySQL 8.0 and MariaDB 10.11**
(`MONITOR_TEST_MYSQL_DSN`), where `_monitor_` also makes the migration fatal.
`node --check assets/js/monitor.js` runs in the lint job.

---

## 15. Files

`includes/monitor/` — `capture.php` (handlers; db.php line 2), `prepend.php`,
`redact.php`, `keys.php` (secret, hashes, intake tokens, probe signature),
`store.php` (record, spool, flush, web facts), `logscan.php`, `checks.php`,
`rum.php`, `intake.php`, `probe.php`, `brief.php`, `alerts.php`, `wall.php`,
`browser.php` (the tag), `bootstrap.php` (everything + `monitor_user_can()`).
`api/monitor_api.php`, `api/monitor_intake.php`, `api/monitor_probe.php`,
`cron/monitor.php`, `bin/monitor.php`, `monitor_confirm.php` (on the standalone
layout, `includes/ui/standalone.php`), `assets/js/monitor.js`,
`.github/workflows/monitor-probe.yml`, `db/migrations/20261125090000_monitor_core.sql`,
`tests/monitor/`. Screens: `modules/monitor/index.php`, `modules/monitor/wall.php`,
`modules/monitor/how_to_use.md`, `assets/ui/js/pages/monitor.js`, `assets/js/monitor_wall.js`,
`assets/css/monitor_wall.css`. Touched: `includes/db.php` (install + the connection-failure
note), `includes/pwa_head.php` (the tag), `.github/workflows/deploy.yml`,
`tests/special_events/db_setup.php` (the `_monitor_` marker).
