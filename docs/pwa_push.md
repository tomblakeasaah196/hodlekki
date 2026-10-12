# HOD Lekki as an installable app (PWA) + Web Push

Everything the platform already writes to `system_notifications` now also
reaches the member's phone as a push notification, and the site installs to
the home screen as **HOD Lekki**.

No module had to change. There are roughly 60 `INSERT INTO system_notifications`
statements across 29 files; none of them know push exists.

---

## 1. Turning it on in production

Three things, in this order.

### 1.1 Run the migration

`db/migrations/20261012090000_pwa_push.sql` runs automatically on the next
deploy (`bin/deploy.sh` → `php db/migrate.php`). It creates
`push_subscriptions`, `push_queue`, `push_config` and `push_user_prefs`, and
touches nothing that already exists.

Until it has run, every entry point answers `FEATURE_NOT_READY` and the rest of
the site behaves exactly as before. That gap is real: the tree is copied to the
docroot *before* migrations run.

> **Do this BEFORE anyone turns alerts on.** With nothing in `.env`, the module
> generates a VAPID pair and keeps it in `push_config`, and every member who
> turns alerts on is subscribed *against that key*. Adding `.env` keys later
> makes `.env` win, and all of those subscriptions start answering 401 —
> silently. The Alerts Centre shows a red banner if it detects this, but the
> cheap fix is to decide once, up front. Either is fine; changing your mind is
> what costs.

### 1.2 Put the VAPID keys in `.env`

VAPID is how a push service (Google's FCM, Mozilla's, Apple's) knows a message
really came from us. It is a P-256 keypair: the public half is handed to the
browser when it subscribes, the private half signs a short-lived JWT on every
send.

On the production host (cPanel → Terminal). Use `ea-php83` explicitly — the
bare `php` on that box is not necessarily 8.3:

```bash
/usr/local/bin/ea-php83 \
  /home/smartqaq/public_html/hodlc.lpc.cm/bin/generate_vapid_keys.php \
  "mailto:asah.tomf@gmail.com"
```

It prints three lines. Paste them into `.env` (cPanel → File Manager, Settings
→ *Show hidden files*, then edit `.env` in the site root):

```
VAPID_PUBLIC_KEY="BJ…"
VAPID_PRIVATE_KEY="xK…"
VAPID_SUBJECT="mailto:asah.tomf@gmail.com"
```

Nothing else is needed. `SITE_URL` is optional and only worth setting if
notification links come out pointing at the wrong host.

**This step is optional.** With nothing in `.env`, `push_vapid()` generates a
keypair on first use and stores it in `push_config`, so push works the moment
the migration lands. `.env` is still better: the key survives a database
restore taken from before the feature existed, and the private half stays out
of nightly SQL dumps.

> **Rotating the keys invalidates every subscription.** A push service ties a
> subscription to the public key it was created with. After a rotation every
> member has to turn alerts on again, and nothing tells them to. Generate once.

### 1.3 Add the cron job

cPanel → Cron Jobs, every minute (this is the one genuinely required step):

```
* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/push_worker.php >/dev/null 2>&1
```

In normal operation this worker finds nothing to do — see §3. It exists for
notifications written by other cron jobs, for retries after a push service
answered 429 or 5xx, for a run that died half way, and for pruning the queue.

### 1.4 Check it

```bash
/usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/push_worker.php --status
```

```
ready: yes
queue: 0 pending, 0 sent, 0 failed
devices: 0 active, across 0 member(s)
fan-out cursor: 184213 (latest notification is 184213)
```

Then sign in on a phone, open the bell → the gear icon → **Send me a test
alert**.

---

## 2. What a member sees

**Android / Chrome / Edge.** A banner slides up: *Install HOD Lekki*. One tap
installs it. If they dismiss it, it stays away for 14 days.

**iPhone / iPad.** Safari offers no install API at all, so the app shows a
sheet with the three real steps — Share → Add to Home Screen → Add — and an
arrow bouncing at Safari's share button (bottom bar on iPhone, top bar on
iPad). It appears from their third page view, at most once a month, and is
always reachable from the bell's gear icon.

**Notifications.** From their second page view the app asks once, explaining
what it will send. On iPhone the ask is replaced by the install guide, because
iOS only delivers Web Push to an installed app — that is Apple's rule, and the
sheet says so.

**Afterwards**, the gear icon in the notification panel opens a sheet with:
alerts on/off for this device, a test alert, quiet hours, and how many devices
are registered.

---

## 3. How a notification becomes a push

```
  any api/*.php              includes/push_helpers.php            the phone
  ─────────────              ─────────────────────────            ─────────
  INSERT INTO
  system_notifications  ──▶  push_fanout()
                             reads rows > push_config.fanout_cursor_id,
                             expands user / department / role / region,
                             writes one push_queue row per recipient
                                      │
                                      ▼
                             push_drain()
                             claims pending rows, encrypts one payload per
                             device (RFC 8291), posts them all in parallel
                             with curl_multi                    ──▶  sw.js
                                                                     'push'
                                                                     event
```

Both halves run inside `push_run()`, which holds the MySQL named lock
`hod_push_worker`, so the cron worker and the inline dispatcher can never
duplicate each other.

### The inline dispatcher — why push feels instant

`includes/db.php` is required by every page and every endpoint, so the bottom
of it arms `push_register_dispatcher($pdo)`. That adds **no query** to the
request: it registers a shutdown function, and only on POST/PUT/PATCH/DELETE
or a request to `/api/` — the only things that write notifications.

After the response is finished, the shutdown function calls
`fastcgi_finish_request()` (the browser already has its answer),
`session_write_close()` (the member's next click is not queued behind this),
and then sends whatever is waiting with a hard time budget.

The result: a notification written at 10:04:03 is on the phone at 10:04:03, not
at 10:05:00, and no page got slower.

Turn it off without a deploy:

```sql
UPDATE push_config SET config_value = '0' WHERE config_key = 'inline_dispatch';
```

The cron worker then carries everything, with up to a minute of latency.

### The cursor

`push_config.fanout_cursor_id` is the last `system_notifications.id` that has
been fanned out. It is **not** seeded by the migration — the first run sets it
to the current maximum, so switching the feature on does not replay months of
old notifications at the whole congregation.

### Audience

| Column on the row | Who gets it |
| ----------------- | ----------- |
| `user_id`         | that member, and only that member |
| `department_id`   | everyone on the department's live roster (`is_active = 1`) |
| `role_id`         | everyone holding that role, excluding frozen rows |
| `region_id`       | everyone in the region who is not `Relocated` |

A row that names a `user_id` is addressed to that member alone; the group
columns on it are context. This matters: `api/regions_api.php` writes one row
per member of a region, each carrying both `user_id` and `region_id`.
Expanding both would turn a 200-member broadcast into 40,000 queue writes.

### Delivery outcomes

| Response | What happens |
| -------- | ------------ |
| 2xx | Delivered; `last_success_at` updated, failure count cleared |
| 404, 410 | Subscription is gone for good — deactivated, never retried |
| 429, 5xx, network error | Retried: 1, 5, 15, 60, 180 minutes, then failed |
| Anything else | Failed; counted against the device (8 strikes and it is dropped) |

`push_prune()` deletes finished queue rows after 14 days, and
`push_recover_stuck()` puts back any row a killed run left in `sending` for
more than 10 minutes — the attempt was already counted, so a recovered row
cannot loop.

`php cron/push_worker.php --status` reports the `in flight` count. It should
be 0 or a handful; a number that stays high means runs are being killed
mid-flight (memory limit, a deploy landing mid-request).

---

## 4. Privacy and security

**Payloads are end-to-end encrypted.** RFC 8291: an ephemeral ECDH exchange
against the subscription's own key, mixed with its auth secret, then
AES-128-GCM. Google and Mozilla relay a blob they cannot read.

**No HTML is ever cached.** Every page on this site is rendered against a PHP
session and is full of member data. Cache Storage is readable by anything else
on the origin and survives sign-out, so `sw.js` sends every navigation to the
network and falls back to a static `/offline.html` that contains nothing. Only
`/assets/**` and the CDN libraries are cached.

**`/api/`, `/auth/`, `/live/`, `/e/`, `/webhook/` and `/uploads/` are passed
straight through** by the service worker. The Special Events module polls
`/live/*.json` for real-time state and serves `/e/` under its own strict CSP; a
cache in front of either is a bug.

**A notification's `link_url` can never leave this site.**
`push_message_from_notification()` rejects anything that is not a same-site
path — absolute URLs, `//host`, backslashes, `javascript:` — and falls back to
`/index.php`. Without that, any of the 60 writers that took a URL from user
input would be an open redirect wearing the church's icon.

**Only the subscriber can touch their own devices.** Every action in
`api/push_api.php` is scoped to `$_SESSION['user_id']`. There is no action that
pushes to somebody else; a broadcast is a `system_notifications` row, which the
worker fans out.

**Quiet hours do not hold back security alerts.** `push_urgency_for()` marks
titles containing security / urgent / alert / revoked / suspended / emergency
as high urgency, and those ignore a member's quiet window.

---

## 4b. The Alerts Centre

`modules/alerts/index.php`, in the sidebar under Security & Stewardship. Super
Admins and the Resident Pastor only, gated by `security_is_admin()` against the
live roles table — never `$_SESSION['active_role']`, which the member can
switch themselves from their profile.

It shows device counts, members reached, delivered and waiting, where the
signing keys live, how far behind the fan-out is, a breakdown by platform, and
the last seven days of failures grouped by reason. The two kill switches
(`enabled`, `inline_dispatch`) are toggles, and every flip is written to
`security_audit_log`.

The **broadcast composer** writes `system_notifications` rows rather than
pushing directly. That is the whole design: the message lands in everyone's
bell whether or not they have a device, the normal fan-out delivers it, and a
member's quiet hours and opt-out are honoured without this endpoint knowing
they exist. Audiences are everyone, everyone with alerts on, a department, a
role, or a region.

It always asks twice. The button first reads **Check who this reaches** and
answers with the real number of people and how many of them have a phone
registered; only then does it become **Send it**. Any edit afterwards — a word,
the audience, the link — resets it, so nothing can be sent to an audience that
was not shown first. Member-facing guide: `modules/alerts/how_to_use.md`.

## 4c. Special Events registrations

`se_registration_notify()` in `includes/special_events/capacity.php`, called
from `se_register()` so it covers every channel — the public portal, the desk,
the Studio's manual add.

A Chara night takes 300 registrations in an evening, and 300 notifications is
not information; it is why people turn notifications off and then miss the one
that mattered. So `registration.notify_crew` defaults to **digest**: the first
registration of an event goes out immediately (the "we are live" moment), and
after that a summary at most every `SE_REG_NOTICE_MIN_GAP_MINUTES` (15). That
floor scales itself — an event where somebody signs up once a day still gets a
notice per person, because a quarter of an hour has always passed. `every` and
`off` are the other two settings, in Studio → Registration → Crew alerts.

Recipients are the event's producers plus module managers, through the existing
`se_capacity_notice_recipients()`. State lives in `se_audit_log` as
`reg_notice` rows, the same ledger idiom the capacity notices use. Test-mode
registrations never notify anyone.

---

## 5. Files

| Path | What |
| ---- | ---- |
| `includes/push_crypto.php` | VAPID JWT (ES256), ECDH, HKDF, aes128gcm. No Composer dependency — see §6. |
| `includes/push_helpers.php` | Subscriptions, preferences, queue, fan-out, delivery. |
| `includes/push_dispatch.php` | The after-response shutdown hook. |
| `includes/pwa_head.php` | `<head>` partial: manifest, Apple metadata, launch images, runtime. |
| `includes/pwa_boot.php` | The launch screen. First element inside `<body>`. |
| `api/push_api.php` | Subscribe, unsubscribe, devices, preferences, test, badge. Member-facing; every action scoped to the caller. |
| `api/push_admin_api.php` | Delivery health, kill switches, broadcast. Separate file because it can reach everyone. |
| `modules/alerts/index.php` | The Alerts Centre screen. |
| `cron/push_worker.php` | The every-minute safety net. |
| `manifest.php` | Web App Manifest. PHP so the MIME type is certain. |
| `sw.js` | Service worker. Root scope. |
| `offline.html` | Offline fallback. Static, contains no member data. |
| `assets/js/pwa.js` | Install prompts, iOS guide, push opt-in, alerts sheet, native feel. |
| `assets/css/pwa.css` | App shell styling. Everything is `hod-` namespaced. |
| `assets/pwa/` | Generated icons and launch images — **committed**. |
| `bin/build_pwa_icons.php` | Rebuilds `assets/pwa/` from `assets/images/logo_hod.png`. |
| `bin/generate_vapid_keys.php` | Prints a VAPID keypair for `.env`. |
| `db/migrations/20261012090000_pwa_push.sql` | The four tables. |
| `tests/pwa/run.php` | The suite. Run it after touching any of the above. |

### Adding the PWA to another page

Two lines:

```php
<head>
    …
    <?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body …>
<?php require_once __DIR__ . '/includes/pwa_boot.php'; ?>
```

Both are idempotent, and `includes/header.php` already does it, so every ERP
screen is covered. The public pages carry them individually: `connect.php`,
`sermons.php`, `testimonies.php`, `register.php`, `checkin.php`,
`parent_portal.php`, `live_radio.php`, `verify.php`, `idi_mobilization.php`,
`reach.php`, `assimilation.php`, `auth/login.php` and `e/index.php`.

Two variables may be set immediately before the `require`:

| Variable | When |
| -------- | ---- |
| `$pwaNonce` | The page sends a CSP without `'unsafe-inline'`. The Special Events pages do (§19.7), and without this the preloader styles and the early boot script are dropped **silently** — the page still renders, so nobody notices for weeks. |
| `$pwaQuiet` | The page is doing something time-critical: a live event console, a check-in desk. Keeps the manifest, icons, service worker and push delivery; drops the install banner, the opt-in sheet and pull-to-refresh, which would otherwise turn a swipe mid-game into a reload. The alerts sheet and install guide stay reachable from any `data-hod-alerts` / `data-hod-install` control. |

`e/index.php` sets both, on its event portal and its `/e/` hub. Its 404 page is
left alone. Any individual element can also opt a subtree out of the pull
gesture with `data-no-pull`.

### Rebuilding the artwork

```bash
php bin/build_pwa_icons.php     # then commit everything under assets/pwa/
```

The cPanel host has no build step, so these PNGs are committed and served
directly — the same reasoning as `assets/se/css/se.css`. The script draws
shapes and the logo only, never text, so it needs no font and produces
identical bytes anywhere.

---

## 6. Why no Composer package

`minishlink/web-push` is the obvious choice and was rejected deliberately:

- it needs `ext-gmp` or `ext-bcmath`, neither of which is guaranteed on this
  shared host;
- it pulls in a dozen `spomky-labs` packages;
- `vendor/` is regenerated on every deploy, so a dependency that fails to
  install there takes notifications down with no warning anywhere.

`includes/push_crypto.php` is about 300 lines on top of `ext-openssl`, which
`includes/sms_functions.php` already depends on. It is verified byte for byte
against the worked example in **RFC 8291 §5**, so what it produces is what the
RFC says a browser will decrypt.

---

## 7. Testing

```bash
php tests/pwa/run.php
```

176 checks, no database and no network. Run it after any change to
`includes/push_*.php`, `manifest.php`, `sw.js` or `includes/pwa_head.php`.

### The integration suite (needs a real database)

The unit suite covers the crypto and the string handling. The fan-out SQL, the
audience expansion, the unique key that stops a double send, the cursor and the
recovery of an interrupted run can only be answered by a database:

```bash
php tests/pwa/integration/run.php --socket=/tmp/mdb.sock --db=push_it
php tests/pwa/integration/run.php --host=127.0.0.1 --user=root --pass=root --db=push_it
```

51 checks. It builds its own stand-in tables, applies the real migration, and
attempts no network — every subscription in it is fictional, so what is proved
is that the right rows reach the queue.

**Run it on both engines.** The production one is unconfirmed, and they
disagree on real things. Both are verified for this feature:
MySQL 8.0.46 and MariaDB 10.11 — migration applied twice (idempotent) and
51/51 on each.

JavaScript (there is no bundler, so a syntax error ships as a blank surface):

```bash
node --experimental-default-type=module --check assets/js/pwa.js
node --experimental-default-type=module --check sw.js
```

These are not in `.github/workflows/deploy.yml` yet: the deploy app cannot
push `.github/workflows/`, so the owner adds the steps — the same situation as
the Assimilation Excel suite.

### Testing push by hand

```bash
php cron/push_worker.php --status      # ready? how many devices? where is the cursor?
php cron/push_worker.php               # fan out and deliver one batch
php cron/push_worker.php --prune-only  # clear out finished queue rows
```

To drive a real notification end to end:

```sql
INSERT INTO system_notifications (user_id, title, message, link_url)
VALUES (<your user id>, 'Test', 'From the database.', '/index.php');
```

The next request to any `/api/` endpoint delivers it; so does the next cron
minute.

---

## 8. Troubleshooting

| Symptom | Cause |
| ------- | ----- |
| `--status` says `FEATURE_NOT_READY` | The migration has not run. |
| `--status` says `NO_VAPID_KEYS` | `VAPID_PRIVATE_KEY` is malformed, and nothing was generated into `push_config`. |
| `--status` says `NO_OPENSSL_ECDH` | PHP is older than 7.3 or `ext-openssl` has no EC support. |
| `--status` says `DISABLED` | `push_config.enabled` is `0`. |
| Nobody receives anything, queue full of `failed` | Read `push_queue.last_error`. A wall of `HTTP 401` means the VAPID key changed after members subscribed. |
| One member gets nothing | `SELECT is_active, failure_count, last_error FROM push_subscriptions WHERE user_id = …`. |
| iPhone shows no prompt | Expected in a Safari tab. iOS only delivers Web Push to an installed app. |
| Install banner never appears | Already installed, dismissed within 14 days, or an in-app browser (Facebook, Instagram) which cannot install anything. |
| Everything works but the icon is stale | Bump `PWA_ASSET_VERSION` in `manifest.php` and `PWA_VERSION` in `includes/pwa_head.php`. |
| It worked, then everyone stopped receiving | The signing keys changed after people subscribed. The Alerts Centre shows a red banner for exactly this. Either remove the `.env` keys to go back to the generated pair, or clear the generated one and have everyone turn alerts on again. |

All server-side logging goes to `error_log` with a `[push]` prefix.

---

## 9. Master switches

```sql
-- stop all push, keep the bell
UPDATE push_config SET config_value = '0' WHERE config_key = 'enabled';

-- cron only, no after-response sending
UPDATE push_config SET config_value = '0' WHERE config_key = 'inline_dispatch';
```

A member switches themselves off from the bell's gear icon, which writes
`push_user_prefs.push_enabled`. Turning the device off also unsubscribes it at
the browser, so nothing is sent to a dead endpoint.
