# Integrations: Google for Trainings' live classes

Trainings, PR 3 (`TRAININGS-PLAN.md`, owner's answers 5 and 6). The ERP connects to **one Google account**. First it is the JBS Praxis Gmail, for testing, and later HOD's own. The connection gives:

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
| `db/migrations/20261210090000_integrations_google.sql` | `integration_settings`. |
| `tests/integrations/run.php` | Vault, settings, the client against a fake Google, the matcher, static rules. |

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

The tables (`training_meetings`, `training_join_log`, `training_attendance`) and the pages arrive once PR 1's `training_items` has merged.

## Known limit: who may enter the call

A Meet made through Calendar on a personal Gmail belongs to that account. People who are not invited may have to **ask to join**, and only the host can admit them. If this gets in the way, there are two options:

- the tutor runs the class signed in as the connected account;
- a later change makes the space open, which needs the `meetings.space.created` scope (the Meet REST API, `spaces.patch` with `accessType: OPEN`).
