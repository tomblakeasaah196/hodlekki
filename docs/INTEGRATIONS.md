# Integrations: Google for Trainings' live classes

Trainings, PR 3 (`TRAININGS-PLAN.md`, owner's answers 5 and 6). The ERP connects to **one Google account**: the church's Google account (Cloud project "HOD Lekki Academy"). The connection gives:

- live classes a **Google Meet link**: a Calendar event with a Meet conference, in Africa/Lagos time;
- **who attended**, after the class, from Meet's participant list.

Transcripts, Drive and Docs are never asked for.

Switching accounts needs no code change: **Integrations → Google → Disconnect**, then **Connect** while signed in to the other account.

## Files

| Path | What |
|---|---|
| `modules/integrations/index.php`, `assets/ui/js/pages/integrations.js` | The Integrations page: status, Connect, Test, Disconnect. Only `can('integrations', 'manage')`. |
| `integrations/google/connect.php` | Connect posts here (with the CSRF token). It puts a random **state** and a **PKCE** verifier in the session, then sends the admin to Google. |
| `integrations/google/callback.php` | `https://hodlekki.org/integrations/google/callback`, the redirect URI registered on the OAuth client. It refuses a state this session did not make or one older than 15 minutes, then exchanges the code with the verifier. |
| `api/integrations_api.php` | The Test and Disconnect buttons (POST JSON, CSRF, manage permission). |
| `includes/integrations/Google/Google.php` | The client: OAuth, the token cache, Calendar (`events.insert` / `patch` / `delete`) and Meet REST (`conferenceRecords`, `participants`, `participantSessions`). Fakeable: `Google::fake()`. |
| `includes/integrations/{Settings,Vault}.php` | `integration_settings` (one row per setting), and secrets encrypted with `INTEGRATIONS_VAULT_KEY`. |
| `includes/training_live/Matcher.php` | Matches Meet participants to enrolled learners (below). |
| `includes/training_live/Live.php`, `functions.php` | Live classes: the Meet of a live item, Join class, attendance, the tutor's corrections; the hooks PR 1 calls. |
| `modules/academy/live.php`, `assets/ui/js/pages/training-live.js` | The Academy's page of one live class (the builder's **Meet** / **Attendance** button), and the logging of the learner's **Join class**. |
| `api/training_live_api.php` | `join` (the learner) and the Academy's actions (`can('trainings', 'edit', 'church')`). |
| `db/migrations/20261210090000_integrations_google.sql` | `integration_settings`. |
| `db/migrations/20261210100000_trainings_live.sql` | `training_meetings`, `training_join_log`, `training_attendance`, `training_meet_participants`, all keyed on `training_items.id`. |
| `tests/integrations/run.php` | Vault, settings, the client against a fake Google, the matcher, static rules. |
| `tests/training_live/run.php` | Live classes on SQLite from the real migrations, with Google faked, end to end. |

## Setup (once)

1. **Google Cloud** project (the lead does this with the owner): enable the **Google Calendar API** and the **Google Meet REST API**.
2. **OAuth consent screen**: External, the four scopes below, and **published to production**. In Testing mode, refresh tokens die after 7 days.
3. **OAuth client**: a Web application, with redirect URI `https://hodlekki.org/integrations/google/callback`.
4. **`.env`**, filled in by the owner, never typed by anyone else:
   - `GOOGLE_OAUTH_CLIENT_ID`
   - `GOOGLE_OAUTH_CLIENT_SECRET`
   - `INTEGRATIONS_VAULT_KEY`: 64 hex characters, made on the server with `php -r "echo bin2hex(random_bytes(32));"`.
5. **Integrations → Google → Connect**, signed in as the account to use. Allow every box.

**Scopes:** `openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/meetings.space.readonly`.

## What is kept, and where

`integration_settings` holds these rows:

| Row | What it holds |
|---|---|
| `google.refresh_token` | The refresh token, **encrypted**. |
| `google.access_token` | The cached access token and when it expires, **encrypted**. It is refreshed 2 minutes early. |
| `google.account_email`, `google.scopes` | The account's email and the scopes Google granted. |
| `google.status` | `connected` or `revoked`. |
| `google.last_ok_at`, `google.last_error` | For Health. |

The client ID and secret stay in `.env`.

The vault is Mail's design (AES-256-GCM, `v1:<key id>:<base64>`) with its own key. The associated data is the setting's name, so a value copied onto another row will not decrypt. If the key is lost, an admin presses Connect again.

Nothing logs a token, a code, a secret or a participant. Failures log only the host, the HTTP status and Google's reason word.

## When Google says no

- **`invalid_grant` on a refresh:** the owner withdrew access, or the token died. The connection becomes **revoked**:
  - every call throws `GoogleError('revoked')` without touching the network;
  - System Health turns **red**;
  - the page offers **Connect again**.
- **A 401 on an API call:** the token is refreshed once and the call tried again.
- **Other failures** map to `quota`, `scopes`, `not_found` or `failed`, each with a short message for a toast.

## System Health

The **Google (Meet)** check, in the Server group, alerts at once when red:

| State | When |
|---|---|
| **Red** | Access was withdrawn. |
| **Amber** | Not set up: the client or the vault key is missing in `.env`. |
| **Amber** | Not connected. |
| **Amber** | The last call was refused for the client or a permission. |
| **Green** | Connected. The detail says when the last call worked. |

## Live attendance (`HOD\TrainingLive\Matcher`)

After the class, the ERP reads Meet's participant list:

- each participant's display name, which on personal-Gmail meetings is a name only, never an email;
- the stretches each participant spent in the call.

A participant counts for a learner automatically only when the match is clear:

1. **Name:** exactly one enrolled learner matches at **95% or more**. Names are normalised, so accents, titles (Pastor, Sis., Dr …), brackets, punctuation and word order don't count.
2. **Join log:** either of these is enough:
   - the participant entered within 2 minutes before to 10 minutes after exactly one learner pressed **Join class**, and nobody else entered in that window;
   - exactly one learner who pressed Join matches the name at 80% or more, 10 points clear of the next.

Everyone else lands in **Needs a look**, with up to three suggestions: learners who pressed Join first, then the best name matches. A learner who pressed Join but was not found in the call is listed too.

Minutes are the time in the call, with overlapping stretches counted once, so a phone and a laptop together count as one. **Present** means at least N minutes, set per class (default 30). The tutor can always correct the result.

## Live classes (`HOD\TrainingLive\Live`)

**The Meet link.** In the builder, a live item has a **Meet** button (or **Attendance** for an in-person class). It opens `/modules/academy/live?item=…`, where the Academy:

- presses **Create Meet link**. This needs an online class with a date and time, and Google connected;
- gets an event on the church's calendar with a Meet: titled "Training: class", at the class's time and length, in Lagos time;
- adds no guests and has Google send nothing, so no learner record goes to Google;
- sees the link stored in the item's `details.meet_url` / `meet_event_id`. PR 1 keeps those on every edit, and the learner's page prefers them to a pasted link.

**The Meet follows the item.** `api/trainings_api.php` calls `training_live_after_action()` after a builder change (an item saved or removed, a card removed, the settings, the training deleted):

- a moved or retitled class patches its event;
- a removed class, or one made in person or left without a time, deletes the event.

A Google problem never fails the builder. It is kept on the meeting, shown on the Meet page, and tried again on the next save or with **Update**.

**Join class.** The learner's button opens the link as before, in a new tab, and `training-live.js` logs the press (`training_join_log`, once a minute at most). Only learners on the training can be logged.

**Attendance.** It is read from Meet by **Get attendance** on the Meet page, or by the daily run (`cron/trainings_daily.php`, 06:30) for classes that ended 2 hours to 3 days ago:

1. Meet's calls with the class's code, starting from an hour before the class to 3 hours after it.
2. Their participants and stretches.
3. The Matcher.
4. `training_attendance`, and for those present the item **completed** in `training_progress` (`data.by = 'meet'`), then `trainings_recheck_enrolment()`.

The rest go to **Needs a look** (`training_meet_participants`), one row per name.

**The tutor decides.** On the Meet page the tutor can:

- mark any learner present or absent;
- give a participant to a learner (their minutes count, present at N);
- **Ignore** a guest or their own device;
- **Reopen** a decision.

A tutor's choice (`source = manual`, progress `by = 'tutor'`) is never changed by a later read. A read removes only what an earlier read added.

## Known limit: who may enter the call

A Meet made through Calendar on a personal Gmail belongs to that account. People who are not invited may have to **ask to join**, and only the host can admit them. If this gets in the way, there are two options:

- the tutor runs the class signed in as the connected account;
- a later change makes the space open, which needs the `meetings.space.created` scope (the Meet REST API, `spaces.patch` with `accessType: OPEN`).
