# Special Events Module — Engineering Guide

| | |
|---|---|
| **Module** | Special Events (the "Envision Studio") |
| **Code name / prefix** | `special_events` · database tables `se_*` · PHP functions `se_*()` |
| **Public URL shape** | `https://hodlc.lpc.cm/e/<slug>` — e.g. `https://hodlc.lpc.cm/e/chara` |
| **First event** | **Chara 2026** — Envision's karaoke & games night (*chara*, χαρά, is Greek for "joy"). Target: last week of October 2026, date to be confirmed. |
| **Organiser** | Envision, the creative department of Household of David Lekki Centre |
| **Owner** | Tom-Blake Asaah |
| **Status** | Approved design — being built (plan and progress: §28) |
| **Version / date** | 1.1 · 2026-10-03 |
| **Sources** | Meeting of 2026-09-30 (Tom-Blake Asaah, Odun-Ayo Funmilola, Chidera) · 20-question design interview of 2026-10-03 · codebase audit at commit `bd1094b` |

---

## Table of contents

0. [How to read this guide](#0-how-to-read-this-guide)
1. [Executive summary](#1-executive-summary)
2. [Background: the meeting and the existing platform](#2-background-the-meeting-and-the-existing-platform)
3. [Decision log](#3-decision-log)
4. [Glossary](#4-glossary)
5. [Goals, non-goals and success metrics](#5-goals-non-goals-and-success-metrics)
6. [People, roles and surfaces](#6-people-roles-and-surfaces)
7. [User journeys](#7-user-journeys)
8. [Architecture](#8-architecture)
9. [Data model](#9-data-model)
10. [Domain logic: events, identity, capacity, check-in, teams, program, karaoke](#10-domain-logic)
11. [Games engine, scoring and the live show](#11-games-engine-scoring-and-the-live-show)
12. [API reference](#12-api-reference)
13. [Front-end specification](#13-front-end-specification)
14. [Share cards and the Format Studio](#14-share-cards-and-the-format-studio)
15. [AI layer](#15-ai-layer)
16. [Messaging (SMS)](#16-messaging-sms)
17. [Hand-off to Reach and Embrace](#17-hand-off-to-reach-and-embrace)
18. [Insights and reporting](#18-insights-and-reporting)
19. [Security and privacy](#19-security-and-privacy)
20. [Configuration reference](#20-configuration-reference)
21. [Changes to existing code and docs](#21-changes-to-existing-code-and-docs)
22. [Testing strategy](#22-testing-strategy)
23. [Deployment and operations](#23-deployment-and-operations)
24. [Event-day runbook and failure playbook](#24-event-day-runbook-and-failure-playbook)
25. [Delivery plan for Chara](#25-delivery-plan-for-chara)
26. [Roadmap and idea bank](#26-roadmap-and-idea-bank)
27. [Open items and inputs needed](#27-open-items-and-inputs-needed)
28. [Build plan: pull requests and progress](#28-build-plan-pull-requests-and-progress)
- [Appendix A — Complete SQL migrations](#appendix-a--complete-sql-migrations)
- [Appendix B — Live snapshot examples](#appendix-b--live-snapshot-examples)
- [Appendix C — Deck item payload schemas and samples](#appendix-c--deck-item-payload-schemas-and-samples)
- [Appendix D — AI prompt templates and response schemas](#appendix-d--ai-prompt-templates-and-response-schemas)
- [Appendix E — Default per-event settings (`settings_json`)](#appendix-e--default-per-event-settings-settings_json)
- [Appendix F — Copy deck (microcopy)](#appendix-f--copy-deck-microcopy)
- [Appendix G — Chara starter content](#appendix-g--chara-starter-content)
- [Appendix H — Checklists](#appendix-h--checklists)

---

## 0. How to read this guide

**Who this is for.** Developers and AI coding agents building the module. Envision leads and the Producer of each event should read §1, §6, §7, §13.3, §24 and §27. Leadership should read §1, §5, §17, §18 and §19.

**Normative words.** **MUST** / **MUST NOT** are hard requirements; a pull request that breaks one is wrong even if it works. **SHOULD** / **SHOULD NOT** are strong defaults; deviate only with a written reason in the PR. **MAY** is optional.

**Where this sits among the repo docs.** `AGENTS.md` still governs every file in the repository. Where this guide sets a module-specific rule that differs from `AGENTS.md` (for example, using Preact in this module only), the rule is listed in §21 and `AGENTS.md` MUST be updated in the same pull request that first relies on it.

**Reading order for implementers.** Start with §3 (decisions), §8 (architecture), §9 (data) and §10–§11 (logic), then the API (§12) and front-end (§13) for the surface you are building. Appendix A is the source of truth for the schema; Appendix E is the source of truth for per-event settings.

**Building it?** The module is built in pull requests PR0–PR7. Start with §28 (the PR map, the progress tracker and the deviations log) and your PR's prompt in [`docs/build_prompts.md`](build_prompts.md).

**Conventions used below.**
- Times are West Africa Time (WAT, UTC+01:00). PHP runs in `Africa/Lagos`, MySQL with `time_zone = '+01:00'` (both set in `includes/db.php`).
- "Phone" always means the normalised identity form defined in §10.3. "Registration" means one person's place at one event. "Contact" means one person across all events.
- Code identifiers are in `monospace`. File paths are relative to the repository root.

---

## 1. Executive summary

Envision runs a periodic **karaoke and games night**, and the first edition is **Chara 2026**. We are building a reusable **Special Events** module inside the HOD Lekki ERP. It covers the whole life of a special event:

1. **Create.** In the Studio (an ERP screen), Envision creates an event, sets its short link (`/e/chara`) and enters its brand colours as hex codes, with AI-suggested palettes on top. They then set the dates (multi-day supported), capacity rules and registration form, build the programme by hand or import it with AI from a written copy or screenshot, set the teams with their hex colours, import the karaoke song list, prepare the games, upload the flyers and assets, and assign the crew. Any event can be **cloned** from a previous one.
2. **Promote and register.** The public portal at `/e/<slug>` is a cinematic, animated "Marquee" experience (dark stage, spotlights, neon type, scroll chapters) in the event's own colours. Registration is phone-first and takes about 20 seconds. Members are recognised from their phone number, and returning guests are pre-filled. Registration closes itself at capacity, with an optional waitlist, self-cancellation, a walk-in cap and a "seats left" counter, all configurable per event. Guests can make an **"I'm going to Chara"** card with an optional photo in a circle.
3. **Check in.** One QR poster downstairs opens `/e/chara/in`. A guest types their phone number and the system either confirms them ("Welcome, Ada O.") or registers them as a walk-in on the spot. It then **auto-assigns a colour team**, balanced by size, then gender, then the guest/member mix. The guest gets a player number, a personalised **welcome-verse card** in the style of Exousia, and their karaoke queue number. Volunteers can check people in from **desk mode**, and a **lobby screen** downstairs animates each arrival into their team.
4. **Run the night.** A host console drives the **stage display** on the projector, while every checked-in phone becomes a game controller. The v1 games are **Bible Charades**, a **Kahoot-style Live Quiz**, **Bible Trivia**, the **buzzer & word games** (Bible Buzzer, Finish the Verse, Who Am I?, Emoji Bible) and **Bible Family Feud**. Every game feeds one **team championship**, with individual MVPs from the quiz. The karaoke list is a **list only** (title, artist, duration). Singers can **pre-pick** a song before the event, each song can be taken only once, and the queue uses numbers rather than time slots.
5. **After the event.** A thank-you SMS links to a **"My Night" recap card** and a one-minute survey. Envision then pushes guest data to the follow-up teams through a **guided hand-off wizard**: guests go to a new Reach campaign, and guests who said "I'd love to visit HOD" go to Embrace's first-timer queue. Leadership gets an insights dashboard and a PDF report.

**Architecture in one paragraph.** The module stays on today's cPanel/PHP 8.3/MySQL host and is **fully standalone**: it has its own `se_*` tables and does not touch `events`, `checkins` or `attendance`. Pages under `/e/` are a no-build modern front-end, made of native ES modules with Preact + htm + signals (vendored locally), GSAP for motion and a precompiled Tailwind v4 stylesheet driven by per-event CSS variables. Realtime runs on **polling of static JSON snapshots**, which LiteSpeed serves without starting PHP. Snapshots are written atomically on every state change, phones poll about once a second, and a **clock-synced "reveal-at"** time keeps quiz scoring fair. A driver interface leaves room to switch on a push service later. AI calls go through one provider-neutral `se_ai()` layer on the existing Gemini key, with JSON-schema validation and human review before anything is saved. SMS goes through SMS Studio's existing queue and worker.

**Timeline.** Chara is 3–4 weeks away. Registration must go live first, then check-in and teams, then the live show. See §25 for the phased plan and acceptance criteria.

---

## 2. Background: the meeting and the existing platform

### 2.1 What the 2026-09-30 meeting decided

Attendees: Tom-Blake Asaah (platform), Odun-Ayo Funmilola (Director; owns registration), Chidera (Envision). The notes were generated by Gemini and the transcript was read in full for this guide.

| # | Topic | Outcome |
|---|---|---|
| M1 | Where registration lives | Inside our own ERP so the data stays in-house and can be followed up (bulk SMS, invitations to future events). A **separate, eye-catching registration experience** — animated, icon-led, not text-heavy, with media (YouTube/voice-overs/AI illustrations) — but still inside the platform. |
| M2 | Registration payload | **Phone number is compulsory and comes first**; it recognises existing members ("it registers you, confirms your name") and tolerates format mistakes (with or without `234`/`0`). Guests give name, email, and **gender (Male/Female only — no "other")**. Browser autofill should make it fast. Do **not** ask for church affiliation; neighbourhood was discussed and dropped to keep it light. |
| M3 | Karaoke interest | A karaoke-interest checkbox at registration. The song list will not be ready at launch, so **song picking happens later (at check-in)**. |
| M4 | Capacity | **120** seats via the link; **registration closes automatically** at 120, with a dashboard button to reopen. **≈30 extra walk-ins** are welcome on the day (registered at the door, not via the link). |
| M5 | Check-in | **Downstairs**, via **printed QR posters (2–3 copies)**. One code for everyone: enter phone → if registered, confirm and check in; if not, register and check in at once. |
| M6 | Teams | Check-in **auto-distributes people equally into 4 colour teams, balanced by gender**. Whether teams are pre-named or name themselves was left open (resolved in D10). |
| M7 | Karaoke order | Times were proposed, but because the programme runs flexibly (buffers, debates running over, the Pastor's wrap-up) people get a **sequence number, not a time slot**. Karaoke is last on the lineup. |
| M8 | Final check-in page | Welcome message + a **Bible verse**, signed "**Chara 2026 by Envision**", downloadable as an image to share (as was done for Exousia). |
| M9 | Games | A real-time games backend: a "**Join the games**" button on the check-in page (only after check-in); questions answered on phones with results visible; **charades** where the host picks a team representative **by their number** (e.g. "#120") and **only that person's phone** shows the word. External Kahoot was discussed — building it in keeps everyone's performance in one place. Tom-Blake owns the trivia questions. |
| M10 | Reuse | The module is part of the ERP so the **next edition is configuration, not a rebuild**: name, karaoke toggle, re-upload or reuse last year's song list. |
| M11 | Follow-up | Envision is not a follow-up department. **Hand attendee data to follow-up and evangelism** (Reach, Embrace, Assimilation) — e.g. "create a campaign for those who came through this event". Present numbers (members vs guests, new guests) to leadership afterwards. |
| M12 | Programme | Chidera to get the programme; Tom-Blake to meet **Tommy** (events lead) about lineup and flow. There is a 15-minute buffer and a debate segment; Pastor Billy wraps up. |
| M13 | Brand | The team still had to pick the event's dominant colours. |
| M14 | Deadline | Registration, check-in and associated features ready for testing by **Sunday** (2026-10-04), to align with the flyer release. |

### 2.2 What already exists in the codebase (and what we reuse)

The audit at `bd1094b` found the following relevant pieces.

| Existing piece | Where | How the module uses it |
|---|---|---|
| Generic events, registration, check-in (Exousia era) | `events`, `event_registrations`, `checkins`, `attendance`; `register.php`, `checkin.php`, `api/registration_api.php`, `api/checkin_api.php` | **Not used for data** (decision D2: standalone). We reuse *ideas*: phone-first lookup, the volunteer mode, the blessing card with iOS share sheet, the GSAP/confetti registration page, and the Open Graph meta for link previews. |
| Phone normalisation | `sms_normalize_phone()` in `includes/sms_functions.php`; `ci_normalize_phone()` in `api/checkin_api.php` | `se_phone_normalize()` (§10.3) delegates Nigerian mobiles to `sms_normalize_phone()` so the two never disagree. |
| Member lookup by phone | `ci_find_user_by_phone()` pattern, `findFuzzyMemberMatch()` | Same multi-format lookup (234…, 0…, +234…, last-10-digits). Fuzzy name matching is used only in the Studio's "possible member" flag, never to auto-link. |
| SMS Studio | `sms_campaigns`, `sms_queue`, `sms_send_one()`, `cron/sms_queue_worker.php`, `sms_segments()`, `sms_render()` | All module SMS is enqueued as SMS Studio campaigns and sent by the existing worker (AGENTS.md: never write `sms_log.status` directly). One small extension: a `{{link}}` merge field (§21). |
| Gemini | `reach_gemini()` in `includes/reach_helpers.php`, multimodal example in `api/charis_api.php` | `se_ai()` (§15) is a new wrapper: multimodal, JSON-schema, usage logging, header-based key. It reuses `GEMINI_API_KEY`. |
| Reach / Embrace data entry | `reach_campaigns`, `reach_leads`, `reach_lead_captures`, `reach_notify()`; Embrace queue = `users.spiritual_status IN ('1st_Timer','2nd_Timer','3rd_Timer')` | The hand-off wizard (§17) writes leads exactly as Reach's own code does, and first-timers exactly as `push_to_embrace` does. |
| Security primitives | `security_client_ip()`, `security_enforce_session()` (via `includes/db.php`), audit/notification helpers | IP hashing, session gate for crew and Studio, `system_notifications` for crew alerts. |
| PDF / Excel | dompdf, PhpSpreadsheet (Composer) | Event report PDF and attendee exports (§18). |
| Department IDs | header nav: IDI = 1, Embrace = 2, **Envision = 3**, Reach = 8 | Envision clearance (§6.2). |
| UI conventions | `includes/header.php` layout, `assets/js/modal-manager.js` modal contract, `assets/js/tab-deeplink.js` (`#tab=`), Ctrl/Cmd+K palette index | The Studio follows all three (§13.13). |

### 2.3 Hosting facts that shape the design

These are confirmed from the repo (`php.ini`, `bin/deploy.sh`, `README.md`, code comments) unless marked otherwise.

| Fact | Consequence |
|---|---|
| cPanel shared hosting, LiteSpeed, PHP 8.3 (ea-php83) | No long-running processes and no WebSockets. Shared hosts cap concurrent PHP "entry processes". **No SSE or long-polling**; requests MUST be short (§8.5). |
| `max_execution_time 300`, `memory_limit 128M`, `upload_max_filesize 128M` | AI calls and imports are synchronous with timeouts ≤ 60 s. Uploads are capped per kind (§19.6). |
| **No `ext-fileinfo`** (deploy uses `--ignore-platform-req=ext-fileinfo`; Reach validates images by decoding) | File types MUST be detected by magic bytes and image decoding, never `mime_content_type()` or `finfo`. |
| No Node.js on the server; deploy = `git reset --hard` + `tar` copy + `composer install` + `php db/migrate.php` | No server-side JS build. Front-end libraries are **vendored**, and the compiled CSS is **committed** with a CI freshness check (§23.2). |
| **Deploy never deletes files from the docroot** (tar copy, not `rsync --delete`) | Renamed or removed public files linger in production. Never rely on deletion for security. Runtime directories are created by tracked placeholder files. |
| Nested `.htaccess` files are deployed (only the root `.htaccess` is excluded) | Clean URLs `/e/<slug>` are possible with `e/.htaccess` without touching the cPanel-managed root `.htaccess`. |
| Cron minimum interval 1 minute | Second-level transitions (closing a round on time) are driven by a **heartbeat** from the stage display and host console (§8.5.6), not cron. |
| Migrations: forward-only, one transaction per file, DDL auto-commits | One logical change per file; migrations shipped per delivery phase (§9.4). |
| MySQL in production may be MySQL 8 **or** MariaDB (unconfirmed) | Use only syntax both accept: generated `STORED` columns and InnoDB FKs yes (but no `CASCADE`/`SET NULL` FK on a generated column's base column, which MySQL 8 rejects); `ADD COLUMN IF NOT EXISTS`, `SKIP LOCKED` and JSON functions no. JSON is stored in `TEXT` columns named `*_json`. |
| Guests will be on the church Wi-Fi (D19), i.e. many phones behind **one public IP** | Per-IP throttling by the host is the main scaling risk. It MUST be checked with the host before the event, and IP-keyed rate limits MUST be generous (§19.4, §24). |

---

## 3. Decision log

Every decision below is binding for v1. "Source" is where it was made: **M** = meeting of 2026-09-30, **I** = design interview of 2026-10-03, **G** = derived in this guide (with rationale).

| ID | Decision | Source |
|---|---|---|
| D1 | First event **Chara** is **3–4 weeks away**. Ship in phases: registration first, then check-in + teams, then the live show, then post-event (§25). | I |
| D2 | **Fully standalone**: own `se_*` tables only. Nothing is written to `events`, `event_registrations`, `checkins` or `attendance`. Members are *recognised* by reading `users` (read-only). Other departments receive data only through the explicit hand-off (D17). | I |
| D3 | **Shortest links**: `https://hodlc.lpc.cm/e/<slug>` with a slug entered at creation (Chara → `chara`). Sub-paths for the other surfaces (`/e/chara/in`, `/play`, `/stage`, …). Slug changes leave permanent redirects so printed QR codes never break. | I |
| D4 | Realtime by **polling static JSON snapshots** on the current host, with a **driver interface** that can switch on a push service (Ably) by configuration later. | I |
| D5 | Front-end for `/e/` surfaces and the Studio: **no-build modern** — native ES modules, Preact + htm + `@preact/signals` (vendored), GSAP (+ScrollTrigger, SplitText), precompiled Tailwind v4 + per-event CSS variables. Allowed **in this module only**; AGENTS.md is updated accordingly. | I, G |
| D6 | Access: **Envision studio + per-event crew**. Super Admin, Resident/Assoc Pastors and Envision HOD/Director manage all events; any active Envision member can create events (and becomes Producer). Per-event crew roles: Producer, Host, Game master, Desk, Karaoke DJ, Media, Follow-up, Viewer — assignable to any member. | I |
| D7 | Registration form: **phone first (required)** → members confirm; guests give first & last name, gender (M/F), email (optional), karaoke interest, "How did you hear about us?" and a consent tick (D7a). Every field is toggleable per event, plus custom questions. | I, M |
| D7a | Consent: default mode is the **required consent tick for follow-up** chosen in the interview. A privacy-preferred alternative mode ("notice + optional opt-in") ships as a per-event setting, and the wording MUST be confirmed by leadership (§19.8, §27). | I, G |
| D8 | Capacity behaviours are **all configurable per event**: online capacity (e.g. 120, or 90 for a smaller event), auto-close at capacity, manual override (force open / force closed / raise capacity), **waitlist** with auto-promotion, **self-cancel**, **walk-in capacity with optional hard cap**, **seats-left counter** (never/threshold/always). Seat allocation MUST be race-free (§10.4). | I, M |
| D9 | Check-in = **poster QR + phone number gives the whole experience**. Plus **volunteer desk mode** and a **lobby welcome screen**. No personal QR pass, no presence code. Check-in only inside the event's check-in window. | I, M |
| D10 | Teams: count configurable (Chara: 4). Each team's colour is entered as a **hex code** (e.g. `#000000` for black) with a label. **Teams name themselves on the night and a crew member types the name into the dashboard**. Auto-assignment at first check-in balances **size → gender → guest/member mix** (§10.6). | I, M |
| D11 | Karaoke library is a **list only**: title, artist, duration (importable from paste/CSV/XLSX or a screenshot via AI). Per-event switches: **pre-pick before the event** (via the manage link) and **unique songs** (first come, first served). Queue **numbers**, not times. Duets and audience voting are roadmap. | I, M |
| D12 | v1 games: **Bible Charades**, **Live Quiz** (Kahoot-style, individual), **Bible Trivia** (team, captain answers), **Buzzer & word games** (Bible Buzzer, Finish the Verse, Who Am I?, Emoji Bible), **Bible Family Feud** (survey-powered). Audience moments and party mechanics are roadmap. | I, M |
| D13 | Scoring: **team championship first + individual MVPs**. Screens show **first name + last initial + team colour**. Crew bonuses/penalties are allowed and audited. | I |
| D14 | AI: **Gemini (existing `GEMINI_API_KEY`) behind a provider-neutral `se_ai()`** with JSON-schema validation, human review before anything is saved, usage/cost logging and per-user rate limits. **No AI image generation in v1.** | I |
| D15 | Assets: **brand asset kit**, **auto format studio** (WhatsApp Status 9:16, Instagram 1:1 and 4:5, link card 1200×630, projector 16:9, QR posters), **personal share cards**: "I'm going" (with an **optional photo in a circle**, composited on the phone; the photo is never uploaded), welcome-verse card, team card, "My Night" recap. | I |
| D16 | Public look: **"Marquee"** theme preset — a hybrid of *Neon Stage* (dark stage, spotlights, neon kinetic type, equaliser, glass cards) and *Cinematic Story* (full-bleed hero video/gradient mesh, scroll-driven chapters, sticky register bar). Colours always come from the event's hex palette. | I |
| D17 | Hand-off: **guided wizard** after the event. Guests → a new **Reach campaign** named after the event (as leads). Guests who ticked "I'd love to visit HOD" → **Embrace** first-timer queue. Members are not pushed. Idempotent, logged, links existing profiles instead of duplicating them. Excel export is always available. | I, M |
| D18 | Automated SMS: **reminders** (day before and ~2 h before) and **thank-you + survey** (next morning), each a per-event switch with editable templates. Transactional waitlist-promotion SMS is part of the waitlist feature. **No automatic confirmation SMS and no email channel in v1.** | I |
| D19 | Venue has **projector/TV + laptop**, **sound system**, **guest Wi-Fi** and **signal downstairs** (with a screen for the lobby display). | I |
| D20 | Payments: **none in v1**; the design reserves hooks for ticketing (§26.3). | I |
| D21 | Without a confirmation SMS, the private **manage link** is shown on the success screen (copy / share / add to home screen), **remembered on the device**, and **included in the day-before reminder**. A rate-limited, on-demand "Text me my link" (1 SMS) is a per-event switch, on by default. | G (from D9, D18) |
| D22 | Gender is required for guests (team balance). For members whose `users.gender` is empty, check-in asks once (one tap). | G (from M6) |
| D23 | A person can hold **one active registration per event** and **one karaoke song at a time** (v1). | G |
| D24 | Every crew mutation and every score change is written to an append-only audit log; **scores are a ledger** (never updated in place). | G |
| D25 | Names on screens are **"Ada O."** (first name + last initial). Names appear only in key-protected snapshots, never in the open `public.json`. | G (from D13) |
| D26 | The default Bible translation is **KJV** (public domain). Verse text is fetched and stored from a Bible source at authoring time, never typed by the AI. | G |
| D27 | Events are optionally grouped in a **series** (e.g. "Chara") for cloning defaults and cross-edition insights (returning guests, Hall of Fame). | G |

---

## 4. Glossary

| Term | Meaning |
|---|---|
| **Studio** | The ERP screen `modules/special_events/index.php` where events are created, configured and analysed. Requires ERP login. |
| **Portal** | The public site of one event at `/e/<slug>`: story, registration, manage link, check-in, games, recap. |
| **Surface** | One of: Portal, Check-in, Games portal (Play), Stage display, Lobby display, Host console, Desk mode, Karaoke DJ console, Studio. |
| **Slug** | The short name in the URL (`chara`). Lowercase letters, digits and hyphens. |
| **Public ID** | A random 12-character identifier of an event (`se_events.public_id`), used in snapshot paths and cookies so internal IDs never leak. |
| **Contact** | One person across all special events, keyed by phone (`se_contacts`). |
| **Registration** | One contact's place at one event (`se_registrations`). Has a status (`confirmed`, `waitlisted`, `cancelled`, `removed`) and, once confirmed, a **seat pool** (`online` or `walkin`). |
| **Walk-in** | Someone who registers on the day at check-in, or a waitlisted/cancelled person seated on the day. They consume the walk-in pool. |
| **Manage link** | Private URL `/e/<slug>/me/<token>` that lets the holder manage that registration (status, cancel, karaoke pre-pick, cards, feedback). |
| **Device token** | Random secret in an HttpOnly cookie that binds one browser to one registration for one event. |
| **Player number** | Sequential number given at first check-in (`#47`), shown on the phone and lobby display; the host calls people by it (e.g. to present charades). |
| **Queue number** | Karaoke ticket number, assigned when a song enters the live queue. |
| **Team** | One of the event's colour teams; holds a hex colour, a label, a crew-entered name, an optional captain. |
| **Captain** | The team member who submits the team's answer in captain-mode games (Trivia). |
| **Scene** | What the stage display shows right now (`standby`, `game`, `karaoke`, `leaderboard`, …). |
| **Round** | One question/turn inside a game, with a state machine (`pending → armed → open → locked → revealed → scored`). |
| **Snapshot** | A JSON file under `/live/<public_id>/` that is the published state for polling clients. |
| **Room key / team key / lobby key / stage key** | Unguessable secrets that make key-protected snapshots and the stage heartbeat reachable only by intended devices. |
| **Heartbeat (tick)** | A request the stage display (and host console) makes every second to flush pending publishes and fire time-based transitions. |
| **Ledger** | `se_score_events`: every point awarded or removed is a row; totals are sums. |
| **Hand-off** | The post-event push of guests to Reach and Embrace. |
| **Marquee** | The v1 theme preset of the portal (D16). |

---

## 5. Goals, non-goals and success metrics

### 5.1 Goals

1. **Reusable.** A new edition is configuration plus content, done in under 2 hours by a Producer (clone → change dates/slug/colours → publish).
2. **Beautiful and fast.** People should be fascinated by the portal and games, and it should still load quickly on a mid-range Android over 4G.
3. **Frictionless.** Registration in ≈20 s; check-in in ≤10 s from scanning the poster; joining the games in 1 tap after check-in.
4. **Fair and live.** Phones and the big screen agree within ~1 s; quiz speed scoring is fair across Wi-Fi and 4G.
5. **Safe.** No overselling seats, no data leaks (names only behind keys, PII only for authorised crew), every crew action audited.
6. **Useful afterwards.** Clean data hand-off to follow-up departments, plus a leadership report.

### 5.2 Non-goals (v1)

- Payments, ticketing, donations (hooks only — D20).
- Email sending (email is collected optionally for the future — D18).
- AI image generation (D14).
- Karaoke audio/lyrics playback, duets, audience voting (D11).
- Audience moments (polls, debate voting, word cloud, shout-outs) and party mechanics (raffle, wheel, bingo) (D12 → roadmap).
- Writing into the generic Events/Attendance tables (D2).
- Native mobile apps. The web app MAY be installable as a PWA (add to home screen) but no store apps.

### 5.3 Success metrics for Chara

| Metric | Target | How measured |
|---|---|---|
| Registration completion rate (started → submitted) | ≥ 80 % | `se_metrics_daily` (`reg_start`, `reg_done`) |
| Median registration time | ≤ 25 s | client timing beacon |
| Show-up rate (confirmed → checked in) | ≥ 75 % | registrations vs check-ins |
| Median self check-in time (page open → team reveal) | ≤ 10 s | client timing beacon |
| Games portal join rate (checked-in → joined) | ≥ 85 % | `se_devices.joined_games_at` |
| Quiz answer rate per question | ≥ 80 % of joined | `se_answers` |
| Snapshot freshness during live rounds | p95 ≤ 1.5 s from host action to phone | client telemetry (version timestamps) |
| Errors | 0 oversold seats; 0 lost check-ins; < 0.5 % failed API calls | logs |
| Satisfaction | NPS ≥ +50 | feedback survey |
| Follow-up | 100 % of consenting guests handed off within 72 h | hand-off log |

---

## 6. People, roles and surfaces

### 6.1 Personas

| Persona | Who | Needs |
|---|---|---|
| **Guest** | Someone invited by a friend or a flyer; may not know HOD | A stunning, quick registration; to feel welcomed; easy games; nothing awkward. |
| **Member** | In `users`; recognised by phone | One-tap registration; same experience as guests. |
| **Producer** | The Envision lead for the event (e.g. Tommy, Odun-Ayo) | Configure everything; see live status; make the call on overrides. |
| **Host / MC** | On the stage with the mic | Drive the show from a tablet or laptop without technical stress. |
| **Game master** | Next to the host | Launch rounds, judge answers, fix scores. |
| **Desk volunteer** | Downstairs at check-in | Help people without phones or data, people in a hurry, and older guests. |
| **Karaoke DJ** | Near the sound desk | Order the queue, call singers. |
| **Media** | Envision designers | Upload the brand kit; render flyers/posters/story formats. |
| **Follow-up liaison** | Reach/Embrace representative | See who came and hand off cleanly. |
| **Leadership** | Pastors, directors | Numbers and a narrative after the event. |

### 6.2 Access model

Two layers: **module-level** (who can enter the Studio, create events, manage all events) and **event-level crew roles** (what a person may do *for one event*).

**Module-level** (computed by `se_module_access($pdo, $userId, $activeRoleSession)`):

| Level | Who | Rule |
|---|---|---|
| `manager` | Super Admin, Resident Pastor, Assoc Pastor, Envision HOD/Director | Any role in `$_SESSION['roles']` named `Super_Admin`, `Resident_Pastor`, `Assoc_Pastor`; **or** `user_departments` row with `department_id = SE_ENVISION_DEPT_ID (3)`, `is_active = 1`, `role_in_dept IN ('HOD','Director')`. |
| `studio_member` | Any active Envision member | `user_departments.department_id = 3 AND is_active = 1`. |
| `crew_only` | Anyone holding a non-revoked crew role on a non-archived event | Row in `se_crew` with `revoked_at IS NULL` on an event whose `status <> 'archived'`. |
| `none` | Everyone else | Module link hidden; API returns `FORBIDDEN`. |

The department is matched by **ID 3** (as in `includes/header.php`). `se_settings.envision_department_id` overrides it if the ID ever changes.

**Event-level capability matrix.** ✔ = allowed. A `manager` has every capability on every event. A `studio_member` can create events and read every event's Overview (aggregates only); they get capabilities on a specific event only through crew roles (the creator is auto-added as `producer`).

| Capability | producer | host | game_master | desk | karaoke_dj | media | followup | viewer |
|---|---|---|---|---|---|---|---|---|
| Edit details, brand, registration, capacity, program, teams setup, songs, games, messages | ✔ | | | | | | | |
| Publish / unpublish / cancel the event | ✔ | | | | | | | |
| Archive / delete (draft only) | manager only | | | | | | | |
| Manage crew & rotate display keys | ✔ | | | | | | | |
| Capacity override (force open/closed) | ✔ | | | | | | | |
| Upload assets, render formats | ✔ | | | | | ✔ | | |
| Host console: scenes, program, announcements, sound | ✔ | ✔ | | | | | | |
| Game control: rounds, judging, presenters | ✔ | ✔ | ✔ | | | | | |
| Score awards/penalties, void score entries | ✔ | ✔ | ✔ | | | | | |
| Rename teams, set captains (live) | ✔ | ✔ | ✔ | | | | | |
| Move a person between teams | ✔ | | | ✔ | | | | |
| Desk mode: check in on behalf, walk-in, transfer code, undo check-in | ✔ | | | ✔ | | | | |
| Karaoke queue control | ✔ | ✔ | | | ✔ | | | |
| See attendee PII (full phone, email) | ✔ | | | | | | ✔ | |
| Desk search (name, last 4 digits of phone, reg code) | ✔ | | | ✔ | | | ✔ | |
| Export attendees (Excel) | ✔ | | | | | | ✔ | |
| Run the hand-off wizard | ✔ | | | | | | ✔ | |
| Insights (aggregates) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Audit log of the event | ✔ | | | | | | | |

**Rules.**
- Capability checks MUST happen server-side in every endpoint, via `se_require_capability($pdo, $eventId, $capability)`. UI hiding is cosmetic.
- `$_SESSION['active_role']` MUST NOT be used for module-level checks. Use the full `$_SESSION['roles']` list, as `includes/header.php` does. This lets a pastor with an active "Church_Member" role still manage events.
- Adding a crew member notifies them (`system_notifications`, link to the Studio).

### 6.3 Surfaces and URLs

| Surface | URL | Auth | Device |
|---|---|---|---|
| Hub (optional) | `/e/` | public | any |
| Portal (story + registration + phase-aware CTA) | `/e/<slug>` | public | phone first |
| Check-in (poster target) | `/e/<slug>/in` | public, phase-gated | phone |
| Manage link | `/e/<slug>/me/<token>` | token | any |
| Games portal | `/e/<slug>/play` | device bound to a checked-in registration | phone |
| Recap / survey | `/e/<slug>/me/<token>#recap`, `#feedback` | token | phone |
| Privacy notice | `/e/<slug>/privacy` | public | any |
| Calendar file | `/e/<slug>/calendar.ics` | public | any |
| Stage display | `/e/<slug>/stage#k=<room_key>&t=<stage_key>` | key in URL fragment | projector laptop |
| Lobby display | `/e/<slug>/lobby#k=<lobby_key>` | key in URL fragment | downstairs TV |
| Host console | `/e/<slug>/host` | ERP session + host/game_master/producer | laptop/tablet |
| Desk mode | `/e/<slug>/desk` | ERP session + desk/producer | phone/tablet |
| Karaoke DJ console | `/e/<slug>/dj` | ERP session + karaoke_dj/host/producer | tablet |
| Studio | `/modules/special_events/index.php` (+ `#tab=`) | ERP session + module access | desktop |

Display keys go in the **URL fragment** (`#…`) so they never reach server logs or `Referer` headers.

---

## 7. User journeys

Each journey lists the steps and the system behaviour behind them. Section references point to the detailed rules.

### 7.1 Producer creates Chara (or clones last year's)

1. **Studio → Special Events → "New event"** (or **"Clone…"** on a past event, §10.10).
2. **Details.** Title `Chara`, edition `2026`, tagline, rich description, organiser label `Envision`, venue (name, address, map link, notes such as "Check-in is downstairs"). Then the **days**: for a single day, one row (doors open 4:00 PM, starts 5:00 PM, ends 10:00 PM). For multi-day events, one row per day. Last on the tab, the **public page** panel: the portal's opening line, the countdown and hero-video switches, and the **Good to know** questions (S6) — the `settings.portal` branch, saved on its own through `portal_settings_save` so it never collides with the form above it.
3. **Slug.** Type `chara`. The field validates live: format, reserved words, availability (§10.2). The preview shows `hodlc.lpc.cm/e/chara`.
4. **Brand.** Paste the **primary** and **secondary** hex codes (optional accent). The derived palette appears immediately, with contrast badges on the live preview. **"Suggest palettes ✨"** returns four AI palettes built around the two colours, each with a mini portal/stage/team preview (§13.2, §15). Then pick the display and body fonts and upload the logo/wordmark, hero image and hero video (§14.1).
5. **Registration & capacity.** Field toggles (email optional/required/hidden, how-heard, karaoke interest), consent mode and text, custom questions; online capacity `120`, auto-close ✓, waitlist ☐/✓, self-cancel ☐/✓, walk-ins `30` (hard cap ☐/✓), seats-left mode; registration window (opens now, closes at event start).
6. **Programme.** Either build items by hand (drag to reorder, durations, public/featured flags, links to games/karaoke), or **"Import with AI"** from pasted text, a photo/screenshot or a PDF of the programme. Review the parsed table, then Apply (§10.7, §15.3).
7. **Teams.** Count `4`. Paste four hex codes (`#000000, #D11920, #F5C518, #1D356A`). Labels are suggested ("Black", "Crimson", "Gold", "Navy") and are editable. Names stay empty until the night.
8. **Karaoke.** Paste or upload the song list (title, artist, duration), or upload a screenshot of it. Review, then import. Toggle **pre-pick** and **unique songs**. Set an optional maximum number of singers. Click **"Publish list"** when it is final.
9. **Games.** Add games in programme order. Pick or create decks, or **"Generate with AI"** (§15.4). Review every item; verse text is fetched from KJV automatically. Mark items approved. Run **test mode** with the crew.
10. **Assets & formats.** Render WhatsApp Status, Instagram, link-card, projector and **QR poster** variants from templates in one click. Download, and set the link card as the Open Graph image (§14.2).
11. **Crew.** Add people with roles (Tommy → Producer; MC → Host; two people → Desk; DJ; Media; a Reach rep → Follow-up). Copy the **stage** and **lobby** display links.
12. **Messages.** Enable reminders and thank-you, review the templates, read the cost estimate, send a test (§16).
13. **Publish.** The checklist (Appendix H.1) must be all green for the blocking items. **Publish** makes `/e/chara` live, and registration opens per its window.

### 7.2 A guest registers

1. Opens `/e/chara?s=wa` from a WhatsApp flyer. `s` is the source code (§18.2). The Marquee hero loads in under ~2 s: neon "CHARA", spotlights, date chips, live countdown, and a pulsing **Register** CTA. Scroll chapters tell the story (§13.3).
2. Taps **Register**. A full-height sheet opens on the **phone step**: large `tel` input with country default Nigeria and `autocomplete="tel"`.
3. Submits. The server looks up the phone (rate-limited, §19.4) and returns one of:
   - **New person** → the form step: first name, last name (`given-name`/`family-name` autocomplete), gender (two big chips), email (optional), "How did you hear?" chips, karaoke toggle (animated mic), consent tick (+ privacy link), custom questions.
   - **Member** (phone in `users`) → "Hi Ada O. 👋 Is this you?" → **Yes, register me** → karaoke toggle + consent (if not on file) → submit. **"Not me"** → "Please double-check the number". If the number is theirs but the name is wrong → "Register me, my name is …" (stored as a name correction for IDI review; never written to `users`).
   - **Returning guest** (`se_contacts` from a previous event) → pre-filled form: "Welcome back, Ada!" → confirm.
   - **Already registered** → "You're already in, Ada O. 🎉". If this device holds the registration: show the ticket. Otherwise offer **"Text me my link"** (if enabled), or "Your link comes with your reminder; you can also just check in at the door".
4. Submit → the server allocates a seat atomically (§10.4):
   - **Confirmed** → confetti in brand colours; ticket card "You're in, Ada! · CHARA · Sat 24 Oct · 5 PM"; reg code; **Save your link** (copy / share / add to home screen); **Make your "I'm going" card**; **Add to calendar**; **Invite friends** (personal link `?r=<ref_code>`). Then an optional one-tap question: "Would you like to visit HOD Lekki on a Sunday?" (sets `wants_visit`).
   - **Waitlisted** → "You're #4 on the waitlist. We'll text you the moment a seat opens."
   - **Full / closed** → "We're full online — walk-ins are welcome on the day while space lasts." (only if walk-ins are enabled).
5. The device is bound (cookie). Revisiting `/e/chara` shows the "You're registered ✓" state, with **Manage** in the sticky bar.
6. **Register someone else** (e.g. a friend without data): from the success screen, the guest registers another phone number. Their own device identity is kept, and the friend's manage link is shown with **Send to Tobi** (WhatsApp/SMS share sheet) (§10.3.5).

### 7.3 Manage link

`/e/chara/me/<token>`. Opening it binds the device and shows:
- Status card (confirmed / waitlist position), event info, add to calendar, invite link.
- **"I can't make it"**, if self-cancel is enabled: confirm dialog, then cancelled. The seat is released and the top waitlisted person is promoted (§10.4.5).
- **Karaoke pre-pick**, if enabled and the list is published: search the song list, see taken songs greyed out, hold one song, change or release it (§10.8).
- Share cards: "I'm going" (with optional photo).
- After the event: **My Night** recap and the **feedback** survey.

### 7.4 Event day: self check-in (registered guest)

1. Downstairs: a lobby TV shows the big check-in QR, live arrivals and team counts. Two or three printed A3 posters carry the same QR (`/e/chara/in`).
2. The phone opens `/e/chara/in`, which goes straight to the phone step. If the device is already bound to a registration, it shows **"Check in as Ada O.?"** with one tap.
3. Phone → "Welcome, Ada O.! Tap to check in" → **Check in**.
4. The server (one transaction under the event lock, §10.5) records the check-in, gives a **player number** (#47) and **assigns a team**, balanced by size → gender → guest/member. It also picks a welcome verse and moves any pre-picked karaoke song into the live queue with a **queue number**.
5. **Reveal**: the screen floods with the team colour, "You're on **TEAM BLACK**" with the team name if already set, and a big "#47". Haptic pulse; confetti in the team colour.
6. **Welcome card**: "Dear Ada," + KJV verse + a personalised prayer line + "Chara 2026 by Envision" → **Save / Share** (§14.3).
7. If karaoke-interested and no song is held yet: **"Pick your song"** (list or "coming soon") → queue number #12 → "There are 11 singers before you".
8. **Join the games** → `/e/chara/play`.
9. Downstairs, the lobby display animates "Welcome Ada O. → Team Black" (§13.9).

### 7.5 Event day: walk-in

1. Phone typed at `/e/chara/in` is unknown (or known but not registered, or waitlisted/cancelled).
2. If walk-ins are disabled, or the walk-in pool is full and the hard cap is on: "We're at capacity — please see the desk" (the desk can override, §10.4.6).
3. Otherwise a short form appears: names, gender and consent for new people; only consent for known contacts and members. Submit → registered (`channel = walkin_self`, `seat_pool = walkin`, or `online` if an online seat has freed up) and checked in, in the same transaction. Then continue from 7.4 step 5.

### 7.6 Event day: desk-assisted check-in

1. A desk volunteer opens `/e/chara/desk` (ERP login once; the session persists).
2. Search by name, last four digits of the phone, or reg code. Results show display name, status, team and checked-in state.
3. **Check in** (asks gender if missing) → the team and player number are shown on the volunteer's screen, so they can say "You're Team Black, number 47!".
4. **Walk-in** form for people without phones. Their phone number is still required as the identity, but they can give a family member's number with a note, which the volunteer can override.
5. **Transfer to a new phone**: generates a 6-digit code valid for 10 minutes. The guest enters it on their phone at `/e/chara/in` → "Use a code". The new device is bound and the old one becomes read-only.
6. **Undo check-in** (mistakes), with a reason; audited.
7. **Offline tolerance**: if the network drops, actions queue locally and sync later (§13.11).

### 7.7 Already checked in, on a different phone

Typing the phone again returns "You're already checked in, Ada O. — on another phone?". The guest gets a read-only view (team, number, card) and the hint "Ask the desk for a transfer code to play on this phone". This stops someone hijacking another person's game session by typing their number.

### 7.8 The live show (host, game master, players)

1. Before start: the stage shows **standby** (loop of brand slides, "Scan to join" QR, check-in count, countdown). Phones in `/play` show "Hang tight — we start soon" with team standings.
2. The host starts programme item **"Welcome"** in the console; the stage shows the welcome scene. ETAs for later items recompute (§10.7).
3. **Team naming**: teams huddle and pick names. The host types "Joy Bringers" for Team Black. The stage plays a **name reveal**, and every phone in that team updates its header.
4. **Live Quiz**: the game master presses **Next question → Arm**. The stage shows a 3-2-1 countdown while phones receive the question. At the shared, clock-synced `opens_at`, the question and choices appear on the stage and on every phone simultaneously. Players tap; the stage shows "87 answered". At time-up (or **Lock**) → **Reveal**: correct answer, distribution bars, KJV reference. Points: Kahoot-style speed points individually, plus team points normalised by team participation (§11.6). The top-5 MVP board follows.
5. **Bible Charades**: the host picks Team Black and enters "#47" (or taps **Random**). Ada's phone vibrates and shows the secret phrase full-screen (with a "hide" toggle). The stage shows Team Black's colour, "Ada O. is acting", a 60 s timer and the words-guessed counter. The host taps **Correct** or **Pass**; points accrue.
6. **Bible Trivia** (captain mode): each team's captain sees the question with submit controls; team-mates tap suggestions, which appear live as a bar chart on the captain's phone; the captain submits. Reveal, then +300 per correct team.
7. **Buzzer games** (Bible Buzzer / Finish the Verse / Emoji Bible): the stage shows the prompt; the buzz opens; the first team to buzz (by clock-synced time, §11.7) flashes on stage with a buzz sound. The team answers aloud and the host judges ✔/✘. On ✘ the buzz reopens for the other teams.
8. **Who Am I?**: clues reveal one by one; teams buzz; points shrink with each clue (500 → 100).
9. **Bible Family Feud**: survey questions were answered by attendees beforehand. The board shows hidden answers. Face-off between two teams' reps (buzzer); the controlling team guesses aloud; the host reveals matches or adds a strike ✘; after three strikes the other team gets one steal guess. Points equal the survey counts × the round multiplier.
10. **Karaoke** (last item): the DJ console shows the queue. The DJ marks **Up next**, so that person's phone vibrates with "You're up next 🎤 — head to the stage". Then **On stage**: the stage display shows the singer, team colour, song and artist, plus the next three. Then **Done**.
11. **Awards**: the host adds a bonus ("Best team spirit +500", audited). The final **leaderboard** reveals the champion team (podium animation) and the MVP.
12. **Recap** scene: thank-you, "Your My Night card is on your phone", next-event teaser.

### 7.9 After the event

1. **Next morning 9:00**: thank-you SMS with a personal link → the recap card (team rank, points, MVP badge, song sung) and a 1-minute survey (NPS, favourite moment, comment, "visit HOD" opt-in).
2. **Insights** tab: funnel, channels, show-up, members vs guests, gender, returning guests, team results, game stats, karaoke, NPS. **PDF report** for the leadership meeting (§18).
3. **Hand-off wizard** (within 72 h): review the summary → rules preview (who goes to Reach or Embrace, who is excluded and why) → per-person overrides → **Push**. Reach HOD and Embrace leaders get notifications. A re-run later picks up late survey opt-ins without duplicating anyone (§17).
4. **Archive** (manager), when finished. The slug can then be re-used by next year's edition; the old edition keeps `chara-2026` as its own slug (§10.2).

---

## 8. Architecture

### 8.1 Context

```
                         ┌────────────────────────── hodlc.lpc.cm (cPanel · LiteSpeed · PHP 8.3 · MySQL) ──────────────────────────┐
                         │                                                                                                          │
 Guests' phones ──HTTPS──┤  /e/<slug>/…     e/index.php  (router + HTML shell, CSP)  ──► assets/se/** (vendored ESM, CSS, SVG, SFX)  │
 (portal, check-in,      │                                                                                                          │
  play, manage)          │  /api/special_events_public_api.php   (JSON; device cookie / manage token)                               │
        │                │                                                                                                          │
        └──poll ~1 s────►│  /live/<public_id>/*.json   (static snapshots, served by LiteSpeed WITHOUT PHP)  ◄── written atomically  │
                         │                                                                                     by se_live_publish() │
 Stage / lobby displays ─┤  /api/special_events_display_api.php  (heartbeat "tick" with stage key)                                  │
                         │                                                                                                          │
 Crew (host, desk, DJ) ──┤  /api/special_events_live_api.php     (ERP session + crew capability + CSRF)                             │
                         │                                                                                                          │
 Studio (ERP) ───────────┤  /modules/special_events/index.php  ► /api/special_events_api.php (ERP session + capability)             │
                         │                                                                                                          │
                         │  includes/special_events/*.php  (domain library)        MySQL: se_* tables (+ read: users, user_departments)│
                         │  cron/special_events.php (every 5 min)                   writes: sms_campaigns/sms_queue (SMS Studio)       │
                         │                                                                   reach_campaigns/reach_leads, users (hand-off)
                         └──────────────────────────────────────────────────────────────────────────────────────────────────────────┘
        External: Gemini API (AI) · bible-api.com (KJV lookup, authoring time only) · BulkSMS (via existing SMS worker)
        Optional later: Ably (push driver)
```

### 8.2 Design principles

1. **The database is the source of truth; snapshots are a cache.** Any snapshot can be rebuilt from MySQL at any time (`se_live_publish($pdo, $eventId, force: true)`).
2. **Short requests only.** No PHP request may wait on a timer or hold a connection open for realtime. Every request finishes in < 1 s, except Studio AI/import calls (≤ 60 s).
3. **Race-free by construction.** Seats, team assignment and player numbers are allocated under one per-event row lock. Uniqueness (one answer per round, one song per singer, unique songs) is enforced by unique indexes, not by "check then insert".
4. **Idempotent everywhere.** A retried request yields the same outcome without duplicates. Natural keys are listed per action in §12.
5. **Privacy by structure.** `public.json` contains no personal names. Names live only in key-protected snapshots. PII (phone, email) never leaves the server except to authorised crew.
6. **Degrade gracefully.** If AI is down, everything still works manually. If SMS is down, the event still runs. If push is down, polling continues. If the network drops at the desk, actions queue locally.
7. **Fit the house.** PHP + PDO with prepared statements, JSON contract `{status, message, data}` (plus `code`), file-per-URL under `api/` and `modules/`, migrations in `db/migrations/`. New technology (Preact etc.) is confined to this module.

### 8.3 Components

| Component | Files | Responsibility |
|---|---|---|
| **Router & shell** | `e/.htaccess`, `e/index.php` | Map `/e/<slug>/<path>` to a surface; resolve slugs (+301 for aliases); 404 for drafts unless crew/preview; render the HTML shell with SEO/OG meta, CSP header + nonce, theme CSS variables, fonts, import map, boot JSON and the surface's entry module; `calendar.ics`; `privacy`. |
| **Public API** | `api/special_events_public_api.php` | Lookup, register, manage, cancel, check-in, device binding, games actions (answer, buzz, suggest), karaoke picks, survey, feedback, beacons, clock sync. |
| **Display API** | `api/special_events_display_api.php` | Stage/lobby bootstrap and the stage **tick** heartbeat. Authenticated by display keys. |
| **Live (crew) API** | `api/special_events_live_api.php` | Host console, game control, desk, karaoke DJ. ERP session + capability + CSRF header. |
| **Studio API** | `api/special_events_api.php` | All configuration, content, AI jobs, assets, crew, messages, attendees, insights, reports, hand-off. |
| **Domain library** | `includes/special_events/*.php` | All business logic. Endpoints are thin: parse → authorise → call library → JSON. |
| **Snapshot publisher** | `includes/special_events/live.php` | Builds and atomically writes `public`, `room`, `team-*`, `lobby` snapshots; debounces; delegates to the realtime driver. |
| **Cron** | `cron/special_events.php` | Scheduled SMS runs, karaoke hold releases, token/rate-limit cleanup, retention, heartbeat record. |
| **Front-end** | `assets/se/**` | Vendored libraries, compiled CSS, ES modules per surface, SVG templates, sound sprites. |
| **Studio page** | `modules/special_events/index.php`, `modules/special_events/how_to_use.md` | ERP layout via `includes/header.php`; mounts the Studio app. |

### 8.4 Key request flows

**Registration (portal):**

```
phone step ─► POST public_api {action:"lookup", purpose:"register"}  ─ rate limit ─► masked identity + needed fields
form step  ─► POST public_api {action:"register", …}
                 BEGIN
                   SELECT … FROM se_events WHERE id=? FOR UPDATE          ← per-event mutex
                   compute registration state from fresh counts            (§10.4)
                   upsert se_contacts by phone_e164
                   insert/reactivate se_registrations (confirmed|waitlisted)
                   issue device token (cookie) + manage token (returned once)
                 COMMIT
                 se_live_publish(public)  (counters / seats-left)          ← debounced
                 threshold notifications (90 %, 100 %) to producers
           ◄── {status:"success", data:{outcome:"confirmed", reg_code, manage_url, …}}
```

**Check-in:**

```
POST public_api {action:"checkin", phone, confirm:true, [walk-in fields]}
  BEGIN; lock event row
    resolve contact → registration (create walk-in if needed, pool rules)
    insert se_checkins (event, registration, day)         ← UNIQUE makes retries safe
    player_no := next_player_no++ (if first time)
    team := se_assign_team() (if teams enabled and none yet)
    verse := least-used approved verse
    karaoke: held → queued (queue_no := next_karaoke_no++)
    bind device
  COMMIT
  se_live_publish(public, room, lobby, team-*)
◄── {team, player_no, verse, karaoke, room_key, team_key}
```

**Live quiz round:**

```
host: POST live_api {action:"round_arm", round_id, preroll_ms:3000, duration_ms:20000, expected_version}
        server: opens_at = now + preroll; closes_at = opens_at + duration; state = armed; version++
        se_live_publish(force)   → public.json now contains the round with arm/open/close times (server clock)
phones: poll public.json (≈1 s) → see armed round → countdown to opens_at using synced clock
        at opens_at (local synced time): reveal question + choices simultaneously everywhere
        tap → POST public_api {action:"answer", round_id, choice_index, client_elapsed_ms}
              server validates window, computes elapsed, inserts se_answers (UNIQUE once), marks snapshot dirty
stage tick (1/s) → publishes dirty snapshot ("87 answered"); at closes_at → state locked (if auto-lock)
host: reveal → scoring → ledger rows (idempotent keys) → publish leaderboard
```

### 8.5 Realtime design

#### 8.5.1 Why polling of static files

On shared hosting, every open connection to PHP holds an "entry process". 150 phones on SSE or long-polling would exceed typical limits and take the whole site down, ERP included. Static files are served by LiteSpeed's event loop without PHP, at thousands of requests per second. Unchanged files return **304 Not Modified** (a few hundred bytes). Polling a small static JSON file once a second from 150 phones is ~150 cheap requests/second, which is well within capacity.

#### 8.5.2 Snapshot files

Directory: `live/<public_id>/` at the docroot. `live/.htaccess` is tracked in git; snapshot files are not (see §23.1).

| File | Readers | Contents | Personal names? |
|---|---|---|---|
| `public.json` | everyone (portal, play, stage, lobby) | phase, registration state, counters, teams (name/label/hex/score/rank), programme now/next + drift, scene, active game & round (public view), announcement, sound cue | **No** |
| `room-<room_key>.json` | checked-in devices (after `join_games`), stage | MVP top-N, karaoke now/next/queue (display names), charades presenter display name, recent highlights | Yes ("Ada O.") |
| `team-<team_key>.json` | members of one team, stage | captain display name, live suggestion counts for captain-mode rounds, team roster count | Yes |
| `lobby-<lobby_key>.json` | lobby display | last 20 arrivals (display name + team), team counts, total checked in | Yes |

Keys are 22-character base64url strings (≈128 bits) generated with `random_bytes(16)`. Rotating a key (Studio → Crew) renames the file and deletes the old one.

Every snapshot has the envelope:

```text
{ "v": 1842, "t": 1792863000123, "e": "k3m9q2x7p1za", "kind": "public", "data": { … } }
```

- `v` is `se_live_state.version` (monotonic per event). Clients ignore any snapshot with `v <= lastV`.
- `t` is the server epoch in ms when the snapshot was built. It is informational; clocks are synced separately (§8.5.4).
- Full schemas and examples: Appendix B.

#### 8.5.3 Polling client

`assets/se/js/core/realtime.js` exports `createPoller({ url, onSnapshot, mode })`:

- Uses `fetch(url, { cache: 'no-cache', credentials: 'omit' })` so the browser revalidates with `If-None-Match` and gets a 304 when nothing changed.
- **Adaptive interval**:

| Situation | Interval |
|---|---|
| A round is `armed`/`open`, a buzz window is open, or charades is running | 1000 ms ± 150 ms jitter |
| Event phase `live`, no active round | 2500 ms ± 300 ms |
| Phase `upcoming` (portal), seats-left visible | 30 s |
| Phase `upcoming`, seats-left hidden | no polling (load once) |
| Page hidden (`document.visibilityState === 'hidden'`) | 10 s |
| After an error | exponential backoff 2 → 4 → 8 → 15 s (cap), "Reconnecting…" pill after 2 failures |

- Optional manual "bump": after the device itself performs an action (e.g. answers), it polls immediately once.
- Snapshots are applied to signals in `core/store.js`. Views re-render reactively.

#### 8.5.4 Clock synchronisation

Players must see the question at the same instant, so every client estimates the server clock offset:

```js
// core/clock.js
const nowMs = () => performance.timeOrigin + performance.now();
async function sample() {
  const t0 = nowMs();
  const r = await fetch('/api/special_events_public_api.php?action=time', { cache: 'no-store' });
  const t1 = nowMs();
  const { data: { server_ms } } = await r.json();
  const rtt = t1 - t0;
  return { offset: server_ms - (t0 + rtt / 2), rtt };
}
// On load: 5 sequential samples; keep the one with the smallest RTT.
// Every 60 s while in /play, /stage, /host: one more sample; keep best of the last 5.
export const serverNow = () => nowMs() + best.offset;
```

`action=time` is the only GET action, does no database work, and returns `{status:"success", data:{server_ms}}` with `Cache-Control: no-store`. Its PHP is `(int) floor(microtime(true) * 1000)`.

#### 8.5.5 Scheduled reveal (fair timing over polling)

A poll interval of ~1 s means phones learn about a new round at different moments. We absorb that by scheduling the reveal in the future:

1. **Arm**: the host's action sets `arm_at = now`, `opens_at = now + preroll` (default **3000 ms**; minimum 2500 ms), `closes_at = opens_at + duration`.
2. Within ≤ ~1.2 s every client has the armed round and shows a **3-2-1 countdown** computed from `serverNow()`.
3. At `opens_at` (by synced clock) every phone and the stage reveal the prompt and choices together.
4. Answers are accepted only when the server time is in `[opens_at, closes_at + 1500 ms grace]`.
5. **Elapsed time** for scoring:
   - `server_elapsed = received_at − opens_at`
   - `elapsed = clamp(client_elapsed_ms, server_elapsed − 2500, server_elapsed)`, then clamped to `[0, duration]`

   We trust the client's own measurement (it removes network latency) but never more than 2.5 s better than what the server observed, and never worse.

The prompt reaches clients up to ~3 s before `opens_at`, so a technically skilled person could read it from the JSON early. The scoring window still starts at `opens_at`, so the only advantage is reading time. This is an accepted risk for a church games night.

#### 8.5.6 Heartbeat ("tick") and debounced publishing

- **Publishing**: `se_live_publish($pdo, $eventId, bool $force = false, array $kinds = ['public','room','team','lobby'])`.
  - If `!$force` and the last publish for this event was < 400 ms ago: set `se_live_state.dirty = 1` and return.
  - Otherwise build the requested snapshots (≈10 small queries), write them atomically, set `dirty = 0` and `last_published_at = NOW(3)`.
  - Answers and buzzes call it with `force = false` (bursty). Host actions call it with `force = true`.
- **Atomic write**: `tempnam($dir, '.tmp')` → `file_put_contents($tmp, $json, LOCK_EX)` → `chmod 0644` → `rename($tmp, $final)`. A rename in the same directory is atomic, so readers never see a partial file.
- **Tick**: the stage display calls `POST /api/special_events_display_api.php {action:"tick", event, stage_key}` every **1000 ms**, and the host console's `console` poll does the same work. A tick:
  1. Acquires `GET_LOCK('se_tick_<event_id>', 0)`; if not acquired, returns immediately (another tick is running).
  2. Runs **time-based transitions**: auto-lock rounds whose `closes_at` has passed; end the charades turn on timeout; settle the buzz winner after the 300 ms fairness window; expire announcements; release karaoke holds after the release time (the cron also does this as a backstop).
  3. If `dirty = 1`, publishes.
  4. Returns `{server_ms, v}`.
- Lazy checks backstop the tick: every answer/buzz validates deadlines itself, so correctness never depends on the tick. Only display freshness does.

#### 8.5.7 Driver interface (push-ready)

```php
// includes/special_events/realtime.php
interface SeRealtimeDriver {
    /** @param string $channel 'public' | 'room-<key>' | 'team-<key>' | 'lobby-<key>' */
    public function publish(string $eventPublicId, string $channel, array $envelope): void;
}
final class SePollDriver implements SeRealtimeDriver { /* atomic file write under live/<id>/<channel>.json */ }
final class SeAblyDriver implements SeRealtimeDriver {
    /* 1) always delegates to SePollDriver (polling stays as fallback)
       2) POST https://rest.ably.io/channels/se:<id>:<channel>/messages  (Basic auth ABLY_API_KEY, 2 s timeout, errors logged and ignored) */
}
function se_realtime_driver(array $event): SeRealtimeDriver { /* env SE_REALTIME_DRIVER, overridable per event in settings.realtime.driver */ }
```

The client side has the same interface: `PollDriver` (default) and `AblyDriver`. The Ably driver subscribes with token auth from `public_api action=rt_token` (subscribe-only capability on that event's channels) and keeps a 15 s safety poll. **v1 ships only the poll driver.** The Ably driver is specified here so it can be added without touching callers.

#### 8.5.8 Load and limits

| Load | Expectation |
|---|---|
| 150 phones polling at 1 Hz | ~150 req/s static, mostly 304 → negligible CPU/bandwidth |
| 150 answers in a 20 s window | ≤ 150 short PHP requests; each ≤ 30 ms DB time |
| Snapshot rebuild | ≤ 20 ms, ≤ 2–3 per second (debounced) |
| Stage tick | 1 req/s (+1 from host console) |

**Primary risk:** the host may throttle per IP (static and dynamic requests per second per client IP). With everyone on guest Wi-Fi behind one public IP, polling plus answer bursts could hit those limits. **Mitigations** (all MUST be done before Chara): ask the hosting provider for the LiteSpeed per-client throttle values and, if needed, request an allow-list for the church's public IP during the event; run the load test from the venue network (§22.4); keep the jitter; and (fallback) ask guests to use mobile data if throttling appears. The driver interface keeps Ably as a later option.

### 8.6 Front-end architecture

#### 8.6.1 Stack (no build step)

| Concern | Choice | Notes |
|---|---|---|
| UI | **Preact 10** + **htm** (JSX-like tagged templates) + **@preact/signals** | ~15 KB gz together. Vendored ESM files under `assets/se/vendor/`. |
| Module loading | Native ES modules + **import map** | `es-module-shims` (vendored, ~15 KB) is loaded only when `HTMLScriptElement.supports('importmap')` is false (old iOS). |
| Styling | **Tailwind CSS v4**, precompiled to `assets/se/css/se.css` (committed) | Source `assets/se/css/se.input.css`. Theme tokens are CSS variables set per event (§13.2). |
| Motion | **GSAP 3** core + ScrollTrigger + SplitText (UMD globals, vendored) | All GSAP plugins are free since 2025; we still vendor exact versions and keep the licence file. |
| Vector animation | `lottie_light` (lazy-loaded only if a Lottie asset is used) | Optional. |
| Confetti | `canvas-confetti` (vendored) | Already used in `register.php`. |
| QR | `qrcode-generator` (vendored) | SVG output for templates and posters. |
| Charts (Studio) | Chart.js 4 UMD (vendored) | Insights tab. |
| Audio | Web Audio API, one sprite file + JSON map | Stage SFX (§13.1.6). |

**Vendoring rules.** Each library lives in `assets/se/vendor/<name>/<file>` with its licence. Every vendored file is listed in `assets/se/vendor/VENDOR.md` (name, exact version, source URL, SHA-256, licence). Libraries MUST NOT be loaded from third-party CDNs at runtime: venue Wi-Fi and CSP both argue against it. The exception is Google Fonts CSS and font files.

**Import map** (emitted by `e/index.php` and `modules/special_events/index.php` with the CSP nonce):

```html
<script type="importmap" nonce="<?= $nonce ?>">
{ "imports": {
    "preact": "/assets/se/vendor/preact/preact.module.js",
    "preact/hooks": "/assets/se/vendor/preact/hooks.module.js",
    "@preact/signals-core": "/assets/se/vendor/preact/signals-core.module.js",
    "@preact/signals": "/assets/se/vendor/preact/signals.module.js",
    "htm": "/assets/se/vendor/htm/htm.module.js",
    "qrcode-generator": "/assets/se/vendor/qrcode/qrcode.mjs",
    "@se/": "/assets/se/js/"
} }
</script>
```

App code imports with `@se/core/api.js`, `@se/portal/views/Hero.js`, etc. A tiny `html` helper is shared: `import { h } from 'preact'; import htm from 'htm'; export const html = htm.bind(h);` (`@se/core/html.js`).

**Caching.** `assets/se/.htaccess` sets `Cache-Control: no-cache` on `*.js`, `*.css`, `*.json`, `*.svg` (revalidate, 304 when unchanged), and `Cache-Control: public, max-age=31536000, immutable` on `vendor/**` (vendor file names contain the version). The shell adds `<link rel="modulepreload">` for each surface's critical modules, from a PHP list in `e/index.php` (`SE_PRELOAD[$surface]`) that MUST be kept in sync with imports. A unit test in `tests/special_events/` checks this (§22.1).

**Bundles per surface.** Entry modules are `@se/portal/main.js` (portal, `/in`, `/play`, `/me`, recap, privacy), `@se/stage/main.js`, `@se/lobby/main.js`, `@se/host/main.js`, `@se/desk/main.js`, `@se/dj/main.js` and `@se/studio/main.js`. Shared code lives in `@se/core/`. Game views load lazily (`import()`) when a game of that type first appears.

**Service worker** (`e/sw.js`, scope `/e/`). Optional; Phase C at the earliest (on the cut line, §25), behind a setting. Cache-first for `vendor/**`, fonts and SFX; stale-while-revalidate for app JS/CSS. **Never** cache `/api/` or `/live/`. Versioned cache name `se-v<build>`, where `<build>` comes from `assets/se/BUILD` (updated by the CSS build script). A kill switch: if `/e/sw-kill` returns 200, unregister.

#### 8.6.2 Client state

`@se/core/store.js` holds signals:

```js
export const boot    = signal(window.__SE_BOOT__);   // server-provided event config (theme, texts, flags, urls)
export const me      = signal(null);                 // from action=me: registration, team, karaoke, alerts
export const live    = signal(null);                 // public.json data
export const room    = signal(null);                 // room snapshot data
export const team    = signal(null);                 // team snapshot data
export const net     = signal({ online: true, lastOkAt: 0, failures: 0 });
export const phase   = computed(() => live.value?.event.phase ?? boot.value.phase);
```

`window.__SE_BOOT__` is read from `<script type="application/json" id="se-boot">` (a non-executable data block, CSP-safe) by `@se/core/boot.js`.

#### 8.6.3 Routing inside the portal

`e/index.php` decides the surface from the path. Within the portal entry, the sub-view comes from the path (`''`, `in`, `play`, `me/<token>`, `privacy`) and `#` fragments (`#recap`, `#feedback`). Navigation between portal views uses `history.pushState` and a minimal router in `@se/portal/routes.js`. Links render as real `<a href>`s, so a hard reload lands on the right view.

#### 8.6.4 Studio front-end

`modules/special_events/index.php` requires `includes/header.php` on line 3 (house rule). It outputs `<div id="se-studio" data-tab="overview"></div>`, the import map and `<script type="module" src="/assets/se/js/studio/main.js">`. The Studio uses **Tailwind classes compiled by the CDN** that `header.php` already loads, so it matches the ERP look. It does not use the precompiled `se.css`. It exposes `window.switchTab(id)` for `tab-deeplink.js`. Modals follow the shared modal contract (`data-app-modal` overlay + `data-modal-panel`); see §13.13.

### 8.7 Repository layout (new and changed files)

```
e/
  .htaccess                         # rewrite /e/<slug>/<path> → e/index.php
  index.php                         # router + HTML shell + calendar.ics + privacy
  sw.js                             # (optional, Phase C+) service worker, scope /e/
live/
  .htaccess                         # no listing; JSON no-cache; deny everything else
  .keep                             # ensures the directory exists after deploy
api/
  special_events_api.php            # Studio
  special_events_public_api.php     # public
  special_events_live_api.php       # crew live ops
  special_events_display_api.php    # stage/lobby
  special_events_export.php         # streams the .xlsx (binary, so not an action)
modules/special_events/
  index.php                         # Studio page (requires header.php on line 3)
  how_to_use.md                     # user guide (CI-enforced, §21)
includes/special_events/
  bootstrap.php                     # require_once of all files below + constants
  constants.php                     # enums, limits, reserved slugs, defaults
  util.php                          # ids, base32, json, hashing, time helpers, se_markdown() renderer, URL builders
  db.php                            # se_table_exists(), se_lock_event(), se_schema_check(), se_audit(), exceptions
  security.php                      # access levels, capabilities, CSRF, rate limits, masking, headers
  settings.php                      # module settings + per-event settings normaliser (Appendix E)
  events.php                        # load/save/publish/archive, phases, days, slugs, clone
  identity.php                      # phone normalisation, contacts, member lookup, devices, tokens
  capacity.php                      # registration state, seat allocation, waitlist, walk-in pools
  registration.php                  # register, cancel, manage, link SMS
  attendees.php                     # the crew's view: list, correct, promote, erase
  portal.php                        # server-rendered Marquee portal (§13.3)
  cards.php                         # share-card payloads and colours (§14.3)
  checkin.php                       # check-in, player numbers, verses, transfer codes, desk ops
  teams.php                         # assignment algorithm, moves, naming, captains
  program.php                       # items, ETA engine, AI import apply
  karaoke.php                       # library, holds, queue, DJ ops
  games/engine.php                  # rounds state machine, common validation
  games/quiz.php  games/trivia.php  games/charades.php  games/buzzer.php  games/who_am_i.php  games/feud.php
  scoring.php                       # ledger, formulas, leaderboards, MVP
  live.php                          # live state, scenes, snapshot builders, publish, tick
  realtime.php                      # driver interface + poll/ably drivers
  theme.php                         # colour maths (OKLCH), palette derivation, contrast, team colours
  ai.php                            # se_ai(): Gemini client, schemas, validation, usage logging
  prompts/*.md                      # versioned prompt templates (Appendix D)
  bible.php                         # KJV lookup + cache
  assets.php                        # uploads, magic-byte sniffing, image variants, SVG sanitiser
  messages.php                      # SMS runs (enqueue via SMS Studio tables)
  handoff.php                       # Reach/Embrace push
  analytics.php                     # metrics, insights queries
  report_pdf.php                    # dompdf report
  export.php                        # PhpSpreadsheet exports
cron/
  special_events.php                # every 5 minutes
assets/se/
  .htaccess                         # cache headers; deny *.php
  BUILD                             # build stamp (written by the CSS build script)
  vendor/…  VENDOR.md
  css/se.input.css  css/se.css
  js/core/…  js/portal/…  js/stage/…  js/lobby/…  js/host/…  js/desk/…  js/dj/…  js/studio/…
  templates/*.svg                   # format studio + share card templates
  sfx/se-sfx.mp3  sfx/se-sfx.json   # sound sprite + map
  img/…                             # default illustrations, team badge shapes
db/migrations/
  20261006090000_se_core.sql …      # see §9.4
tests/special_events/
  run.php                           # CLI unit tests (no DB)
  …_test.php
  js/*.test.mjs                     # node --test unit tests (no DB)
  fixtures/phones.json              # the phone vectors PHP and JS both read
  db_setup.php  smoke_seed.php      # build a disposable MySQL + a /e/smoke event
  dev_router.php                    # serves /e/ under `php -S`
  integration/run.php               # §22.2, needs a real MySQL
  load/quiz.k6.js                   # load test script
docs/
  engineering_guide.md              # this file
  build_prompts.md                  # one build prompt per pull request (§28)
```

Existing files changed: see §21.

### 8.8 URL routing details

`e/.htaccess`:

```apache
# /e/ — Special Events public router. Real files (index.php, sw.js) pass through.
Options -Indexes
DirectoryIndex index.php
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /e/
  RewriteCond %{REQUEST_FILENAME} -f
  RewriteRule ^ - [L]
  # /e/<slug>            → index.php?__slug=<slug>&__path=
  # /e/<slug>/<rest…>    → index.php?__slug=<slug>&__path=<rest>
  RewriteRule ^([A-Za-z0-9][A-Za-z0-9-]{0,39})/?$ index.php?__slug=$1&__path= [QSA,L]
  RewriteRule ^([A-Za-z0-9][A-Za-z0-9-]{0,39})/(.+)$ index.php?__slug=$1&__path=$2 [QSA,L]
</IfModule>
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

`e/index.php` logic:

1. `__slug` → lowercase. If it differs from the request (uppercase typed), **301** to the lowercase URL.
2. Look up `se_slugs`. Not found → **404 page** (branded, link to `/e/`). Alias (`is_canonical = 0`) → **301** to `/e/<canonical>/<path>`, preserving the query string.
3. Load the event. If `status = 'draft'` and the viewer is neither crew nor holding a valid `?preview=<preview_key>`, return 404 (not 403, so drafts can't be enumerated).
4. Route `__path`:

| `__path` | Surface | Extra checks |
|---|---|---|
| `` (empty) | portal | — |
| `in` | portal (check-in view) | — (the phase gate is in the UI and API) |
| `play` | portal (games view) | — (device checked by API) |
| `me/<token>` | portal (manage view) | token format `^[A-Za-z0-9_-]{22}$` else 404 |
| `privacy` | portal (privacy view, server-rendered Markdown) | — |
| `calendar.ics` | ICS download | published only |
| `stage`, `lobby` | display entries | keys validated by the display API |
| `host`, `desk`, `dj` | crew entries | ERP session required, else redirect `/auth/login.php?next=<url>` (§21.4); capability checked by the live API |
| anything else | 404 | — |

5. Render the shell: `<!doctype html>`, `<html lang="en-NG" data-theme="marquee">`, meta (title, description, canonical, OG/Twitter with the OG asset or hero fallback; `noindex` for everything except a published public portal), CSP header, fonts, `se.css`, the theme `<style nonce>` with CSS variables, the import map, `<script type="application/json" id="se-boot">`, server-rendered **first-paint hero** (title, tagline, date, CTA link — real HTML, so the LCP is text and works without JS), `<noscript>` notice, and the entry module.

**Hub `/e/`.** `e/index.php` with no slug lists published `public` events (upcoming first, then the last 6 past events with recap links). If none, it shows a friendly empty state linking to the main site.

---

## 9. Data model

### 9.1 Conventions

- **Engine/charset**: InnoDB, `utf8mb4`, `utf8mb4_unicode_ci` (same as the Reach/Assimilation migrations).
- **Keys**: `INT UNSIGNED AUTO_INCREMENT` primary keys. References to existing tables (`users.id`) are `INT UNSIGNED` **without** foreign keys, matching house style and avoiding type-mismatch failures on production. **Within** the module, `event_id` columns have `FOREIGN KEY … REFERENCES se_events(id) ON DELETE CASCADE`. Only draft events can be deleted, and their children go with them. `contact_id` references `se_contacts(id)` with `ON DELETE RESTRICT`. This is a deliberate, module-local deviation for integrity, documented here.
- **Times**: `DATETIME` in WAT. Live game timestamps use `DATETIME(3)` (milliseconds) written from PHP (`(new DateTimeImmutable())->format('Y-m-d H:i:s.v')`) or SQL `NOW(3)`.
- **JSON**: stored in `TEXT`/`MEDIUMTEXT` columns named `*_json`, validated and normalised in PHP. No database JSON functions, for MySQL/MariaDB portability.
- **Enumerations**: `ENUM` only for stable lifecycle states. Extensible vocabularies (game types, programme kinds, asset roles, crew roles…) are `VARCHAR` checked against PHP constants in `includes/special_events/constants.php`, so new values need no migration.
- **Soft vs hard delete**: registrations, crew, assets, score entries and karaoke entries are never hard-deleted while the event is not a draft (status changes, `revoked_at`, `deleted_at`, `voided_at`). Check-ins are the only rows hard-deleted in normal operation (undo), and each undo writes an audit entry with the deleted row. The one other exception is **Reset rehearsal**, which deletes rows flagged `is_test = 1` (§11.13).
- **Secrets**: tokens, device secrets and keys are never stored in plain text where they grant access, except display keys and team keys, which double as snapshot file names. Access tokens are stored as `HMAC-SHA256(SE_HASH_PEPPER, token)`; IPs and user agents only as HMACs.
- **Naming**: tables `se_<plural>`; booleans `is_*`/`*_enabled` `TINYINT(1)`; timestamps `*_at`; actor columns `*_by` (users.id).

### 9.2 Entity-relationship overview

```
se_series 1──* se_events ─────────────────────────────────────────────────────────────────────┐
                 │ 1──* se_slugs              (aliases & history)                              │
                 │ 1──* se_event_days         (one per day; check-in windows)                  │
                 │ 1──* se_form_fields        (custom questions)                               │
                 │ 1──* se_crew  *──1 users   (per-event roles)                                │
                 │ 1──* se_assets             (brand kit, renders, sources)                    │
                 │ 1──* se_teams                                                               │
                 │ 1──* se_registrations *──1 se_contacts ──(member_user_id)──► users           │
                 │          │ 1──* se_checkins (per day)                                       │
                 │          │ 1──* se_devices  1──* se_access_tokens                           │
                 │          │ *──1 se_teams (team_id)                                          │
                 │          │ 1──* se_karaoke_entries *──1 se_songs ◄──* se_event_songs ──*1 se_events
                 │          │ 1──* se_answers / se_buzzes / se_survey_responses                │
                 │          │ 0..1 se_feedback                                                 │
                 │ 1──* se_program_items ──(game_id)──► se_games                               │
                 │ 1──* se_games 1──* se_rounds 1──* se_answers                                │
                 │          └──* se_game_items *──1 se_deck_items *──1 se_decks (library/event)│
                 │ 1──* se_feud_answers (boards per survey item)                               │
                 │ 1──* se_score_events (ledger)                                               │
                 │ 1──1 se_live_state  (scene, keys, version)                                  │
                 │ 1──* se_event_verses (welcome card verses)                                  │
                 │ 1──* se_message_runs ──(sms_campaign_id)──► sms_campaigns                   │
                 │ 1──* se_handoffs 1──* se_handoff_items ──► reach_leads | users              │
                 │ 1──* se_feedback, se_metrics_daily, se_ai_jobs, se_audit_log                │
                 └──────────────────────────────────────────────────────────────────────────────┘
Global: se_settings · se_rate_limits · se_ai_requests · se_bible_cache · se_songs · se_decks(library)
```

### 9.3 Tables

The exact DDL is in **Appendix A**. This section explains the purpose of each table and the rules that are not obvious from the columns.

#### Core

| Table | Purpose | Notes |
|---|---|---|
| `se_settings` | Module-wide key/value settings | Keys in §20.2. Like `reach_settings`. |
| `se_series` | Optional grouping of editions ("Chara") | Clone defaults to the latest event of the same series; insights compare editions. |
| `se_events` | One special event | Holds identity (`public_id`, `slug`), content, venue, overall `starts_at`/`ends_at` (first day start → last day end), lifecycle `status`, visibility, theme and brand colours, **capacity columns** (hot path), counters (`next_player_no`, `next_karaoke_no`, `team_rr_pointer`) and `settings_json` (Appendix E). `row_version` gives optimistic concurrency for Studio saves: every UPDATE sets `row_version = row_version + 1 WHERE row_version = :expected`, and 0 affected rows → `STALE_VERSION`. |
| `se_slugs` | Every slug that ever pointed to an event | Exactly one `is_canonical = 1` row per event, equal to `se_events.slug`. Old slugs stay as aliases (301), so printed QR codes never break. |
| `se_event_days` | One row per event day | `doors_open_at`, `starts_at`, `ends_at`, `checkin_closes_at`. Single-day events have exactly one row. `day_date = DATE(starts_at)`. |

#### People and registration

| Table | Purpose | Notes |
|---|---|---|
| `se_contacts` | A person across events, keyed by `phone_e164` | Holds the latest name/email/gender given, the `member_user_id` link (when the phone matches `users`), consent state and erasure marker. One row per phone, **unique**. |
| `se_registrations` | A contact's place at one event | `status` ∈ `confirmed`, `waitlisted`, `cancelled`, `removed`; `seat_pool` ∈ `online`, `walkin` (NULL unless confirmed); `channel` (how it was created). Snapshot of the name/gender/email as given **for this event**, plus `display_name` ("Ada O."), `reg_code` (8 chars), `ref_code` (6 chars), team, player number, karaoke interest, wants-visit, answers to custom questions, source/referral, timestamps for each state change. **UNIQUE(event_id, contact_id)**: re-registering after a cancel reactivates the same row. |
| `se_access_tokens` | Manage links and transfer codes | Stored as HMAC. `purpose` ∈ `manage` (22-char token, expires 30 days after the event), `transfer` (6-digit code, expires in 10 min, single use). |
| `se_devices` | Browser ↔ registration bindings | HMAC of the device cookie; `mode` ∈ `full`, `readonly`; `joined_games_at` (games portal participation); `last_seen_at` (updated at most once per minute). A device belongs to one event. |
| `se_form_fields` | Custom registration questions | Stable `field_key` used in `se_registrations.answers_json`; `audience` ∈ `everyone`, `guests`, `members`. |

#### Day of the event

| Table | Purpose | Notes |
|---|---|---|
| `se_teams` | Teams of an event | `color_hex` (`#RRGGBB`, uppercase), `color_label`, `name` (crew-entered on the night), `captain_registration_id`, `team_key` (secret for its snapshot file). **The team count cannot change once any registration has a team** (§10.6.5). |
| `se_checkins` | One row per person per event day | **UNIQUE(event_id, registration_id, day_date)**: idempotent check-in. Records method, walk-in flag, the verse given, device, crew actor. |
| `se_team_moves` | History of team assignments | `method` ∈ `auto`, `crew`. Includes the first automatic assignment. |
| `se_event_verses` | Welcome-card verses for the event | KJV reference + text (fetched, never typed by AI) + optional prayer line template with `{name}`. Must be `approved` to be used. |
| `se_bible_cache` | Cache of looked-up verses | Key `(translation, ref_norm)`. |

#### Programme and karaoke

| Table | Purpose | Notes |
|---|---|---|
| `se_program_items` | Run-of-show items per day | `planned_start_at` NULL means "right after the previous item". `status`, `started_at`, `ended_at` drive the live ETA engine (§10.7). `is_featured` items become portal chapters. Optional link to a game. |
| `se_songs` | Global karaoke library | Normalised `title_norm`/`artist_norm` unique together, so a song is entered once and reused across events. |
| `se_event_songs` | Which songs an event offers | Activate/deactivate per event; order. |
| `se_karaoke_entries` | A singer's claim/queue entry | `status` lifecycle (§10.8). Two **generated columns** enforce the rules race-free: `active_song_key` (unique songs per event, only when `enforce_unique = 1`) and `active_singer_key` (one active entry per person). `queue_no` unique per event. |

#### Games and scoring

| Table | Purpose | Notes |
|---|---|---|
| `se_decks` | A set of content items | `scope` ∈ `library` (reusable across events) or `event`; `content_type` (`mcq`, `open`, `charade`, `clues`, `emoji`, `verse`, `survey`). |
| `se_deck_items` | One question/word/clue set | `payload_json` per content type (Appendix C), scripture reference + fetched text, difficulty, `review_status` (`draft`, `approved`, `rejected`); usage counters to avoid repeating questions across editions. |
| `se_games` | A game in an event | `type` (`live_quiz`, `trivia`, `charades`, `buzzer`, `who_am_i`, `feud`), `settings_json` (timers, points), `weight`, `status`. |
| `se_game_items` | Ordered items a game will use | Only `approved` items may be added. |
| `se_rounds` | One question/turn | State machine (§11.2), clock fields in ms, `eligible_json` (team participation at arm time), `state_json` (game-specific progress such as Feud reveals/strikes or Who-Am-I clue index). |
| `se_answers` | Player/captain/suggestion submissions | **UNIQUE(round_id, registration_id, role)**: one answer per person per round. Suggestions use `role = 'suggestion'` and are updated in place (upsert). |
| `se_buzzes` | Buzzer presses | **UNIQUE(round_id, attempt, team_id)**: the first press per team per attempt. `effective_ms` (validated epoch ms) decides order. |
| `se_survey_responses` | Family Feud survey answers from attendees | **UNIQUE(event_id, deck_item_id, registration_id)**. |
| `se_feud_answers` | The board for a survey question | Built from clustered responses (or manual/AI fallback), must be `approved` to play. |
| `se_score_events` | **Score ledger** | `scope` ∈ `team`, `individual`. Team total = Σ team-scope points; MVP = Σ individual-scope points; voided rows excluded. Auto-scoring uses `idempotency_key` (UNIQUE per event), so a round can never be scored twice. |
| `se_live_state` | Live control state per event | Current scene + payload, active game/round, announcement, sound cue sequence, `version` (monotonic), `dirty`, `last_published_at`, and the three display keys (`room_key`, `lobby_key`, `stage_key`). The draft-preview key lives on `se_events.preview_key` (needed from Phase A). |

#### Operations

| Table | Purpose | Notes |
|---|---|---|
| `se_crew` | Per-event roles | UNIQUE(event_id, user_id, role); revoke = `revoked_at`. |
| `se_assets` | Uploaded and rendered files | `kind`, `role`, web `path` under `/uploads/se/<public_id>/`, detected MIME, dimensions, `sha256`, `variants_json` (responsive images, video poster), `meta_json`. Soft-deleted. |
| `se_message_runs` | Each scheduled/sent SMS run | UNIQUE(event_id, run_key) gives idempotency (`reminder_1`, `reminder_2`, `thank_you`, `waitlist:<reg_id>:<n>`, `link:<reg_id>:<yyyymmddhh>`). Links to `sms_campaigns.id`. |
| `se_feedback` | Post-event survey | One per registration per event. |
| `se_handoffs`, `se_handoff_items` | Hand-off batches and per-person outcomes | A generated column makes "successfully handed off" unique per contact per event; skipped rows do not block a later push (§17). |
| `se_ai_jobs` | AI results awaiting review/apply | Programme import, song import, deck generation, palettes, feud clustering, copy, verses, report summary. Inputs contain **no PII**. |
| `se_ai_requests` | AI usage log | Model, prompt version, tokens, latency, outcome; used for rate limits and cost. |
| `se_audit_log` | Append-only audit trail | Every crew/Studio mutation and every public action that changes state (`register`, `cancel`, `checkin`). |
| `se_rate_limits` | Fixed-window counters | `(bucket, subject_hash, window_start)` → hits. |
| `se_metrics_daily` | Privacy-friendly counters | Views, registration starts/completions, shares, card downloads, by day and dimension (e.g. source code). |

### 9.4 Migrations and phasing

Migrations ship **with the phase that first needs them** (§25). The timestamps below are placeholders that sort after the newest existing migration (`20261005090000_security_core.sql`). At merge time, use the real time but keep the relative order. Never edit a migration that production has applied (AGENTS.md).

| Phase | File | Creates |
|---|---|---|
| A (registration) | `20261006090000_se_core.sql` | `se_settings`, `se_series`, `se_events`, `se_slugs`, `se_event_days` |
| A | `20261006090100_se_people.sql` | `se_contacts`, `se_registrations`, `se_access_tokens`, `se_devices`, `se_form_fields` |
| A | `20261006090200_se_ops.sql` | `se_crew`, `se_assets`, `se_audit_log`, `se_rate_limits`, `se_metrics_daily`, `se_ai_jobs`, `se_ai_requests`, `se_message_runs` (waitlist promotions and on-demand links already send SMS in Phase A) |
| A | `20261006090300_se_seed_settings.sql` | default rows in `se_settings` |
| B (check-in & live) | `20261013090000_se_checkin_teams.sql` | `se_teams`, `se_checkins`, `se_team_moves`, `se_event_verses`, `se_bible_cache` |
| B | `20261013090100_se_program_karaoke.sql` | `se_program_items`, `se_songs`, `se_event_songs`, `se_karaoke_entries` |
| B | `20261013090200_se_live_state.sql` | `se_live_state` |
| C (games) | `20261020090000_se_games.sql` | `se_decks`, `se_deck_items`, `se_games`, `se_game_items`, `se_rounds`, `se_answers`, `se_buzzes`, `se_survey_responses`, `se_feud_answers`, `se_score_events` |
| D (after) | `20261027090000_se_post_event.sql` | `se_feedback`, `se_handoffs`, `se_handoff_items` |
| D | `20261027090100_se_reach_campaign_type.sql` | `INSERT … ON DUPLICATE KEY UPDATE` of Reach campaign type `Special_Event` |

**Rollout safety (AGENTS.md: code is copied before migrations run).** Every library function that touches a table from a later phase MUST degrade safely if the table does not exist yet. Use `se_table_exists($pdo, 'se_rounds')` (cached per request, like `sms_table_exists()`), and return an empty result or a `FEATURE_NOT_READY` error, never a fatal error. The Studio hides tabs whose tables are missing.

### 9.5 Integrity and lifecycle rules

1. **Deleting**: only `draft` events can be deleted (manager only). `se_event_delete_draft()` runs one transaction: first `DELETE FROM se_karaoke_entries WHERE event_id = ?`, then `DELETE FROM se_events WHERE id = ?`, and the cascade removes the remaining children. The karaoke rows go first because `fk_se_karaoke_reg` is `ON DELETE RESTRICT` (MySQL 8 forbids `CASCADE` on a column that feeds a stored generated column, Appendix A), so the result never depends on the order in which InnoDB processes the cascades. Contacts are never deleted by an event deletion. Any other code that hard-deletes registrations (only **Reset rehearsal**, §11.13) also deletes their karaoke entries first.
2. **Archiving**: freezes the event (all writes refused with `EVENT_ARCHIVED`, except the hand-off and exports). It may release the canonical slug (§10.2 item 4).
3. **Erasure** (right to be forgotten, §19.8): `se_contacts` and every `se_registrations` row of the contact are anonymised in place (names → "Erased", phone → `erased:<id>`, email NULL, answers NULL, display name "Guest"). Statistics survive; the audit records the erasure.
4. **Counters**: `next_player_no` and `next_karaoke_no` only increase. They are read and incremented under the event row lock, so numbers are never reused, even after an undo.
5. **One canonical slug per event** and **one event per canonical slug**: enforced by `UNIQUE(se_events.slug)` plus `se_slugs` primary key and transactional updates in `se_slug_change()`.

---

## 10. Domain logic

All functions below live in `includes/special_events/` and take `PDO $pdo` first. Pseudocode is PHP-flavoured; the names are normative (other code and tests refer to them).

### 10.1 Event lifecycle and phases

**Status** (stored, changed by people):

```
           publish (checklist green)                 archive (manager)
  draft ───────────────────────────► published ─────────────────────────► archived
    ▲  unpublish (only if 0 registrations)  │  cancel (producer, with reason)
    └───────────────────────────────────────┤──────────────► cancelled ──► archived
```

- **Unpublish** is allowed only while the event has no registrations. Otherwise the Producer uses **Pause registration** (`reg_override = force_closed`).
- **Cancel** shows a cancellation banner on the portal (with the reason text), closes registration and check-in, and offers an ad-hoc SMS to registrants (§16.6).
- **Archive** (manager only, at least 24 h after the event) freezes everything except exports, insights and the hand-off.

**Phase** (computed, never stored) — `se_event_phase(array $event, array $days, DateTimeImmutable $now): array`:

```php
if ($event['status'] === 'draft')     return ['phase' => 'draft'];
if ($event['status'] === 'cancelled') return ['phase' => 'cancelled'];
if ($event['status'] === 'archived')  return ['phase' => 'archived'];
$first = $days[0]; $last = end($days);
if ($now < $first['doors_open_at'])   return ['phase' => 'upcoming', 'next_day' => $first];
foreach ($days as $d) {
    $liveEnd = max($d['ends_at'], $d['checkin_closes_at']);
    if ($now >= $d['doors_open_at'] && $now <= $liveEnd) {
        return ['phase' => 'live', 'day' => $d,
                'checkin_open' => $now <= $d['checkin_closes_at']];
    }
}
if ($now > max($last['ends_at'], $last['checkin_closes_at'])) return ['phase' => 'post'];
return ['phase' => 'between_days', 'next_day' => /* first day with doors_open_at > now */];
```

Every returned array also carries `first_day` and `last_day` (the first and last `se_event_days` rows), which other functions use (e.g. the default registration deadline).

Defaults when a day is created: `doors_open_at = starts_at − settings.checkin.opens_minutes_before (60)`, `checkin_closes_at = ends_at`. The Producer can edit both.

**Portal behaviour by phase:**

| Phase | Hero CTA | Other |
|---|---|---|
| `upcoming` | **Register** (or the registration state message) | Countdown, seats-left (if visible) |
| `live` (check-in open) | **Check in** (→ `/in`); **Join the games** if this device is checked in | Live "Now / Next" programme |
| `live` (check-in closed) | **Join the games** (checked-in devices) | "Check-in has closed — see the desk" |
| `between_days` | "See you tomorrow" + next day's time | Day recap teaser |
| `post` | **Relive the night** (recap: champions, highlights) | Feedback link for registered devices |
| `cancelled` | Cancellation notice | — |
| `archived` | Recap (if `settings.recap.public` is on) or a simple "This event has ended" | — |

### 10.2 Slugs

1. **Format**: `/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/` (1–40 chars, lowercase letters/digits/hyphens, no leading or trailing hyphen). Input is lowercased and trimmed; spaces become hyphens; other characters are rejected (not silently dropped), with a suggestion shown.
2. **Reserved** (`SE_RESERVED_SLUGS`): `admin api assets auth calendar desk dj e edit hub host in index live lobby login me modules new null play preview privacy stage static studio sw sw-kill test undefined uploads`.
3. **Availability**: a slug is available if it is not in `se_slugs`. The Studio checks this live (`check_slug`) and again at save time inside the transaction (UNIQUE keys are the final guard).
4. **Reclaiming** a slug held by an **archived** event (typical for annual series: next year's Chara wants `chara`). Allowed for managers, or for the Producer when both events are in the same series:
   - In one transaction: generate `<slug>-<edition_label or YYYY of starts_at>` (append `-2`, `-3`… until free) for the old event; set it as the old event's canonical (insert `se_slugs`, update `se_events.slug`); re-point the `se_slugs` row for the reclaimed slug to the new event with `is_canonical = 1`; update the new event's `slug`.
   - Effect: printed posters for `chara` now open the newest Chara (desired for a periodic event), and last year's edition lives at `/e/chara-2026`.
5. **Renaming** a published event's slug: the new slug becomes canonical and the old one stays as an alias (301). The Studio warns: "Printed QR codes keep working (they redirect)."

### 10.3 Identity: phones, contacts, members, devices, tokens

#### 10.3.1 Phone normalisation

`se_phone_normalize(string $raw): ?array` returns `['e164' => '2348031234567', 'sms' => true, 'display' => '+234 803 123 4567']`, or `null` if invalid.

1. Keep digits and a leading `+`.
2. Try `sms_normalize_phone($raw)` (existing, Nigerian mobiles: `0803…`, `803…`, `234803…`, `+234 (0) 803…`, `00234…`). If it returns a number → `sms = true`.
3. Otherwise, if the input began with `+` or `00` and has 8–15 digits → international; `e164` = those digits; `sms = false` (BulkSMS route is Nigerian).
4. Otherwise → `null` → error `INVALID_PHONE` ("Please enter a mobile number, e.g. 0803 123 4567").

The client mirrors this in `@se/core/phone.js` (same test vectors, §22.1) for instant feedback, but the server decides.

#### 10.3.2 Member recognition

`se_find_member(PDO $pdo, string $e164): ?array` — Nigerian numbers only:

```sql
-- 1) exact forms (fast)
SELECT id, first_name, last_name, gender, email, real_email
  FROM users WHERE phone IN (:e164, CONCAT('+', :e164), CONCAT('0', SUBSTRING(:e164, 4))) LIMIT 2;
-- 2) fallback: same last 10 digits, ignoring spaces/dashes/plus/brackets
SELECT id, first_name, last_name, gender, email, real_email FROM users
 WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),')',''), 10) = :last10
 LIMIT 2;
```

- If exactly one user matches → member. If two or more → treat as member using the first row and set `member_ambiguous` (shown in the Studio for IDI review). Never block the person.
- The result is cached on the contact (`member_user_id`, `member_checked_at`) and background paths refresh it if older than 24 h. The interactive phone-first lookup always checks `users` immediately—even when no `se_contacts` row exists—so a first-time Special Events visitor or newly added Congregation member is recognised at once.
- The module **never writes to `users`** (D2). Name corrections from members are stored in `se_registrations.name_correction` and listed in the Studio for IDI to apply manually.

#### 10.3.3 Contacts

- `se_contact_get_or_create($pdo, $phone, array $fields)`: `INSERT … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` on `phone_e164`. For a **new** contact the given fields are stored. For an **existing** contact, public flows MUST NOT overwrite `first_name`/`last_name`. They may fill empty `email`/`gender`. The registration row stores what the person typed for this event.
- Consent is updated only upward by the person (tick) or downward by opt-out (§19.8).

#### 10.3.4 Display names

`se_display_name(first, last) = trim(ucfirst(first)) . (last !== '' ? ' ' . mb_strtoupper(mb_substr(trim(last), 0, 1)) . '.' : '')`. Max 40 chars. This is the only form of a person's name that may appear on screens, in snapshots or in lookups (D25).

#### 10.3.5 Devices

- Cookie `se_dev_<public_id>`: `random_bytes(32)` base64url; `HttpOnly; Secure; SameSite=Lax; Path=/; Expires = last day ends_at + 30 days`. Stored as `HMAC-SHA256(SE_HASH_PEPPER, value)` in `se_devices.token_hash`.
- A device row is created lazily on the first state-changing public action. It is **bound** to a registration by: successful `register` (new registration), self check-in (first check-in of that registration *today*), opening a manage link (`claim_link`), or entering a desk transfer code.
- **One identity per device.** A device's identity is the first registration bound to it. Registering or checking in **another person's phone number** from a device that already has an identity (e.g. a mother registering her son, or checking in a friend whose battery died) does **not** rebind it. The new registration's manage link is shown on screen ("Send this link to Tobi") and the check-in result (team, number, card) is displayed for that person. The UI labels this flow **"Register / check in someone else"**. The device's own identity changes only via a manage link, a transfer code, or Studio/desk action.
- Self check-in by phone binds the device (if it has no identity yet) unless that registration already has a check-in **today** made from another device. In that case the new device is bound `readonly` and the response says so (§7.7). This is the defence against hijacking by phone number.
- `last_seen_at` is touched at most once a minute (`UPDATE … WHERE last_seen_at < NOW() - INTERVAL 1 MINUTE`).

#### 10.3.6 Tokens

| Purpose | Format | Lifetime | Use |
|---|---|---|---|
| `manage` | 16 random bytes → 22-char base64url (128 bits; short enough to keep SMS to one page) | until last day `ends_at` + 30 days | `/e/<slug>/me/<token>`; issued at registration (returned once), per recipient in reminder/thank-you SMS, and by "Text me my link" |
| `transfer` | 6 random digits | 10 minutes, single use | Desk → new phone (§7.6). Lookup is scoped to the event and rate-limited (5 attempts / 10 min / device). |

Lookup: `SELECT … FROM se_access_tokens WHERE token_hash = HMAC(token) AND revoked_at IS NULL AND expires_at > NOW()`. On success, update `last_used_at`. Studio "Reset links" revokes all tokens of a registration.

### 10.4 Capacity engine

#### 10.4.1 Definitions

All counts are computed **inside the event-row lock** (`SELECT … FROM se_events WHERE id = ? FOR UPDATE`).

| Name | SQL meaning |
|---|---|
| `online_taken` | `COUNT(*) FROM se_registrations WHERE event_id = ? AND status = 'confirmed' AND seat_pool = 'online'` |
| `walkin_taken` | same with `seat_pool = 'walkin'` |
| `waitlisted` | `COUNT(*) … status = 'waitlisted'` |
| `online_free` | `online_capacity IS NULL ? ∞ : max(0, online_capacity − online_taken)` |
| `walkin_free` | `walkin_capacity IS NULL ? ∞ : max(0, walkin_capacity − walkin_taken)` |

#### 10.4.2 Registration state

`se_registration_state(array $event, array $phaseInfo, array $counts, DateTimeImmutable $now): string`

```php
if (!in_array($phaseInfo['phase'], ['upcoming'], true))       return 'closed';          // incl. live: walk-ins go through check-in
if ($event['reg_override'] === 'force_closed')                return 'closed_manual';
if ($event['reg_opens_at'] && $now < $event['reg_opens_at'])  return 'not_open_yet';
$closeAt = $event['reg_closes_at'] ?? $phaseInfo['first_day']['starts_at'];   // first event day
if ($now >= $closeAt)                                         return 'closed_deadline';
if ($event['reg_override'] === 'force_open')                  return 'open';
if ($event['online_capacity'] === null)                       return 'open';
if ($counts['online_taken'] < $event['online_capacity'])      return 'open';
if (!$event['auto_close_at_capacity'])                        return 'open';           // soft cap (over-capacity flagged in Studio)
if ($event['waitlist_enabled'] && ($event['waitlist_capacity'] === null || $counts['waitlisted'] < $event['waitlist_capacity']))
                                                              return 'waitlist';
return 'full';
```

The public snapshot exposes `reg.state` and, when visible (§10.4.8), `seats_left`.

#### 10.4.3 Register (atomic)

```php
function se_register(PDO $pdo, array $event, array $in, array $ctx): array {
    $pdo->beginTransaction();
    try {
        $ev = se_lock_event($pdo, $event['id']);                       // SELECT … FOR UPDATE
        $counts = se_capacity_counts($pdo, $ev['id']);
        $state  = se_registration_state($ev, se_event_phase(...), $counts, $ctx['now']);
        if (!in_array($state, ['open', 'waitlist'], true)) { $pdo->rollBack(); return ['outcome' => 'closed', 'state' => $state]; }

        $contact = se_contact_get_or_create($pdo, $in['phone'], $in);    // §10.3.3
        $reg = se_find_registration_for_update($pdo, $ev['id'], $contact['id']);
        if ($reg && in_array($reg['status'], ['confirmed', 'waitlisted'], true)) {
            $pdo->commit();
            return ['outcome' => 'already', 'registration' => $reg,
                    'device_owns_it' => se_device_owns($ctx['device'], $reg)];   // token only if the device already owns it
        }
        if ($reg && $reg['status'] === 'removed') { $pdo->rollBack(); return ['outcome' => 'blocked']; } // crew removed them

        if ($state === 'open') { $status = 'confirmed'; $pool = 'online'; }
        else                   { $status = 'waitlisted'; $pool = null; }

        $reg = $reg ? se_reactivate_registration($pdo, $reg, $status, $pool, $in)
                    : se_insert_registration($pdo, $ev, $contact, $status, $pool, $in, $ctx);
        $device = se_device_bind_if_unbound($pdo, $ev, $ctx['device_cookie'], $reg['id'], 'full');   // §10.3.5: never rebinds an existing identity
        [$token, $tokenRow] = se_token_issue($pdo, $reg['id'], 'manage', se_token_expiry($ev));
        se_audit($pdo, $ev['id'], 'register', ['registration_id' => $reg['id'], 'status' => $status], $ctx);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

    se_after_capacity_change($pdo, $ev);   // publish public snapshot (debounced) + threshold notifications
    return ['outcome' => $status, 'registration' => $reg, 'manage_token' => $token, 'waitlist_position' => …];
}
```

- `waitlist_position` = `1 + COUNT(*) WHERE status='waitlisted' AND (waitlisted_at, id) < (mine)`.
- `se_insert_registration` generates a `reg_code` (8 chars, Crockford base32 without `I L O U`) and a `ref_code` (6 chars). It retries on duplicate key (≤ 5 attempts).
- **Threshold notifications** (producers + managers, `system_notifications`, once each per event, recorded in `se_audit_log` as `notice_sent:<kind>`): `capacity_90`, `capacity_full`, `waitlist_started`.

#### 10.4.4 Cancel (self or crew)

```php
lock event; $reg = FOR UPDATE;
if (!in_array($reg['status'], ['confirmed', 'waitlisted'])) return 'noop';
if ($actor === 'self' && !$event['self_cancel_enabled'])     return error SELF_CANCEL_DISABLED;
if ($actor === 'self' && $phase !== 'upcoming')               return error TOO_LATE;   // on the day, cancellations go through the desk
$wasOnline = $reg['status'] === 'confirmed' && $reg['seat_pool'] === 'online';
UPDATE se_registrations SET status='cancelled', seat_pool=NULL, cancelled_at=NOW(), cancelled_by=?, cancel_reason=? WHERE id=?;
UPDATE se_karaoke_entries SET status='cancelled' WHERE registration_id=? AND status IN ('held','queued','up_next');
if ($wasOnline) se_promote_waitlist($pdo, $event, 1);    // inside the same transaction
audit; commit; publish; enqueue promotion SMS (after commit)
```

#### 10.4.5 Waitlist promotion

`se_promote_waitlist(PDO $pdo, array $event, int $seats): array` (caller holds the lock):

```php
if (!$event['waitlist_enabled'] || $event['waitlist_promotion'] !== 'auto_confirm') return [];
$free = online_free(fresh counts); $n = min($seats, $free);
$rows = SELECT id FROM se_registrations WHERE event_id=? AND status='waitlisted'
        ORDER BY waitlisted_at, id LIMIT $n FOR UPDATE;
foreach ($rows as $r) UPDATE … SET status='confirmed', seat_pool='online', confirmed_at=NOW() WHERE id=? AND status='waitlisted';
return $promotedIds;   // after commit: se_messages_enqueue_waitlist_promotion($pdo, $event, $ids) if waitlist_notify_sms
```

Promotion also runs when the Producer **raises capacity**, switches to `force_open`, or turns `auto_close_at_capacity` off. In those cases it promotes up to `online_free` people, or everyone if the cap is now unlimited. With `waitlist_promotion = manual`, the Attendees tab offers **Promote** per person; the effect is the same.

**Lowering capacity** below `online_taken` never removes anyone. The Studio shows "122 confirmed exceeds the new capacity 90 — nobody is removed; registration stays closed until below 90".

#### 10.4.6 Walk-ins (on the day, via check-in)

`se_seat_for_walkin(array $event, array $counts, bool $deskOverride): ?string` (caller holds the lock):

```php
if (online_free > 0)                                         return 'online';  // a cancelled online seat is reused first
if (!$event['walkin_enabled'] && !$deskOverride)             return null;      // WALKINS_DISABLED
if (walkin_free > 0)                                         return 'walkin';
if (!$event['walkin_hard_cap'])                              return 'walkin';  // soft cap: allowed, Studio/console flag "over walk-in cap"
if ($deskOverride)                                           return 'walkin';  // audited 'walkin_override'
return null;                                                                    // WALKIN_FULL → "please see the desk"
```

Waitlisted or cancelled registrations arriving at the door go through the same function. Their row is reused (status → `confirmed`, pool as returned).

#### 10.4.7 Overrides

| Control (Studio → Registration, or console quick action) | Effect |
|---|---|
| **Pause registration** | `reg_override = force_closed` (note required) |
| **Force open** | `reg_override = force_open`: accepts registrations beyond capacity until switched back (note required). The meeting's "button to reopen". |
| **Raise capacity** (preferred) | Edit `online_capacity`; auto-promotes the waitlist |
| **Back to automatic** | `reg_override = none` |

Every override records `reg_override_by`, `reg_override_at`, the note, and an audit entry.

#### 10.4.8 Seats-left display

```php
if ($event['online_capacity'] === null || $event['seats_left_mode'] === 'never') return null;
$left = max(0, $event['online_capacity'] - $counts['online_taken']);
if ($event['seats_left_mode'] === 'always') return $left;
$pct = 100 * $counts['online_taken'] / max(1, $event['online_capacity']);
return $pct >= $event['seats_left_threshold_pct'] ? $left : null;
```

### 10.5 Check-in

`se_checkin(PDO $pdo, array $event, array $in, array $ctx): array`. Inputs: phone (or a registration id when called by the desk), `confirm = true`, optional walk-in fields (`first_name`, `last_name`, `gender`, `consent`), optional `gender` for members missing one.

1. **Window**: `se_event_phase()` must be `live` with `checkin_open = true`. Otherwise return `CHECKIN_NOT_OPEN` (with the opening time) or `CHECKIN_CLOSED`.
2. **Resolve** (before locking): normalise phone → contact (may not exist) → member (may exist).
3. **Lock** the event row. Load the registration `FOR UPDATE` (by contact).
4. **Branches:**

| Situation | Action |
|---|---|
| Registration `confirmed` | proceed |
| Registration `waitlisted`/`cancelled`, or none | walk-in: `$pool = se_seat_for_walkin()`; null → `WALKIN_FULL`. Unknown phone → require walk-in fields (`NEEDS_DETAILS` with the field list if missing). Create/reactivate the registration with `channel = walkin_self` (or `walkin_desk`), `seat_pool = $pool`, status `confirmed`. |
| Registration `removed` | `BLOCKED` ("Please see the desk") |

5. **Already checked in today?** `SELECT id FROM se_checkins WHERE event_id=? AND registration_id=? AND day_date=?`. If yes: commit nothing new. If this device owns the registration, return the full payload (`already = true`). If the device has **no** identity, bind it `readonly` and return `already_elsewhere = true`. If the device already belongs to someone else, just return that person's status (`for = "other"`, `already = true`).
6. **Gender**: if teams are enabled and the registration's gender is NULL, require `gender` (`NEEDS_GENDER`, one tap in the UI). Store it on the registration, and on the contact if empty.
7. **Insert** `se_checkins(event_id, registration_id, day_date, method, is_walkin, device_id, checked_in_by, checked_in_at = NOW(3), verse_id)`.
8. **Player number**: if `player_no` is NULL → `player_no = next_player_no`; `UPDATE se_events SET next_player_no = next_player_no + 1`. Also set `first_checkin_at`.
9. **Team**: if teams are enabled and `team_id` is NULL → `se_assign_team()` (§10.6).
10. **Verse**: `se_pick_verse()`, the approved verse of this event with the lowest usage count (ties → random), stored on the check-in. Multi-day events give a new verse each day.
11. **Karaoke**: any `held` entry → `queued`, `queue_no = next_karaoke_no++`, `position = (SELECT COALESCE(MAX(position),0)+1 …)`, `queued_at = NOW()`.
12. **Device**: bind (`full`) per §10.3.5. Only if the device has no identity yet; checking in someone else never rebinds.
13. **Audit + commit.** After commit: `se_live_publish(force=true, kinds=['public','lobby','room','team'])`.
14. **Response**: `{ for: "self" | "other", team: {id, name, label, hex, on, ring}, player_no, display_name, verse: {ref, text, prayer}, karaoke: {status, queue_no, ahead} | null, card_signature, room_key?, team_key? }`. The keys are included only when `for = "self"`.

**Desk variants** (`desk_checkin`, `desk_walkin`) call the same function with `method = 'desk'`, `checked_in_by = crew user`, the registration resolved by id, and an optional `override_walkin_cap`. No device binding happens (the guest's phone isn't present); the response is shown on the desk screen.

**Undo** (`desk_undo_checkin`, reason required): deletes the `se_checkins` row for that day, writes an audit entry containing the deleted row, and leaves player number, team and karaoke queue untouched.

**Transfer code** (`desk_transfer_code`): issues a 6-digit `transfer` token for the registration. The guest's phone calls `transfer` with the code → device bound `full`, and every other device of that registration is set to `readonly`.

### 10.6 Team assignment

#### 10.6.1 Inputs

- Teams of the event ordered by `sort_order` (k ≥ 2).
- Current counts per team, computed in the lock, from registrations of this event with `team_id IS NOT NULL AND status = 'confirmed'`: `n` (size), `n_Male`, `n_Female`, `n_member`, `n_guest`.
- The arriving person: gender `g ∈ {Male, Female}` (required, §10.5 step 6), membership `m ∈ {member, guest}` (`is_member`).
- `team_rr_pointer` on the event (0…k−1).

#### 10.6.2 Algorithm

```php
function se_assign_team(PDO $pdo, array $event, array $reg): array {
    $teams  = se_teams($pdo, $event['id']);                 // ordered
    $c      = se_team_counts($pdo, $event['id']);           // [team_id => counts]
    // Rule 1 — size: only teams with the minimum size are candidates (keeps sizes within ±1)
    $cand = se_argmin($teams, fn($t) => $c[$t['id']]['n']);
    // Rule 2 — gender: fewest people of the arriving person's gender
    $cand = se_argmin($cand, fn($t) => $c[$t['id']]['n_' . $reg['gender']]);
    // Rule 3 — mix: fewest people of the same membership type (spreads guests among members)
    $cand = se_argmin($cand, fn($t) => $c[$t['id']]['n_' . ($reg['is_member'] ? 'member' : 'guest')]);
    // Rule 4 — tie-break: first candidate at or after the round-robin pointer
    $k = count($teams); $ptr = (int) $event['team_rr_pointer'];
    for ($i = 0; $i < $k; $i++) {
        $t = $teams[($ptr + $i) % $k];
        if (in_array($t['id'], array_column($cand, 'id'), true)) { $chosen = $t; break; }
    }
    $idx = array_search($chosen['id'], array_column($teams, 'id'), true);
    UPDATE se_events SET team_rr_pointer = (($idx + 1) % $k) WHERE id = ?;
    UPDATE se_registrations SET team_id = ?, team_assigned_at = NOW() WHERE id = ? AND team_id IS NULL;
    INSERT INTO se_team_moves (event_id, registration_id, from_team_id, to_team_id, method) VALUES (?, ?, NULL, ?, 'auto');
    return $chosen;
}
```

`se_argmin($items, $fn)` returns every item whose `$fn` value equals the minimum, preserving order.

#### 10.6.3 Properties (enforced by tests, §22.1)

- **I1 — sizes**: without crew moves, `max(n) − min(n) ≤ 1` after every assignment (rule 1 always picks a smallest team).
- **I2 — gender**: for each gender, the spread across teams is ≤ 2 over 100 000 random arrival sequences (k = 2…8, 10–300 arrivals), and ≤ 1 in ≥ 95 % of final states. If a counterexample ever appears, revisit the rules.
- **I3 — determinism**: same arrival order + same starting pointer → same assignment (makes bugs reproducible).
- **I4 — concurrency**: assignment only happens under the event lock, so two simultaneous check-ins cannot both see the same counts.

#### 10.6.4 Crew moves

`team_move(registration_id, to_team_id, reason)` requires the `team_move` capability and a reason ≥ 3 chars. It writes `se_team_moves(method = 'crew')`, updates the registration and publishes. Existing points stay with the team they were earned for: the ledger is per team, not per person.

#### 10.6.5 Team configuration rules

- `count` 2–8. Colours are **hex only**: `^#?[0-9A-Fa-f]{6}$`, stored uppercase with `#`. **Paste box** accepts any separators (`,` `;` whitespace, newlines) and fills teams in order; a hex that has already appeared is **dropped**, the first occurrence keeping its place, because two teams with the same colour get the same label and the host cannot name them apart.
- Labels are auto-suggested from the hex by nearest named colour (`se_color_name()`, CSS named colours + a small Nigerian-English-friendly list), editable, ≤ 30 chars.
- Warnings (non-blocking) shown in the Studio:
  - two team colours too similar: OKLab ΔE < 0.08 (≈ "hard to tell apart") — `se_team_similarity_warnings()` takes the threshold as an argument and defaults to 0.12 for the brand palette (§13.2.4), so team configuration passes 0.08 explicitly;
  - a team colour with contrast < 3:1 against the stage background: it will be drawn with an outline ring, previewed live (§13.2.4).
- **Team count is locked once any registration has a team.** To change it before check-in opens, delete/recreate teams. After check-in has started, only colours, labels, names and captains can change.
- **Names** (D10): crew enters them on the night (Studio Teams tab, host console). Each rename triggers a `team_name` sound cue and a reveal animation on stage, if the current scene is `teams` or `leaderboard`. Max 40 chars; profanity list check (warn only, since crew typed it).
- **Captain**: set by crew. If none is set when a captain-mode round arms, the **auto-captain** is the team member with the earliest `joined_games_at` among devices seen in the last 2 minutes. Crew can override at any time.

### 10.7 Programme and live ETA engine

#### 10.7.1 Planned timeline

For each day, items in `sort_order`:

```php
$cursor = $day['starts_at'];
foreach ($items as &$it) {
    $it['planned_start'] = $it['planned_start_at'] ?? $cursor;
    $it['planned_end']   = $it['planned_start'] + $it['duration_min'] minutes;
    $cursor = $it['planned_end'];
}
```

The Studio shows conflicts: an explicit `planned_start_at` earlier than the previous item's end → amber "overlaps by N min".

#### 10.7.2 Live ETA

`se_program_eta(array $items, DateTimeImmutable $now)`:

```php
$cursor = null;
foreach ($items as &$it) {
    switch ($it['status']) {
        case 'done': case 'skipped':
            $cursor = $it['ended_at'] ?? $cursor; break;
        case 'live':
            $it['eta_start'] = $it['started_at'];
            $it['eta_end']   = max($now, $it['started_at'] + duration);
            $cursor = $it['eta_end']; break;
        default: // planned
            $base = $cursor ?? $it['planned_start'];
            $it['eta_start'] = max($base, $it['planned_start_at'] ?? $base);
            $it['eta_end']   = $it['eta_start'] + duration;
            $cursor = $it['eta_end'];
    }
}
$drift_min = round((last eta_end − last planned_end) / 60);   // positive = running late
```

- Host console shows drift ("Running 8 min late") and suggests skipping a `buffer` item.
- Public display honours `settings.program.public_time_mode`: `exact` (`7:15 PM`), `approximate` (default: `~7:15 PM`, rounded to 15 min) or `order_only` (no times), the right fit for a flexibly-timed programme (M7, M12).
- **Publish gate.** `settings.program.published` (default **false**) decides whether the event page shows the programme at all. `se_portal_program_public()` checks it *before* reading anything, so an unpublished programme costs the page no queries, returns `null`, and S4b plus its menu entry are simply not rendered. Studio → Programme has the toggle (`program_publish {id, on}` → `se_event_settings_patch()`, `event.edit`, no `row_version`). The gate is **public-page-only**: `se_snapshot_public()` keeps feeding `program` to the stage, the host console and the lobby, because the crew needs the run of show whether or not guests may read it.
- Actions: **Start** (sets `live`, ends any other live item as `done`), **Finish**, **Skip**, **Undo last** (restores the previous status/timestamps from the audit entry), **Reorder** (planned items only).
- Starting an item linked to a game sets the stage scene to that game's intro.

#### 10.7.3 AI import (apply rules)

AI parsing is described in §15.3. Applying a reviewed import:
- **Replace**: allowed only when no item of that day is `live`/`done`; deletes planned items, inserts the reviewed rows assigned to that selected day.
- **Append**: adds after the last item on each reviewed row's selected event day.
- Kind mapping: unknown kinds → `other`; times without dates are attached to the selected day; a written start/end range supplies the exact duration, otherwise a missing duration is derived from the next item's start, else 10 min (flagged).

### 10.8 Karaoke engine

#### 10.8.1 Library and import

- `se_songs` is global; `title_norm`/`artist_norm` = lowercase, accents stripped, punctuation removed, multiple spaces collapsed, leading "the " removed (for artists).
- **Import sources** (Studio → Karaoke → Import): paste (one per line, `Title - Artist - 4:05` or tab-separated), CSV/XLSX (PhpSpreadsheet; columns detected by header names `title|song`, `artist|singer`, `duration|length|time`), or an image/PDF of a list (AI, §15.5).
- Duration parsing: `m:ss`, `mm:ss`, `h:mm:ss`, plain seconds, `4m5s`. Invalid → NULL (shown as "—", counted as 4:00 for estimates).
- Preview shows: new to library / already in library / already in this event / possible duplicate (same title, different artist). Import adds missing songs to `se_songs` and all to `se_event_songs`.
- **Publish list** (`settings.karaoke.list_published = true`) makes it visible on manage links and in `/play`. Before that: "The song list is coming soon 🎤".

#### 10.8.2 Entry lifecycle

```
            pre-pick (manage link, before the event)              check-in
  (none) ───────────────────────────────────────────► held ─────────────────► queued ──► up_next ──► on_stage ──► done
     │  pick at venue (checked in) / desk / DJ add                    │   release time passes      │            │
     └────────────────────────────────────────────────────────────► queued                         ├► skipped   ├► no_show
                                         held ──► released (not checked in by release time)        └► cancelled (person cancels)
```

- **One active entry per person** (`active_singer_key` covers `held, queued, up_next, on_stage`). With `songs_per_person = 1` (v1 default), a person with a `done` entry cannot claim again; this is checked in PHP.
- **Unique songs**: when `settings.karaoke.unique_songs = true`, entries are inserted with `enforce_unique = 1`, so `active_song_key` (song_id while held/queued/up_next/on_stage/**done**) is unique per event. A taken song shows "Taken" in the picker. Insert failure on that key → `SONG_TAKEN` (race-safe).
- **Cap**: `settings.karaoke.max_singers` (NULL = unlimited). Count of entries in `held, queued, up_next, on_stage, done` ≥ cap → `KARAOKE_FULL`. The Studio suggests a cap = floor(karaoke programme minutes / (avg song minutes + 1 changeover minute)).
- **Release of holds**: at `first day starts_at + settings.karaoke.release_holds_after_min (30)`, `held` entries whose registration has no check-in become `released` (tick and cron). Released songs become available.
- **Queue order**: `position` (DJ can drag). `queue_no` is the stable ticket number shown to the singer. "Singers before you" = count of `queued`/`up_next` entries with a smaller `position`.
- **DJ actions**: `up_next` (one at a time; triggers the singer's phone alert), `on_stage` (one at a time; stage karaoke scene), `done`, `skipped`, `no_show`, reorder, add a walk-up singer (search attendee + song), remove.

### 10.9 Welcome verses

- Each event has a verse set (`se_event_verses`) built in the Studio: from **AI suggestions by theme** (e.g. "joy" for Chara, §15.7), from a library list, or typed references. Every reference is fetched from KJV (`se_bible_lookup`, §15.9) and stored as text, and crew must approve it. AI never supplies verse text.
- Each verse may carry a short prayer line template with `{name}` (e.g. "{name}, may the joy of the Lord be your strength tonight and always.").
- Assignment: lowest usage first, random among ties. Multi-day events: a new verse each day; re-checking in the same day returns the same verse.
- The card signature defaults to `"{title} {edition} by {organizer}"` → "Chara 2026 by Envision" (M8), editable in `settings.checkin.welcome_card.signature`.

### 10.10 Cloning

`se_clone_event(PDO $pdo, int $sourceId, array $opts, int $actorId): int`, in one transaction.

| Copied (if selected; defaults ✓) | Never copied |
|---|---|
| Details, description, venue, organiser label | Registrations, contacts' event data, check-ins, devices, tokens |
| Days (shifted by `Δ = new_first_start − old_first_start`) | Scores, answers, buzzes, rounds, survey responses, feedback |
| Brand colours, palette, fonts, theme preset, brand-kit assets (re-linked, not re-uploaded) | Karaoke entries (claims/queue) |
| Registration form settings, custom questions, capacity settings | Live state, display keys (new ones generated), message runs, hand-offs |
| Programme items (times shifted by Δ, status reset to `planned`) | Audit log, metrics |
| Teams (count, colours, labels; **names and captains cleared**) | Slug (must be new or reclaimed, §10.2 item 4) |
| Karaoke event song list + settings (`list_published` reset to false) | |
| Games (settings, weights); decks: **reuse** library decks, **duplicate** event decks; option **"Fresh questions only"** drops items used in the series' last N events (default 1) | |
| Welcome verses (approved state kept) | |
| Message settings/templates (schedules shifted by Δ) | |
| Crew (optional, default ☐) | |

The new event starts as `draft`, `series_id` = the source's (or a new series created on the fly), `cloned_from_event_id` = source.

---

## 11. Games engine, scoring and the live show

### 11.1 Concepts

- A **deck** holds content of one **content type**. A **game** of a given **type** draws ordered items from compatible decks. A game is played as a sequence of **rounds**, one per item (Feud: one per survey question).
- **Modes**: *individual* (every joined player answers: Live Quiz), *captain* (one answer per team, with suggestions: Trivia), *buzzer* (first team to buzz answers aloud; host judges: Bible Buzzer, Who Am I?, Feud face-off), *presenter* (one player acts; their team guesses aloud; host judges: Charades).

| Game type | Mode | Compatible content types | Default rounds |
|---|---|---|---|
| `live_quiz` (Kahoot-style) | individual | `mcq`, `verse` (MCQ ending), `emoji` (MCQ) | 10 |
| `trivia` | captain | `mcq`, `verse` (MCQ) | 8 |
| `buzzer` (Bible Buzzer / Finish the Verse / Emoji Bible) | buzzer | `open`, `verse`, `emoji`, `mcq` (shown without choices) | 10 |
| `who_am_i` | buzzer + clues | `clues` | 6 |
| `charades` | presenter | `charade` | 2 turns per team |
| `feud` | face-off + team play | `survey` | 3–4 |

"Finish the Verse" and "Emoji Bible" are **content flavours** played under `buzzer` (or `live_quiz` in MCQ form). The stage/phone views adapt to the content type: verse lead-in typography, big emoji row.

### 11.2 Round state machine (all games)

```
 pending ──arm──► armed ──(opens_at reached)──► open ──lock / closes_at──► locked ──reveal──► revealed ──score──► scored
    │                │                            │                         │                    │
    └──────────────── void (any time before scored; or after, by "void round" which voids its ledger rows) ─────────► void
```

| Transition | Who | Guards | Effects |
|---|---|---|---|
| create (`round_next`) | host/GM | game `live`; no other round of this game in `armed/open/locked/revealed` | new `pending` round with the next item |
| `arm` | host/GM | state `pending`; `expected_version` matches | `arm_at = now`, `opens_at = now + preroll`, `closes_at = opens_at + duration` (charades: no `closes_at` until the presenter starts); snapshot eligible counts per team → `eligible_json`; state `armed`; publish force |
| auto `open` | tick/lazy | `now ≥ opens_at` | state `open` (purely informational; answers are validated by time anyway) |
| `lock` | host/GM or tick at `closes_at` (if `settings.auto_lock`) | state `armed/open` | `locked_at = now`; state `locked`; publish |
| `reveal` | host/GM | state `locked` | compute correctness & per-choice distribution into `result_json`; state `revealed`; sfx `reveal`; publish |
| `score` | host/GM, or automatically right after reveal (`settings.auto_score`, default on) | state `revealed` | write ledger rows (idempotent keys); state `scored`; leaderboard deltas in `result_json`; publish |
| `void` | host/GM (+ reason) | any state | if scored: void all ledger rows of the round (`voided_at`, reason); state `void`; publish |

All transitions run in a transaction with `SELECT … FROM se_live_state WHERE event_id=? FOR UPDATE` and `UPDATE se_rounds … WHERE id=? AND state=<expected>`. Zero affected rows → `STALE_STATE` (no double transitions from double clicks). Each transition bumps `se_live_state.version`.

**Timing defaults** (`se_games.settings_json`, editable per game and per round in the console): `preroll_ms` 3000, `duration_ms` 20000 (quiz), 30000 (trivia), 15000 (buzzer window), 60000 (charades turn); `grace_ms` 1500.

### 11.3 Common validation

**Answer** (`public_api action=answer`):

1. Device bound `full` → registration `confirmed` and **checked in today**, else `NOT_CHECKED_IN`.
2. Round belongs to the event, its game is `individual` or `captain` mode, state ∈ {`armed`, `open`, `locked`}.
3. `server_now ≥ opens_at` (else `TOO_EARLY`) and `server_now ≤ closes_at + grace_ms` and the round not locked by the host before receipt (else `ROUND_CLOSED`).
4. Captain mode: the device's registration must be the team's (auto-)captain for this round (`NOT_CAPTAIN`). Non-captains use `suggest`.
5. Choice index within the item's choice count (`VALIDATION`).
6. `elapsed_ms` per §8.5.5; insert into `se_answers` (unique key → `ALREADY_ANSWERED`, returning the stored answer so the UI can restore).
7. Mark the snapshot dirty. Respond `{accepted: true, elapsed_ms}`. Correctness is **not** revealed until the round is `revealed`.

**Buzz** (`public_api action=buzz`): see §11.7.

### 11.4 Live Quiz (Kahoot-style, individual)

- **Phones**: during `armed`, a countdown ring; at `opens_at`, the prompt (text, small) plus 2–4 large answer tiles (letter + shape ▲ ◆ ● ■ + text). Colour is never the only cue (§13.14). After tapping: "Locked in ✓" with a pulse; the answer cannot be changed. On reveal: ✓/✗, points earned, streak, "You're #12 of 96".
- **Stage**: prompt (large), tiles, countdown ring, live "87 / 104 answered". Reveal: the correct tile glows and the others dim, distribution bars animate, the KJV reference/explanation shows. Then (if `show_podium_every = 1`) the **top 5 MVP** board with rank-change arrows.
- **Scoring**: §11.6.1. Optional streak bonus (off by default).
- **Eligibility**: individual points need only a valid answer. Team contribution uses the participation-normalised formula (§11.6.1) with eligibility frozen at arm time.

### 11.5 Bible Trivia (team, captain mode)

- At arm, each team's captain (or auto-captain, §10.6.5) gets **Captain view**: prompt + choices + **Submit for the team**. Other members get **Suggest view**: tap a choice to suggest it, changeable until the captain submits.
- Suggestions are stored as `role = 'suggestion'` (upserted) and published in that team's `team-<key>.json`. The captain's phone shows live bars ("Team says: B 7 · C 3 · A 1").
- When the captain submits, every team member's phone shows "Captain chose B".
- Reveal shows each team's choice on stage (four team-coloured chips under the tiles).
- **Scoring**: correct → `points_correct` (default 300) to the team; optional speed bonus (`speed_bonus_max`, default 0) linear in remaining time.
- If no captain answer arrives before lock, the **plurality suggestion** is used when `settings.use_suggestions_if_no_captain = true` (default true), marked "(by team vote)".

### 11.6 Scoring

#### 11.6.1 Formulas (defaults; every number is a game setting)

| Game | Individual (scope `individual`) | Team (scope `team`) |
|---|---|---|
| Live Quiz | correct: `round(base × (1 − (elapsed / duration) / 2))`, base = 1000 (`points_mode`: `standard`=1000, `double`=2000, `none`=0). Wrong/none: 0. Optional streak bonus +100 × (streak−1), capped +500. | per team per question: `round(team_base × correct_members / max(1, eligible_members))`, team_base = 1000 |
| Trivia | — | correct: 300 (+ optional speed bonus) |
| Buzzer games | — (the buzzing player gets `buzzer_player_points`, default 0) | correct: 300; wrong: `wrong_penalty` (default 0); lockout after a wrong answer |
| Who Am I? | — | by clue index when answered: 500, 400, 300, 200, 100 |
| Charades | presenter: `presenter_points` per word (default 0) | per word guessed: 200; pass: 0 |
| Family Feud | — | round winner banks Σ revealed answer points × round multiplier (1, 1, 2, 3) |
| Crew award / penalty | optional individual award | any integer with a reason |

Each game's `weight` (default 1.00) multiplies its **team** points at write time (rounded). Producers can balance games without editing every number. The Studio shows a "points preview": the maximum points each game can award per team.

#### 11.6.2 Ledger rules

- Auto rows carry `idempotency_key`:
  - `q:<round_id>:p:<registration_id>`: individual quiz points.
  - `q:<round_id>:t:<team_id>`: team quiz points.
  - `r:<round_id>:t:<team_id>`: team points for other games (one award per team per round).
  - `p:<round_id>:t:<team_id>:a:<attempt>`: a wrong-answer penalty (buzzer/Who Am I?). `attempt` increments on every buzz reopen and on every new Who-Am-I clue, so a team can be penalised once per attempt.
  - `c:<round_id>:w:<n>`: charades word n.
  - `f:<round_id>:bank`: feud bank.
- Re-scoring a round can therefore never double-count. To change a result, **void** the round (voids its rows) and re-run.
- Manual awards/penalties: `kind = award|penalty`, reason ≥ 3 chars, actor stored; shown in the console's score history with an **Undo** (= void).
- **Team total** = `SUM(points) WHERE event_id=? AND scope='team' AND team_id=? AND voided_at IS NULL`.
- **MVP** = `SUM(points) WHERE scope='individual' AND registration_id=? AND voided_at IS NULL`. Ties are broken by the number of correct answers, then the earliest last-scoring time.
- Ranks: dense ranking by total (ties share a rank) for display; the champion tie-break is the most round wins, then the host decides (console prompt).

### 11.7 Buzzer games (Bible Buzzer, Finish the Verse, Emoji Bible)

1. **Arm** → stage shows the prompt (verse lead-in in display serif, or a big emoji row, or the question). Phones show a giant **BUZZ** button in the team colour, disabled until `opens_at`.
2. **Who may buzz**: any checked-in, joined member of a team that is not locked out for this attempt (`settings.who_can_buzz`: `anyone` default, or `captain`).
3. `public_api action=buzz {round_id, attempt, client_ms}`:
   - Guards as in §11.3 (window = `[opens_at, closes_at]`, attempt must equal the round's current `attempt`).
   - `effective_ms = clamp(client_ms, opens_at_ms, received_ms)`. If `client_ms > received_ms + 200` (client clock ahead) → `received_ms`. If `client_ms < opens_at_ms − 200` → reject `TOO_EARLY`.
   - Insert `se_buzzes` (unique per team per attempt → `ALREADY_BUZZED` for teammates). Phones of that team show "Ada buzzed for your team!".
4. **Fairness window**: the winner is settled `300 ms` after the first buzz received for that attempt (tick/lazy). The winner is the lowest `effective_ms`, ties broken by `received_at`, then by team order.
5. **Stage**: the winning team's colour floods the screen with the team name and the buzzer's display name, plus a `buzz` sound. The console shows **✔ Correct / ✘ Wrong** for that team.
6. **Judging**: ✔ → team points, reveal answer (+ KJV text for verses), round → `revealed` → `scored`. ✘ → optional penalty, that team is locked out; if `reopen_on_wrong` (default true) and other teams remain, `attempt++` and a new 10 s window opens (`opens_at = now + 1500 ms`); otherwise the answer is revealed with no points.
7. **No buzz** before `closes_at` → host reveals; no points.

### 11.8 Who Am I?

- The item has 3–5 clues. On arm, clue 1 shows. The console has **Next clue** (stage animates the new clue in; `state_json.clue_index++`).
- Buzzing works as in §11.7 at any clue. Points depend on the clue index at the time of the **winning buzz**: 500/400/300/200/100. A wrong answer locks that team out until the next clue (`lockout_until_next_clue = true`).
- After the last clue with no correct answer → reveal.

### 11.9 Bible Charades

1. Console: **Next turn** → choose the **acting team** (default: rotate in team order, skipping none) → choose the **presenter**: type a **player number** ("#47", validated: member of that team, checked in today) or **Random** (random team member with a device seen in the last 2 minutes). If the presenter has no active device, the console offers **"Show on this tablet"** (presenter view on the host device).
2. The round goes `armed` with no `closes_at`. The presenter's phone vibrates: full-screen **secret card** (phrase, category, hint), a large **Hide** toggle, and the Screen Wake Lock is requested. The phrase is delivered **only** via `me` for that device (never in any snapshot) and to the console.
3. Host presses **Start timer** → `opens_at = now + 1500`, `closes_at = opens_at + turn_ms (60 s)`. Stage: team colour, "Ada O. is acting!", huge countdown, words guessed counter. Phones of the acting team: "Guess out loud!"; other teams: "Team Gold is acting".
4. Console buttons: **✔ Got it** (+200 to the team; next phrase from the deck appears on the presenter's phone and console), **Pass** (next phrase, no points; `max_passes` per turn default 2), **End turn**.
5. At `closes_at` the turn locks; tally (`state_json.words` = list of results). Score → ledger rows `c:<round_id>:w:<n>`.
6. Phrases used in a turn are marked used and never repeated in the event.

### 11.10 Bible Family Feud

#### 11.10.1 Survey collection

- Producers pick 3–5 **survey questions** (`content_type = survey`), e.g. "Name something you'd find on Noah's Ark".
- Attendees answer **before the game**:
  1. On the registration success screen, an optional "Play ahead 🎯: 3 quick fun questions" (skippable, never blocks registration);
  2. On the manage link;
  3. In `/play` before the Feud starts ("Feud survey — 30 seconds").
- One answer per person per question, ≤ 60 chars, trimmed. A profanity filter (word list) rejects with a friendly message.
- `settings.feud.survey_closes_at` (default: when the Feud game starts).

#### 11.10.2 Board building (Studio → Games → Feud → Build boards)

1. Normalise answers (lowercase, strip punctuation, singularise simple plurals, strip articles).
2. If ≥ `min_responses` (default 25) responses: **AI clustering** (§15.6) groups them into labelled answers with counts. The crew reviews: merge, split, rename, delete junk. The top 5–8 become the board (`se_feud_answers`, `source = survey`, `points = count`).
3. If too few responses: the crew writes the board manually, or asks AI for a "typical survey" board (`source = ai`, labelled internally as estimated). The UI marks such boards "estimated".
4. Boards must be **approved** before the game can arm that round.

#### 11.10.3 Play (per round)

1. **Face-off**: the console chooses two teams (rotate/bracket per `settings.feud.matchups`: `round_robin` default for 4 teams, or `manual`). One rep per team (by player number or random). Phones of the two reps show **BUZZ**. The first buzz (as §11.7) answers aloud. The host reveals the matching board answer, or ✘; the other rep then answers; the higher-ranked answer wins control. The console has **Give control to …**.
2. **Control team** guesses aloud one by one. The host taps the matching board slot (flip animation + `ding`) or **✘ Strike** (big ✘ on stage + `strike` sound). Revealed points accumulate in the **bank**.
3. **Three strikes** → the other team in the face-off gets one **steal** guess: the host taps the matching slot (steal succeeds) or ✘ (steal fails).
4. **Bank** goes to the control team (or the stealing team on success) × the round multiplier → ledger `f:<round_id>:bank`.
5. The host may **reveal all** remaining answers afterwards (no points) for fun.
6. With 4 teams and 4 rounds the default `round_robin` matchups are: (1v2), (3v4), (1v3), (2v4). Teams not in a round watch.

### 11.11 Leaderboards, MVP and awards

- `public.json` carries team totals and ranks after every scoring change (no names).
- `room.json` carries the MVP top-N (`settings.games.public_mvp_count`, default 5) with display names and team colours.
- **Final reveal** (console → **Finale**): stage counts down from the last team to the champion (podium animation, confetti in the champion colour, fanfare), then the MVP reveal.
- **Awards** (optional, roadmap for richer use): crew can create named awards ("Best team spirit") as manual team awards with a reason; they show as chips on the leaderboard.

### 11.12 Scenes, announcements and sound cues

**Scenes** (`se_live_state.scene`): `standby`, `welcome`, `program`, `teams`, `game`, `leaderboard`, `karaoke`, `announcement`, `break`, `blank`, `recap`, `finale`. Set by `live_api action=scene {scene, payload, expected_version}`.

| Scene | Payload | Stage shows | Phones show |
|---|---|---|---|
| `standby` | `{loop: [assetIds], countdown_to?}` | brand loop, "Scan to join" QR (to `/e/<slug>/in`), checked-in count, countdown | waiting card + team standings |
| `welcome` | `{title?, subtitle?}` | title card with wordmark | same title, small |
| `program` | — | now / next / later with ETAs | programme timeline |
| `teams` | — | 4 team panels: colour, name (or label), count; name reveal animation on rename | own team card |
| `game` | — (uses active game/round) | game view per type | game view per type/role |
| `leaderboard` | `{show_mvp: bool}` | team bars race (FLIP), MVP list | standings + own rank |
| `karaoke` | — | now on stage + next 3 + queue length | own karaoke status / pick song |
| `announcement` | `{text ≤ 140, seconds}` | big text with brand motion | banner at top |
| `break` | `{minutes, label}` | countdown timer | countdown |
| `blank` | — | logo on black (emergency/blackout) | nothing changes |
| `recap` | — | thank-you, champions, "Your My Night card is on your phone" | recap card CTA |

**Sound cues**: `public.json.sfx = {seq, cue}`. The stage plays the cue when `seq` increases. Cues: `tick` (last 5 s), `arm`, `reveal`, `correct`, `wrong`, `buzz`, `strike`, `ding`, `team_name`, `fanfare`, `applause`, `drumroll`. The host console's **sound board** can fire any cue manually (`live_api action=sound`). Phones never autoplay sound; they use haptics (`navigator.vibrate`) when allowed.

**Announcements** expire automatically (`until`), and the console can clear them early.

### 11.13 Test mode and resets

- **Test mode** (Studio → Live → **Rehearse**, also offered in Studio → Games and the host console) sets `settings.test_mode = true` on the event itself (there is no separate copy). It arrives with check-in (PR3), so the doors can be rehearsed before the games exist. While it is on:
  - check-in is allowed outside the check-in window, for crew devices only (ERP session present);
  - registrations, check-ins, rounds, karaoke entries and survey responses created during the rehearsal are flagged `is_test = 1`;
  - every ledger row gets `reason = 'TEST'`;
  - the console and stage show a red **TEST MODE** ribbon.
- **Reset rehearsal**:
  - voids every ledger row with reason `TEST`;
  - deletes rows with `is_test = 1` (rounds — and with them their answers and buzzes — survey responses, karaoke entries, check-ins, registrations, then any contact created only by test registrations);
  - resets `next_player_no`, `next_karaoke_no` and `team_rr_pointer` **only if no real (non-test) check-in exists**.
- Test mode MUST be switched off before doors open. The tick/cron switches it off automatically 15 minutes before `doors_open_at` of any day (after running **Reset rehearsal**) and notifies the Producer.
- **Reset a game** (before it has scored real rounds): deletes its pending/void rounds only.

### 11.14 Game settings (defaults)

```json
{
  "live_quiz":  { "preroll_ms": 3000, "duration_ms": 20000, "grace_ms": 1500, "points_mode": "standard", "team_base": 1000,
                  "streak_bonus": false, "auto_lock": true, "auto_score": true, "show_podium_every": 1 },
  "trivia":     { "preroll_ms": 3000, "duration_ms": 30000, "grace_ms": 1500, "points_correct": 300, "speed_bonus_max": 0,
                  "use_suggestions_if_no_captain": true, "auto_lock": true, "auto_score": true },
  "buzzer":     { "preroll_ms": 2500, "window_ms": 15000, "reopen_window_ms": 10000, "points_correct": 300, "wrong_penalty": 0,
                  "reopen_on_wrong": true, "who_can_buzz": "anyone", "buzzer_player_points": 0, "fairness_ms": 300 },
  "who_am_i":   { "points_by_clue": [500, 400, 300, 200, 100], "lockout_until_next_clue": true, "fairness_ms": 300 },
  "charades":   { "turn_ms": 60000, "points_per_word": 200, "max_passes": 2, "presenter_points": 0, "turns_per_team": 2 },
  "feud":       { "min_responses": 25, "board_size_min": 5, "board_size_max": 8, "round_multipliers": [1, 1, 2, 3],
                  "matchups": "round_robin", "steal_enabled": true }
}
```

---

## 12. API reference

### 12.1 Conventions (all four endpoints)

- **Transport**: `POST`, `Content-Type: application/json`, body `{"action": "<name>", "event": "<public_id>", …}`. The only GET is `public_api?action=time`. Studio uploads use `multipart/form-data` with the same fields plus files.
- **Headers**: every POST MUST send `X-SE-Request: 1`. The server rejects POSTs without it (`BAD_REQUEST`). Cross-origin pages cannot send custom headers without a CORS preflight, which we never grant. When present, `Origin` must match `HTTP_HOST` and `Sec-Fetch-Site` must be `same-origin` or `none`. Crew and Studio endpoints additionally require `X-SE-CSRF: <token>`, where the token is `$_SESSION['se_csrf']` (32 random bytes, hex), created on first use and embedded in the page boot data.
- **Bootstrap**: each endpoint `require_once '../includes/db.php'` (session + PDO + security gate), then `require_once '../includes/special_events/bootstrap.php'`, then `header('Content-Type: application/json')` (AGENTS.md shape, as in `api/reach_api.php`).
- **Response envelope** (house style + machine code):

```text
{ "status": "success", "message": "Human readable", "data": { … } }
{ "status": "error",   "message": "Human readable, safe to show", "code": "CAPACITY_FULL", "data": { … optional details … } }
```

- **HTTP status**: 200 for every handled outcome (house style); 405 for a wrong method; 413 for a body > 1 MB (uploads excepted); 429 with `Retry-After` for rate limits (body still has `code: "RATE_LIMITED"`). Exceptions are caught; the client gets `SERVER_ERROR` with a generic message and the server logs `error_log('SE <api>/<action>: ' . $e->getMessage())`. PDO errors MUST be wrapped (AGENTS.md).
- **Validation**: unknown fields are ignored; wrong types → `VALIDATION` with `data.fields = {name: "reason"}`.
- **Idempotency**: listed per action. Retrying an action with the same inputs yields the same final state.
- **Times** in responses: ISO 8601 with offset (`2026-10-24T17:00:00+01:00`) for display; epoch milliseconds (`*_ms`) for live timing.

**Error codes**

| Code | Meaning |
|---|---|
| `BAD_REQUEST` | Missing `X-SE-Request`, bad JSON, unknown action |
| `VALIDATION` | Field errors (`data.fields`) |
| `UNAUTHENTICATED` | ERP login required (crew/Studio) |
| `FORBIDDEN` | Logged in but lacking the capability |
| `CSRF` | Missing/invalid `X-SE-CSRF` |
| `RATE_LIMITED` | Too many requests (`data.retry_after_s`) |
| `EVENT_NOT_FOUND` | Unknown or non-public event |
| `EVENT_ARCHIVED` / `EVENT_CANCELLED` | Writes refused |
| `FEATURE_DISABLED` | Feature off for this event |
| `FEATURE_NOT_READY` | Table missing (migration pending, §9.4) |
| `INVALID_PHONE` | Phone not normalisable |
| `REG_NOT_OPEN` / `REG_CLOSED` / `CAPACITY_FULL` | Registration states (`data.state`) |
| `CONSENT_REQUIRED` | Consent tick missing in `required_followup` mode |
| `NEEDS_DETAILS` | Walk-in needs fields (`data.fields`) |
| `NEEDS_GENDER` | Gender needed for team balance |
| `BLOCKED` | Registration removed by crew |
| `CHECKIN_NOT_OPEN` / `CHECKIN_CLOSED` | Outside the check-in window (`data.opens_at`) |
| `WALKIN_FULL` / `WALKINS_DISABLED` | Walk-in pool rules |
| `NOT_REGISTERED` / `NOT_CHECKED_IN` / `DEVICE_READONLY` | Device/registration state |
| `TOKEN_INVALID` / `CODE_INVALID` | Manage token / transfer code |
| `SELF_CANCEL_DISABLED` / `TOO_LATE` | Cancellation rules |
| `KARAOKE_CLOSED` / `LIST_NOT_PUBLISHED` / `SONG_TAKEN` / `KARAOKE_FULL` / `ALREADY_HAS_SONG` | Karaoke rules |
| `ROUND_CLOSED` / `TOO_EARLY` / `ALREADY_ANSWERED` / `ALREADY_BUZZED` / `NOT_CAPTAIN` / `LOCKED_OUT` | Game rules |
| `STALE_VERSION` / `STALE_STATE` | Optimistic concurrency conflict; refresh and retry |
| `AI_UNAVAILABLE` / `AI_INVALID_OUTPUT` / `AI_LIMIT` | AI layer |
| `SMS_UNAVAILABLE` | SMS not configured or worker down (`data.health`) |
| `SERVER_ERROR` | Unexpected; logged |

**Rate-limit buckets** (fixed windows in `se_rate_limits`; subject = HMAC of the device token, or of IP+UA when no device exists yet; IP buckets use the HMAC of `security_client_ip()`). IP limits are generous because guests share the venue Wi-Fi (§8.5.8).

| Bucket | Per device | Per IP | Other |
|---|---|---|---|
| `lookup` | 20 / 10 min | 400 / 10 min | per phone: 10 / 10 min |
| `register` | 5 / 10 min | 150 / 10 min | — |
| `checkin` | 10 / 10 min | 800 / 10 min | per phone: 6 / 10 min |
| `answer`, `suggest`, `buzz` | 120 / min | 6 000 / min | — |
| `request_link` | 3 / hour | 30 / hour | per phone: 1 / 30 min and 3 per event |
| `transfer`, `claim_link` | 5 / 10 min | 100 / 10 min | — |
| `karaoke` (songs, pick, release) | 60 / 10 min | 1 500 / 10 min | — |
| `survey`, `feedback`, `wants_visit`, `optout` | 30 / 10 min | 1 500 / 10 min | — |
| `beacon` | 60 / 10 min | 3 000 / 10 min | — |
| Studio AI actions | — | — | per user 30 / hour; per event 300 / day |

### 12.2 Public API — `api/special_events_public_api.php`

Auth columns: **none** (anyone), **device** (device cookie bound to a registration), **device/token** (device or a `token` field holding a manage token).

| Action | Auth | Request (besides `action`, `event`) | Success `data` | Notable errors | Idempotency |
|---|---|---|---|---|---|
| `time` (GET) | none | — | `{server_ms}` | — | n/a |
| `bootstrap` | none | — | `{event: public config, phase, reg: {state, seats_left}, me: <me payload or null>}` | `EVENT_NOT_FOUND` | read |
| `lookup` | none | `{purpose: "register"\|"checkin", phone}` | `{kind: "member"\|"returning"\|"new"\|"registered"\|"checked_in", display_name?, needs: ["first_name","last_name","gender","email","consent"], reg_status?, waitlist_position?, device_owns?, checkin: {open, opens_at}?}` | `INVALID_PHONE`, `RATE_LIMITED` | read |
| `register` | none | `{phone, first_name?, last_name?, gender?, email?, how_heard?, how_heard_other?, karaoke_interest, consent, answers: {field_key: value}, ref?, src?, hp: "", t_ms}` | `{outcome: "confirmed"\|"waitlisted"\|"already", for: "self"\|"other", reg_code, display_name, manage_url?, waitlist_position?, ref_url, share: {…}}` (`for: "other"` when the device already had an identity, §10.3.5) | `REG_*`, `CAPACITY_FULL`, `CONSENT_REQUIRED`, `VALIDATION`, `BLOCKED` | UNIQUE(event, contact): repeat → `already` |
| `wants_visit` | device/token | `{value: true\|false}` | `{wants_visit}` | — | set |
| `request_link` | none | `{phone}` | `{sent: true}` (always the same answer, to avoid revealing registration existence; SMS only if a registration exists and the number is SMS-capable) | `RATE_LIMITED`, `FEATURE_DISABLED` | per-phone throttle |
| `claim_link` | none | `{token}` | `{me}` (device bound) | `TOKEN_INVALID` | yes |
| `me` | device/token | — | `<me payload>` (§12.2.1) | `NOT_REGISTERED` | read |
| `cancel` | device/token | `{reason?}` | `{status: "cancelled"}` | `SELF_CANCEL_DISABLED`, `TOO_LATE` | yes |
| `songs` | device/token | `{q?, page?}` | `{items: [{id, title, artist, duration_s, taken}], page, pages}` | `LIST_NOT_PUBLISHED` | read |
| `pick_song` | device/token | `{song_id}` | `{karaoke: {status, queue_no?, ahead?, song}}` | `SONG_TAKEN`, `KARAOKE_FULL`, `ALREADY_HAS_SONG`, `KARAOKE_CLOSED` | unique keys |
| `release_song` | device/token | — | `{karaoke: null}` | — | yes |
| `checkin` | none | `{phone, confirm: true, first_name?, last_name?, gender?, consent?}` | `{for: "self"\|"other", team, player_no, display_name, verse, karaoke, room_key?, team_key?, already?, already_elsewhere?, readonly?}` (keys only when `for = "self"`) | `CHECKIN_*`, `NEEDS_DETAILS`, `NEEDS_GENDER`, `WALKIN_FULL`, `BLOCKED` | UNIQUE(event, reg, day) |
| `transfer` | none | `{code}` | `{me}` | `CODE_INVALID`, `RATE_LIMITED` | single-use code |
| `join_games` | device | — | `{room_key, team_key, captain: bool}` | `NOT_CHECKED_IN`, `DEVICE_READONLY` | sets `joined_games_at` once |
| `answer` | device | `{round_id, choice_index, client_elapsed_ms}` | `{accepted: true, elapsed_ms}` | §11.3 | UNIQUE(round, reg, role) |
| `suggest` | device | `{round_id, choice_index}` | `{ok: true}` | `ROUND_CLOSED` | upsert |
| `buzz` | device | `{round_id, attempt, client_ms}` | `{accepted: true, first_for_team: bool}` | `TOO_EARLY`, `ROUND_CLOSED`, `ALREADY_BUZZED`, `LOCKED_OUT` | UNIQUE(round, attempt, team) |
| `survey` | device/token | `{item_id, text}` | `{saved: true}` | `VALIDATION` (length/profanity) | UNIQUE(event, item, reg) (update allowed until closed) |
| `feedback` | device/token | `{nps, favorite?, one_word?, comment?, wants_visit?, future_optin?}` | `{saved: true}` | `VALIDATION` | UNIQUE(event, reg) (update) |
| `optout` | device/token | — | `{opted_out: true}` | — | yes |
| `card` | device/token | `{kind: "im_going"\|"welcome"\|"team"\|"my_night"}` | data for the card template (§14.3) | `NOT_CHECKED_IN` (welcome/team), `FEATURE_DISABLED` | read |
| `beacon` | none | `{m: "view"\|"reg_start"\|"reg_done"\|"share"\|"card", d?: "<dimension>", t_ms?}` | `{}` | `RATE_LIMITED` (silently dropped client-side) | counter |
| `rt_token` | device | — | Ably token request (only when the push driver is enabled) | `FEATURE_DISABLED` | read |

**Honeypot and timing** on `register`: `hp` must be empty and `t_ms` (ms since the form opened) ≥ 1500. Otherwise the server returns a fake success (`outcome: "confirmed"`, no row written) and logs `bot_suspected`.

#### 12.2.1 The `me` payload

```json
{
  "registration": { "status": "confirmed", "seat_pool": "online", "display_name": "Ada O.", "reg_code": "7K3P9QXM",
                    "waitlist_position": null, "checked_in_today": true, "player_no": 47, "is_member": false,
                    "karaoke_interest": true, "wants_visit": false, "device_mode": "full" },
  "team": { "id": 12, "name": "Joy Bringers", "label": "Black", "hex": "#000000", "on": "#FFFFFF", "ring": true },
  "karaoke": { "status": "queued", "queue_no": 12, "ahead": 11, "song": { "title": "Way Maker", "artist": "Sinach" } },
  "games": { "joined": true, "captain": false, "room_key": "…", "team_key": "…" },
  "round": { "id": 901, "my_answer": { "choice_index": 2, "elapsed_ms": 4120 }, "result": null },
  "presenter": null,
  "score": { "points": 5400, "rank": 12, "of": 96 },
  "alerts": [ { "type": "karaoke_up_next", "at_ms": 1792863600000 } ],
  "links": { "manage_url": "https://hodlc.lpc.cm/e/chara/me/…", "ref_url": "https://hodlc.lpc.cm/e/chara?r=K3P9QX" }
}
```

- `presenter` is non-null only for the charades presenter's device while their round is active: `{round_id, phrase, category, hint, words_done}`.
- `round.result` appears after reveal: `{correct: true, points: 840, correct_index: 2}`.
- `/play` calls `me` when the public/room snapshot version changes and the change concerns this player (active round, karaoke, presenter hint), and at most every 3 s otherwise. While the device is the charades presenter, it calls `me` every 1 s.

### 12.3 Display API — `api/special_events_display_api.php`

| Action | Auth | Request | Response `data` |
|---|---|---|---|
| `display_boot` | `key` = room key (stage) or lobby key (lobby) | `{kind: "stage"\|"lobby", key}` | display config (theme, fonts, SFX map, snapshot URLs, check-in URL for the QR) |
| `tick` | `stage_key` | `{stage_key}` | `{server_ms, v}`. Performs the heartbeat (§8.5.6). |

Keys are compared with `hash_equals`. Wrong key → `FORBIDDEN` (logged, throttled 30 / 10 min per IP).

### 12.4 Live (crew) API — `api/special_events_live_api.php`

Auth: ERP session (`$_SESSION['user_id']`), `X-SE-CSRF`, and the listed capability on the event (§6.2). Every mutating action accepts `expected_version` (from the last console poll); a mismatch → `STALE_VERSION` with the fresh state (the console re-renders and the user retries). Every mutation writes `se_audit_log`, bumps `se_live_state.version` and publishes.

| Action | Capability | Request | Effect |
|---|---|---|---|
| `console` (poll, ~1 s) | host or game | — | Full console state: live state, programme with ETA/drift, active game/round with **private** data (correct answer, charades phrase, buzz order, captain answers), counts, health (snapshot age, cron, SMS). Also performs a tick. |
| `scene` | host | `{scene, payload}` | Set scene (§11.12) |
| `announce` / `announce_clear` | host | `{text, seconds}` | Banner |
| `sound` | host | `{cue}` | `sfx.seq++` |
| `program` | host | `{item_id, op: "start"\|"finish"\|"skip"\|"undo"}` | §10.7.2 |
| `program_move` | host | `{item_id, before_id\|null}` | reorder planned items |
| `game_start` / `game_pause` / `game_finish` | game | `{game_id}` | game status |
| `round_next` | game | `{game_id}` | create the next pending round |
| `round_arm` | game | `{round_id, preroll_ms?, duration_ms?}` | §11.2 |
| `round_lock` / `round_reveal` / `round_score` | game | `{round_id}` | §11.2 |
| `round_void` | game | `{round_id, reason}` | voids ledger rows |
| `captain_set` | game | `{team_id, registration_id\|player_no}` | team captain |
| `charades_turn` | game | `{round_id, team_id, player_no\|"random", show_on_console?: bool}` | presenter selection |
| `charades_start` / `charades_mark` / `charades_end` | game | `{round_id}` / `{round_id, result: "correct"\|"pass"}` / `{round_id}` | timer / word results / close the turn early |
| `buzz_judge` | game | `{round_id, buzz_id, correct: bool}` | §11.7 |
| `clue_next` | game | `{round_id}` | §11.8 |
| `feud_faceoff` | game | `{round_id, team_a, team_b, rep_a?, rep_b?}` | §11.10.3 |
| `feud_control` / `feud_reveal` / `feud_strike` / `feud_steal` / `feud_bank` / `feud_reveal_all` | game | per §11.10.3 | board state |
| `finale` | host | — | calculate champion, MVP and awards, then select the finale scene |
| `score_adjust` | score | `{scope, team_id\|registration_id, points, reason}` | ledger row (award/penalty) |
| `score_void` | score | `{score_event_id, reason}` | void |
| `team_name` | team_live | `{team_id, name}` | rename + reveal cue |
| `team_move` | team_move | `{registration_id, team_id, reason}` | §10.6.4 |
| `karaoke_queue` | karaoke | — | queue with names, songs, statuses |
| `karaoke_set` | karaoke | `{entry_id, status}` | §10.8.2 |
| `karaoke_move` | karaoke | `{entry_id, before_id\|null}` | reorder |
| `karaoke_add` | karaoke | `{registration_id\|player_no, song_id}` | add walk-up singer |
| `desk_state` (poll, ~5 s) | desk | — | counts (checked in / confirmed / walk-ins / walk-in cap left), check-in window, health. No game data. |
| `desk_search` | desk | `{q}` (name, last 4 digits, reg code, player #) | ≤ 20 results with display name, masked phone `•••• 4567`, status, team, checked-in |
| `desk_checkin` | desk | `{registration_id, gender?, override_walkin_cap?}` | §10.5 |
| `desk_walkin` | desk | `{phone, first_name, last_name, gender, consent, override_walkin_cap?}` | §10.5 |
| `desk_transfer_code` | desk | `{registration_id}` | `{code, expires_at}` |
| `desk_undo_checkin` | desk | `{registration_id, reason}` | §10.5 |
| `test_mode` | producer | `{on: bool}` / `{reset: true}` | §11.13 |
| `publish_now` | host | — | force publish all snapshots |

Capability keys used above: `host` = host console; `game` = game control; `score` = score awards/voids; `team_live` = rename teams / set captains; `team_move`; `karaoke`; `desk`; `producer`.

### 12.5 Studio API — `api/special_events_api.php`

Auth: ERP session + `X-SE-CSRF` + module access (§6.2) + capability on the event where relevant. Writes to `se_events` use `expected_row_version`. Grouped by tab:

| Area | Actions |
|---|---|
| Events | `list_events {filter}`, `get_event {id}`, `create_event {title, slug, days[], …}`, `update_event {id, section, fields, expected_row_version}`, `check_slug {slug, event_id?}`, `reclaim_slug {event_id, slug}`, `publish {id}`, `unpublish {id}`, `cancel {id, reason}`, `archive {id}`, `delete_draft {id}`, `clone_event {source_id, options, title, slug, first_start}` |
| Brand | `palette_derive {primary, secondary, accent?, preset}` (pure maths, instant), `palette_suggest {primary, secondary, mood[]}` (AI job), `apply_palette {id, palette}` |
| Registration | `form_fields_save {id, fields[]}`, `capacity_save {id, …}`, `override_set {id, mode, note}` |
| Days & programme | `days_save {id, days[]}`, `program_list {id}` (→ `{program, kinds, time_mode, published}`), `program_save {id, items[]}`, `program_publish {id, on}` (the §10.7.2 gate), `program_poster_data {id}` (§14.2b), `program_import {id, text?\|asset_id?}` (AI job), `program_apply {id, job_id, items[], mode: replace\|append, day_id}` |
| Teams | `teams_save {id, teams[{color_hex, color_label, name?}]}` (count locked after first assignment), `teams_roster {id}` |
| Karaoke | `songs_event_list`, `songs_import_preview {text\|file\|asset_id}` (AI for images), `songs_import_commit {preview_id}`, `songs_toggle {song_id, active}`, `karaoke_settings_save`, `karaoke_publish_list {on}` |
| Verses | `verses_suggest {theme, count}` (AI), `verses_add {refs[]}` (fetch KJV), `verses_save {verses[]}` |
| Games | `decks_list {type?}`, `deck_save`, `deck_item_save`, `deck_items_review {ids[], status}`, `deck_generate {content_type, topic, count, difficulty_mix, avoid_recent}` (AI job), `deck_generate_apply {job_id, deck_id}`, `games_save {games[]}`, `game_items_save {game_id, item_ids[]}`, `feud_build_board {item_id}` (AI clustering), `feud_board_save {item_id, answers[], approved}` |
| Assets | `asset_upload` (multipart), `asset_list {role?}`, `asset_update {id, title, alt_text, role}`, `asset_delete {id}`, `render_save` (multipart PNG from Format Studio + `template_key`, `params`), `template_upload` (SVG, sanitised) |
| Crew | `crew_list`, `crew_add {user_id, role}`, `crew_revoke {id}`, `user_search {q}`, `display_keys {id}`, `display_keys_rotate {id, which}` |
| Messages | `messages_get`, `messages_save {settings}`, `messages_preview {kind}` (rendered sample + units), `messages_estimate {kind}`, `messages_test {kind, phone?}`, `messages_send_adhoc {segment, template}`, `message_runs {id}` |
| Attendees | `attendees_list {filters, q, page}`, `attendee_get {id}`, `attendee_update {id, fields}` (crew corrections), `attendee_cancel`, `attendee_restore`, `attendee_promote`, `attendee_remove {reason}`, `attendee_add` (studio channel), `attendee_reset_links`, `attendee_erase {reason}`, `attendees_export` (xlsx), `possible_duplicates`, `possible_members` |
| Insights | `insights {id, range?}`, `report_pdf {id}`, `report_summary_ai {id}` |
| Hand-off | `handoff_preview {id}`, `handoff_commit {id, overrides[]}`, `handoff_history {id}` |
| Portal page | `portal_settings_save {id, settings: {intro_line?, show_countdown?, hero_video_enabled?, chapters_from_featured?, faq?[{q, a}]}}` (patches `settings.portal`, no row_version), `portal_faq_reset {id}` (the Appendix E questions again) |
| Ops | `audit_list {id, filters}`, `health {id}`, `settings_get`, `settings_save` (manager) |

Response shapes mirror the tables in §9. Every list action paginates (`page`, `per_page ≤ 100`), returning `{items, page, pages, total}`.

---

## 13. Front-end specification

Envision is the creative department, and the public experience must show it. The bar: **every first-time visitor should want to screenshot it.** That must not cost speed, accessibility or clarity.

### 13.1 Design system

#### 13.1.1 Principles

1. **Joy first.** Motion, colour and sound express *chara*: playful, never chaotic.
2. **One thumb, one decision.** Each mobile screen has one primary action, placed within thumb reach (bottom 40 % of the screen).
3. **Show, don't tell.** Icons, motion and short lines replace paragraphs; no screen has more than ~40 words above the fold.
4. **Contrast is computed, not hoped for.** All colour pairs come from the theme engine (§13.2), which enforces WCAG 2.2 AA.
5. **Motion has meaning.** Every animation explains a change (entering, confirming, revealing). Purely decorative motion is limited to the hero and pauses off-screen.
6. **Fast on a mid-range Android on 4G** (§13.15).
7. **Respect people.** Reduced motion, reduced transparency, screen readers, no surprise sound, minimal personal data on screens.

#### 13.1.2 Tokens

All tokens are CSS custom properties on `:root`. Tailwind v4 maps them to utilities in `se.input.css`:

```css
@import "tailwindcss";
@source "../js";
@source "../../../e";
@theme inline {
  --color-bg: var(--se-bg);            --color-bg-2: var(--se-bg-2);
  --color-surface: var(--se-surface);  --color-surface-2: var(--se-surface-2);
  --color-line: var(--se-border);
  --color-ink: var(--se-text);         --color-ink-muted: var(--se-text-muted);
  --color-primary: var(--se-primary);  --color-on-primary: var(--se-on-primary);
  --color-secondary: var(--se-secondary); --color-on-secondary: var(--se-on-secondary);
  --color-accent: var(--se-accent);
  --color-success: var(--se-success);  --color-danger: var(--se-danger);  --color-warning: var(--se-warning);
  --font-display: var(--se-font-display), ui-sans-serif, system-ui;
  --font-body: var(--se-font-body), ui-sans-serif, system-ui;
  --font-script: "Great Vibes", cursive;
  --radius-input: 12px; --radius-card: 20px; --radius-sheet: 28px;
}
```

Class names used in templates MUST be complete literal strings (`bg-primary`, never `bg-${x}`), so Tailwind's scanner finds them.

**No `style="…"` attributes in server-rendered HTML.** The §19.7 CSP sets `style-src` to `'self'` plus a nonce, and a nonce does **not** cover style *attributes* — browsers drop every one of them (verified in Chromium, PR1). Server HTML therefore carries only classes, and anything per-event or per-team is a CSS variable written into the nonced `<style>` block by `se_theme_css_vars($theme, $teams)`: it emits `--team-<i>`, `--team-<i>-on`, `--team-<i>-glow` and `--team-<i>-ring` for every team. Markup then uses fixed utility classes such as `bg-[var(--team-1)]`.

Inside Preact components the restriction does not apply: the `style` *prop* is set through the CSSOM, which CSP does not police.

| Group | Tokens |
|---|---|
| Colour roles | `--se-bg`, `--se-bg-2`, `--se-surface` (glass base), `--se-surface-2`, `--se-border`, `--se-text`, `--se-text-muted`, `--se-primary`, `--se-primary-raw`, `--se-on-primary`, `--se-secondary`, `--se-secondary-raw`, `--se-on-secondary`, `--se-accent`, `--se-glow`, `--se-glow-2`, `--se-focus`, `--se-success`, `--se-danger`, `--se-warning` |
| Team colours | per team *i*: `--team-<i>`, `--team-<i>-on`, `--team-<i>-ring`, `--team-<i>-glow` |
| Typography | Display (default **Unbounded** 600–800), Body (default **Inter** 400–700), Script (**Great Vibes**, for card signatures only). Fluid scale: `display-xl clamp(3.5rem, 12vw, 9rem)/0.9`, `display-lg clamp(2.25rem, 7vw, 4.5rem)/0.95`, `h1 clamp(1.75rem, 5vw, 3rem)/1.05`, `h2 clamp(1.375rem, 3.6vw, 2rem)/1.15`, `body 1rem/1.6`, `small .875rem/1.5`, `label .75rem uppercase tracking .12em` |
| Spacing | 4 px base; section padding `clamp(4rem, 12vh, 8rem)`; container max 1200 px; mobile gutter 20 px |
| Radii | inputs 12, cards 20, sheets/glass 28, pills 999 |
| Elevation | **glass**: `background: color-mix(in oklab, var(--se-surface) 70%, transparent); backdrop-filter: blur(18px) saturate(140%); border: 1px solid var(--se-border); box-shadow: 0 30px 80px -20px rgb(0 0 0 / .6)`. **Glow**: `0 0 24px var(--se-glow), 0 0 64px color-mix(in oklab, var(--se-glow) 40%, transparent)` |
| Z layers | base 0 · sticky bar 30 · sheet 50 · toast 60 · celebration overlay 70 |
| Breakpoints | `sm 640`, `md 768`, `lg 1024`, `xl 1280`; stage uses container query units |

**Font pairs** offered in the Studio (Google Fonts, loaded with `display=swap`; the display font is preloaded): Unbounded + Inter (default) · Syne + Manrope · Bricolage Grotesque + Inter · Space Grotesk + Inter · Fraunces + Inter (elegant events) · Monoton (wordmark only) + Inter.

**Low-power fallback.** When `matchMedia('(prefers-reduced-transparency: reduce)')` matches or `navigator.deviceMemory <= 2`, glass falls back to an opaque `--se-surface-2` (no `backdrop-filter`), and the hero video falls back to the poster image.

#### 13.1.3 Icons and imagery

- Icons: **Lucide** (ISC licence), vendored as an SVG sprite (`assets/se/img/icons.svg`, only the ~60 icons used). Stroke 1.75, size 20/24. Icons never carry meaning alone (always a label or `aria-label`).
- Emoji are allowed for warmth in copy (🎤 🎉 🙌) but never as the only label of a control.
- Illustrations: Envision's brand-kit assets. Defaults ship in `assets/se/img/` (mic, controller, open Bible, confetti shapes, team orbs).
- Every uploaded image is served as responsive WebP variants (480/960/1600 widths) with `width`/`height` attributes to prevent layout shift.

#### 13.1.4 Motion system (GSAP)

| Token | Duration | Easing | Use |
|---|---|---|---|
| `micro` | 150 ms | `power2.out` | presses, toggles |
| `small` | 240 ms | `power3.out` | chips, toasts |
| `medium` | 450 ms | `power3.out` | sheets, cards entering |
| `large` | 800 ms | `expo.out` | reveals, scene changes |
| `pop` | 600 ms | `back.out(1.6)` | team reveal, correct answer |
| `hero` | 1.6 s total | staged | hero intro |

Rules:
- Animate only `transform`, `opacity`, `filter: blur()` (sparingly) and CSS variables. Never layout properties.
- Pause infinite animations (spotlights, equaliser, glow breathing) when off-screen (IntersectionObserver) or when the tab is hidden.
- ScrollTrigger **pinning only on ≥ 768 px**. On mobile, chapters use `ScrollTrigger.batch` reveal-on-enter (no pin, no scrub), which avoids iOS jank.
- **Reduced motion** (`prefers-reduced-motion: reduce`): no spotlights, flicker, parallax, pinning or confetti. Fades ≤ 200 ms. Countdown digits change without flip.
- All GSAP code goes through `@se/core/motion.js` helpers (`reveal()`, `popIn()`, `teamWipe()`, `flicker()`, `countUp()`) that check the motion preference once.

#### 13.1.5 Haptics

`navigator.vibrate` (no-op on iOS), only after a user gesture: tap confirm `10`; answer locked `15`; team reveal `[30, 40, 60]`; buzz accepted `25`; "you're up next" `[80, 60, 80]`; presenter selected `[60, 40, 60, 40, 120]`.

#### 13.1.6 Sound design (stage only)

- One sprite `assets/se/sfx/se-sfx.mp3` (+ `se-sfx.json` offsets), loudness-normalised to −16 LUFS, ≤ 600 KB total. Cues: `tick`, `arm`, `reveal`, `correct`, `wrong`, `buzz`, `strike`, `ding`, `team_name`, `fanfare`, `applause`, `drumroll`, `whoosh`.
- Played with Web Audio (`AudioContext` unlocked by the stage's "Click to start" overlay). Master volume and mute live in the host console (`settings.stage.volume`, default 0.8).
- Sources: produced in-house or royalty-free with a recorded licence (`assets/se/sfx/LICENSES.md`).

#### 13.1.7 Component inventory (`@se/core/ui/`)

`Button` (primary-neon, secondary, ghost, glass, danger; loading state), `Chip`/`ChipGroup` (single/multi), `Switch`, `PhoneInput` (country prefix, tel keypad, inline validation), `TextField`, `Select`, `Sheet` (bottom sheet ↔ modal; focus trap; drag-to-dismiss with confirm), `Toast`, `Countdown` (flip digits; reduced-motion variant), `SeatsRing`, `TeamBadge` (colour, name/label, ring when low contrast), `PlayerNumber`, `ProgressRing` (timers), `AnswerTile` (letter + shape + text), `BuzzButton`, `Leaderboard` (FLIP animations), `QueueList`, `ProgramTimeline`, `QR` (SVG), `CardStudio` (share-card editor, §14), `PhotoCircleCropper`, `EmptyState`, `ErrorBoundary`, `NetPill` (online/reconnecting), `ConfettiBurst`.

### 13.2 Theme engine

Implemented once in PHP (`includes/special_events/theme.php`, the source of truth used to render `<style>` on every page) and mirrored in JS (`@se/core/theme.js`, for live previews in the Studio). Both MUST produce identical tokens for the same inputs (shared test vectors, §22.1).

#### 13.2.1 Colour maths

- Hex → sRGB (0–1) → linear (`c ≤ 0.04045 ? c/12.92 : ((c+0.055)/1.055)^2.4`).
- **OKLab/OKLCH** (Björn Ottosson's matrices):

```
l = 0.4122214708 r + 0.5363325363 g + 0.0514459929 b
m = 0.2119034982 r + 0.6806995451 g + 0.1073969566 b
s = 0.0883024619 r + 0.2817188376 g + 0.6299787005 b
l' = ∛l, m' = ∛m, s' = ∛s
L = 0.2104542553 l' + 0.7936177850 m' − 0.0040720468 s'
a = 1.9779984951 l' − 2.4285922050 m' + 0.4505937099 s'
b = 0.0259040371 l' + 0.7827717662 m' − 0.8086757660 s'
C = √(a² + b²), H = atan2(b, a) in degrees
inverse:
l' = L + 0.3963377774 a + 0.2158037573 b ; m' = L − 0.1055613458 a − 0.0638541728 b ; s' = L − 0.0894841775 a − 1.2914855480 b
r = +4.0767416621 l − 3.3077115913 m + 0.2309699292 s
g = −1.2684380046 l + 2.6097574011 m − 0.3413193965 s
b = −0.0041960863 l − 0.7034186147 m + 1.7076147010 s
```

- Out-of-gamut results: reduce chroma by binary search (12 iterations) until r, g, b ∈ [0, 1].
- **WCAG contrast**: relative luminance `Y = 0.2126 R + 0.7152 G + 0.0722 B` (linear), ratio `(Y1 + 0.05) / (Y2 + 0.05)`.
- **Similarity**: OKLab ΔE `= √(ΔL² + Δa² + Δb²)`.

#### 13.2.2 Marquee token derivation (dark)

Inputs: primary `P`, secondary `S`, optional accent `A` (all hex). `(Lp, Cp, Hp)` = OKLCH of P.

| Token | Rule |
|---|---|
| `--se-bg` | `oklch(0.14, min(0.035, 0.25·Cp), Hp)` |
| `--se-bg-2` | `oklch(0.10, min(0.030, 0.20·Cp), Hp)` |
| `--se-surface` | `oklch(0.19, min(0.040, 0.30·Cp), Hp)` |
| `--se-surface-2` | `oklch(0.24, min(0.045, 0.30·Cp), Hp)` |
| `--se-border` | `rgb(255 255 255 / 0.14)` |
| `--se-text` | `oklch(0.97, 0.010, Hp)` |
| `--se-text-muted` | `oklch(0.80, 0.020, Hp)`, raised until contrast with `--se-bg` ≥ 4.5 |
| `--se-primary-raw` | P exactly (decorative use) |
| `--se-primary` | P, with L raised in 0.02 steps until contrast vs `--se-bg` ≥ 3.0 (cap L 0.92) |
| `--se-on-primary` | `#0B0B0F` or `#FFFFFF`, whichever contrasts more with `--se-primary` (must be ≥ 4.5; else nudge primary L until it is) |
| `--se-secondary(-raw)`, `--se-on-secondary` | same rules with S |
| `--se-accent` | A if given (same raising rule); else `oklch(0.78, max(Cp, 0.12), Hp + 150°)` gamut-clipped |
| `--se-glow` / `--se-glow-2` | `oklch(min(0.85, Lp + 0.12), min(0.32, 1.25·Cp), Hp)` / same for S |
| `--se-focus` | `--se-secondary` if contrast vs `--se-bg` ≥ 3.0, else `--se-text` |
| `--se-success` / `--se-danger` / `--se-warning` | `oklch(0.78 0.17 150)` / `oklch(0.70 0.19 25)` / `oklch(0.85 0.16 85)` |

The derived palette is stored in `se_events.palette_json` at save time (with the inputs and a hash) so pages don't recompute it. It is recomputed whenever the inputs change.

#### 13.2.3 AI palette suggestions

The **"Suggest palettes ✨"** button (§15.2) returns 4 palettes. Each keeps P and S **unchanged** and proposes accent, mood keywords and optional team-colour ideas. The server validates every hex, recomputes all tokens with §13.2.2 (AI never sets contrast-critical tokens) and attaches a contrast report. The Studio shows each suggestion as a card with three live minis (portal hero, stage title, team chips) and **Apply**.

#### 13.2.4 Team colours on screen

For each team: `--team-i = hex`; `--team-i-on` = black/white with the higher contrast; `--team-i-glow` = lighter OKLCH variant. **`--team-i-ring`**: if contrast(team, `--se-bg`) < 3.0 (e.g. **black on a dark stage**), every team chip, badge, bar and wipe gets a 2 px ring in `--se-text` at 85 % opacity plus a soft outer glow of the team's lighter variant. A black team therefore always reads as a defined black shape. Team identity is also always carried by the **name/label text** (never colour alone).

### 13.3 Portal — "Marquee" theme (Neon Stage × Cinematic Story)

Mobile-first; desktop enhances. Sections in order:

**S0 · Top bar.** Transparent over the hero, glass after 80 px of scroll. Left: `ENVISION PRESENTS` (label style, organiser label). Right: Share (Web Share API → fallback copy link) and a menu (Programme, Venue, FAQ, Privacy).

**S1 · Hero (100svh).**
- *Background*: hero video (muted, `playsinline`, loop ≤ 15 s, poster) **or** an animated gradient mesh: 3 layered radial gradients in primary/secondary/accent whose positions drift via `@property` custom properties, a 3 % SVG noise grain and a vignette. The video is skipped if `navigator.connection.saveData` or `effectiveType` is `2g`/`slow-2g`.
- *Spotlights*: two conic-gradient beams from the top corners, `mix-blend-mode: screen`, opacity .35, sweeping ±18° (GSAP yoyo, 6–8 s, offset).
- *Kicker*: `CHARA 2026 · BY ENVISION` (edition + organiser).
- *Wordmark*: event title in `display-xl`, display font, neon glow (layered `text-shadow` from `--se-glow`). Intro: SplitText chars flicker-in (opacity 0→1→.3→1, glow ramp, 40 ms stagger, ≈1.2 s), then a slow glow "breathing" (4 s sine).
- *Tagline* (≤ 12 words) and *info chips*: 📅 date · 🕔 time · 📍 venue (tap → map).
- *Countdown* (flip digits DD : HH : MM : SS) while `upcoming`.
- *CTA cluster*: primary neon button (phase-aware label, §10.1) with a pulsing ring; the **seats-left ring** wraps the button when visible ("42 left"); secondary ghost link "See the night ↓".
- *Equaliser strip* along the bottom edge (24 bars, CSS transforms with randomised durations), then a bouncing scroll cue.
- *Live phase*: a "Happening now · 97 checked in" chip (from `public.json`) and the CTA "Check in" (or "Join the games" for a checked-in device).

**S2 · "What is it?"** One statement from `settings.portal.intro_line` (for Chara: "*Chara* (χαρά) means joy."), revealed line by line, plus up to 40 words from the description. Background shifts from `--se-bg` to a primary-tinted gradient.

**S3 · Chapters.** One chapter per **featured** programme item (or per default activity block if none are featured). Each chapter: big index `01`, title (`display-lg`), ≤ 25-word blurb and one **interactive object**:
- *The Mic* (karaoke): animated SVG microphone with an equaliser halo. Tap plays a 2-second sample if a `sfx`/`music` asset is attached (muted until tapped). Copy: "Tick karaoke when you register — pick your song before the night."
- *The Games*: a fan of 4–6 cards (Charades, Live Quiz, Trivia, Buzzer, Family Feud). Tap → 3D flip to a one-line how-to.
- *The Teams*: team orbs in the event's team colours orbiting slowly. "At check-in you'll join a colour team — balanced and fun."
- *The Night*: a programme timeline that draws itself (SVG stroke) with approximate times (§10.7.2).
- *Desktop*: each chapter pins for one viewport height while its content animates (scrubbed). *Mobile*: no pin; content reveals on enter.

**S4 · Watch / listen.** "Lite" YouTube embed (thumbnail + play; the `youtube-nocookie.com` iframe loads on tap) and an optional voice-over audio card ("Hear from the team"). **Not built in PR2** — no per-event setting holds the URL yet, so the portal simply skips this screen (§28.4).

**S4b · Programme.** The public run of show (§10.7.2): public items only, never a crew note, times in the event's chosen mode. Shown only when `settings.program.published` is true — see the publish gate in §10.7.2. While it is hidden the top-bar menu drops its "Programme" entry too (the menu's first item, which has always pointed at the chapters, now reads "The night").

**S5 · Venue.** Glass card: venue name, address, notes ("Check-in is downstairs"), **Open in Maps**, **Add to calendar** (`/e/<slug>/calendar.ics`).

**S6 · FAQ.** Accordion from `settings.portal.faq`. The Appendix E list (cost, dress code, singing optional, friends, arrival time, food) is **starter content**, not fixed copy: Studio → Details → **Good to know** rewrites, reorders, extends (to `SE_PORTAL_FAQ_MAX` = 20) or empties it, and an empty list drops the whole section. Server-side, `se_portal_faq_clean()` trims, drops fully blank rows and refuses a half-filled one (`faq_<i>_q` / `faq_<i>_a` field errors); the normaliser only re-seeds the six when the key is absent entirely.

**S7 · Footer.** Organiser, HOD Lekki logo, Privacy, "Designed by Envision".

**Sticky bar (mobile).** Appears once the hero leaves the viewport. Shows CTA + seats-left (or "You're registered ✓ · Manage"); respects `env(safe-area-inset-bottom)`; hides while a sheet is open.

**Hub `/e/`.** A grid of event cards (OG image, title, date, status chip) on a brand-neutral dark background with a soft spotlight.

### 13.4 Registration sheet

- Opens as a bottom sheet (mobile) or centred 520 px modal (≥ 768 px), glass, with 2–3 progress dots. Drag-to-dismiss asks "Leave registration?" if anything is typed.
- **Step 1 — Phone**: label "Your phone number", hint "We'll use it to welcome you at the door." `type="tel" inputmode="tel" autocomplete="tel"`, a `+234` prefix chip (tap → international), font-size ≥ 16 px (prevents iOS zoom). **Continue**.
- **Step 2 — Identity** (by `lookup.kind`):
  - `member`: big avatar initials, "Hi Ada O. 👋 — is this you?" → **Yes, register me** / Not me. Then the karaoke switch and consent (if needed).
  - `returning`: "Welcome back, Ada!" with pre-filled, editable email/gender. Names are shown read-only with "Not right? Tell us" (stored as a correction).
  - `new`: First name (`given-name`), Last name (`family-name`), Gender (radiogroup of two big chips), Email (optional, `email`), "How did you hear about Chara?" (chips: WhatsApp, Instagram, Facebook, TikTok, Friend/family, Church announcement, Flyer/poster, SMS, Other → text), Karaoke switch (mic wiggles on), consent checkbox + privacy link, custom questions.
  - `registered`: "You're already in, Ada O. 🎉" → device-owned: show ticket; else **Text me my link** (if enabled) + "or just check in at the door".
- **Submit**: spinner in the button; network failure keeps all input and shows **Retry**.
- **Success (confirmed)**: confetti burst in brand colours (reduced motion → none). Ticket card (event wordmark, name, date, reg code, subtle holographic gradient that follows device tilt where `DeviceOrientationEvent` is permitted). An action grid:
  - **Save your link**: copy / share / add to home screen (`beforeinstallprompt` on Android; iOS instructions).
  - **Make your "I'm going" card** (§14.3).
  - **Add to calendar**.
  - **Invite friends**: personal `?r=` link via Web Share.

  Then "One more thing: Would you like to visit HOD Lekki on a Sunday?" (Yes, I'd love to / Not now), and "Play ahead 🎯" (Feud survey) when configured.
- **Waitlist**: "You're #4 on the waitlist" + what happens next. **Closed/full**: the state message + walk-in note.
- The device's registration summary and manage token are cached in `localStorage['se:<public_id>']` (wrapped in try/catch; private mode safe).

### 13.5 Manage page (`/me/<token>`)

Status hero (confirmed ✓ / waitlist #n / cancelled), event chips, then cards: **Your song** (pre-pick with search, "Taken" greyed, change/release), **Your cards**, **Invite friends**, **Add to calendar**, **Can't make it?** (if enabled; confirm sheet). After the event: **My Night** (recap card) and **Feedback** (`#feedback` deep link scrolls to and opens it).

### 13.6 Check-in (`/in`)

1. **Splash**: small wordmark + "Welcome to Chara! Let's check you in." + phone input (autofocus), or **"Check in as Ada O."** one-tap when the device is known.
2. **Confirm**: huge "Ada O." + "Is this you?" → **Yes, check me in** / Not me.
3. **Details** (walk-in) or **Gender** (one tap) when the API asks.
4. **Team reveal**: a circle wipe in the team colour expands from the button (clip-path). "You're on" + team **name** (or label) in `display-lg` + **#47** in a badge; `pop` animation; confetti in the team colour; haptic pattern.
5. **Welcome card** (§14.3) with **Save** / **Share**.
6. **Next**: Pick your song (if karaoke) → **Join the games** → Tonight's programme.
- Error states with friendly copy: not open yet (countdown to doors), closed, walk-ins full (see the desk), already checked in elsewhere (read-only + desk hint).

### 13.7 Games portal (`/play`)

- **Header**: a bar in the team colour (with ring if needed): team name/label · `#47` · my points · NetPill.
- **Bottom tabs**: Play · Karaoke · Programme · Me.
- **Play tab by state**:
  - Waiting: what's on now/next, team standings mini, "Next game soon".
  - Live Quiz: countdown ring → 2–4 `AnswerTile`s (letter + shape + text) → "Locked in ✓" → reveal (✓/✗, +points, rank).
  - Trivia: captain view (submit for team + live suggestion bars) or member view (suggest; "Captain chose B").
  - Buzzer/Who Am I?: full-width `BuzzButton` (team colour, ≥ 40 % of the screen height) + current clue/prompt mirror; disabled states explain why ("Your team is locked out this round").
  - Charades: presenter → secret card with **Hide**, Screen Wake Lock, timer; others → "Team Gold is acting".
  - Feud: board mirror (revealed answers), reps get BUZZ during face-offs.
  - Leaderboard: standings, my rank.
- **Karaoke tab**: my status (number, ahead count), pick/release, list search; "You're up next 🎤" full-screen alert with haptic when `alerts` says so.
- **Me tab**: my cards (welcome/team/My Night), team, player number, manage link.

### 13.8 Stage display (`/stage`)

- A 16:9 frame centred and scaled to the screen (`aspect-ratio: 16/9; container-type: size`). All sizes in `cqw`/`cqh`, minimum body text `2.2cqw`. No thin weights; off-white text (not pure white) to reduce projector glare.
- **Start overlay** "Click to start the show": unlocks audio, requests fullscreen and Wake Lock, starts the 1 s tick.
- Persistent chrome (toggleable): small wordmark bottom-left, optional team score strip at the bottom, tiny connection dot top-right.
- Scenes as in §11.12, with transitions (400 ms crossfade + scene choreography):
  - *Standby*: slow Ken-Burns brand slides, big QR "Scan to join → hodlc.lpc.cm/e/chara/in", live checked-in counter (count-up), countdown.
  - *Teams*: four tall panels (colour, name/label, member count). A rename plays a typewriter + glow reveal with the `team_name` cue.
  - *Quiz/Trivia*: prompt (max 3 lines), tile grid, countdown ring, "87 / 104 answered". Reveal: tile glow, distribution bars, reference.
  - *Buzzer/Who Am I*: prompt or clues; on buzz, a full-frame flash in the team colour with the team name + buzzer display name.
  - *Charades*: team colour field, "Ada O. is acting!", giant timer, words-guessed counter.
  - *Feud*: classic two-column board with flip tiles, strike ✘ overlay, bank counter, face-off team panels.
  - *Leaderboard*: horizontal bars racing to totals (FLIP), rank badges; MVP top-5 list with display names.
  - *Karaoke*: spotlight on "Now on the mic: Ada O." (team colour ring), song + artist, "Up next" ×3.
  - *Finale*: podium countdown, champion confetti + fanfare, MVP reveal.
  - *Blank*: logo on near-black.
- Holds the last good state if the network drops; the dot turns amber, then red.

### 13.9 Lobby display (`/lobby`)

- Left 45 %: huge QR to `/e/<slug>/in` with "Scan to check in" and the short URL. Below: "Doors open · Starts 5:00 PM" countdown.
- Right 55 %: **arrivals stream**. Each check-in card flies up with a team-colour stripe: "Welcome Ada O. → Team Black". The newest is big and older ones shrink; at most 6 are visible.
- Bottom: team count bars (equal-size goal line) and the total checked in.
- Idle (60 s without arrivals): rotating tips ("Tip: tick karaoke to sing tonight 🎤").
- `settings.lobby.show_names = false` → cards show only "New arrival → Team Black".

### 13.10 Host console (`/host`)

- Dark UI with large touch targets (≥ 48 px). ≥ 1024 px: three columns. Below that: tabs (Show · Game · Teams · Karaoke).
- **Top bar**: event, phase, clock, health dots (snapshot age < 3 s green; cron < 10 min; SMS worker), version, **TEST MODE** ribbon, **Blackout** (scene `blank`, one tap, confirm-free).
- **Column 1 — Run of show**: items with ETAs, the live item highlighted, drift badge ("+8 min"), Start / Finish / Skip / Undo, buffer suggestion.
- **Column 2 — Stage & game**: a live mini stage (the same scene components rendered at 25 % scale from the same snapshots), scene buttons, and the **Game runner**. The runner shows one big **primary action** that changes with state (Next question → Arm → Lock → Reveal → Next…), secondary actions, private info (correct answer, charades phrase, buzz order with ms), and judge buttons per team.
- **Column 3 — Teams & tools**: team totals with quick awards (+100/+200/+500/custom, reason required for custom), score history with **Undo** (void), team rename fields, captain pickers, announcement composer, sound board, karaoke mini (now/next).
- **Keyboard**: Space = primary action · A arm · L lock · R reveal · N next round · 1–8 judge team ✔ (Shift ✘) · B blackout · S leaderboard · K karaoke · ? help overlay · Esc close.
- Every mutation sends `expected_version`. On `STALE_VERSION`, the console refreshes and shows "Someone else just changed the show — check and retry".

### 13.11 Desk mode (`/desk`)

- Mobile-first. Header: "Desk · Chara" + counts (checked in / confirmed / walk-ins) + NetPill.
- Big search (name, last 4 digits, reg code, player #). Result rows: display name, masked phone `•••• 4567`, status chip, team chip, ✓ if checked in. Actions: **Check in**, **Transfer code**, **Undo**, **Move team** (if allowed).
- **Walk-in** button → full form, with an over-cap override toggle when permitted.
- **Offline**: on open, the registrant list (display names, masked phones, reg codes, status, team) is cached in IndexedDB `se-desk-<public_id>` for offline search. Actions made offline queue in IndexedDB with a client UUID and replay in order when back online. The server is idempotent: one check-in per day, walk-in by phone. A banner shows "3 pending sync". Cached data is wiped on logout, on **Clear offline data**, and automatically 24 h after the event.

### 13.12 Karaoke DJ console (`/dj`)

Now-on-stage card, Up-next card, queue (drag handles), statuses (Up next / On stage / Done / Skip / No-show), **Add singer** (search attendee + song), stats (performed / remaining / estimated time left = Σ durations + 1 min changeovers).

### 13.13 Studio (`modules/special_events/index.php`)

- **Look**: the ERP's style (white cards, `rounded-3xl`, `hodBlue` accents, Montserrat/Inter from `header.php`) so it feels native, plus the event's colours in previews.
- **Home**: event cards (cover from the OG/hero asset, status pill, dates, registered/capacity ring, checked-in) · **New event** · **Clone** · filters (status, series).
- **Event workspace**: tab rail (desktop) / scrollable tabs (mobile): Overview · Details · Brand · Registration · Check-in · Programme · Teams · Karaoke · Games · Assets · Crew · Messages · Attendees · Live · Insights · Hand-off · Settings. Tabs are deep-linkable (`#tab=brand`) through `window.switchTab()` (tab-deeplink.js), and the Ctrl/Cmd+K index gets "Special Events" plus these tabs (§21).
- **Save model**: each tab has a sticky save bar ("Unsaved changes · Save · Discard"). Saves send `expected_row_version`; a conflict shows a diff dialog ("Someone saved Brand 2 minutes ago — reload or overwrite?").
- **Live preview drawer** (Details, Brand, Registration, Programme): an iframe of `/e/<slug>?preview=<preview_key>` in a phone/desktop/stage frame. Unsaved brand edits are pushed into it by `postMessage({type:'se:theme', tokens})`; the portal only listens to its parent's origin.
- **Overview**: readiness ring + checklist (Appendix H.1), key dates, quick links (portal, check-in poster PNG/PDF, stage & lobby links with copy buttons, consoles), KPIs, recent audit activity.
- **Brand**: hex inputs (swatch + text, paste-friendly), contrast report, AI suggestions grid, font pickers with live specimen, asset pickers (logo, hero image/video, OG).
- **Check-in**: the welcome-verse list (§10.9) with AI suggestions, KJV lookup and approve, plus the A4/A3 QR posters (§14.2) and the live door counters. Everything about the moment somebody walks in lives here, so the desk crew has one tab to open.
- **Programme**: the publish bar first (green/amber — it is the one thing about a run of show a producer gets wrong), then the run of show, the **programme poster** (§14.2b) and the AI import panel.
- **Teams**: count, "paste hex codes" box, per-team rows (swatch, hex, label, name, captain), similarity/contrast warnings, roster view.
- **Live**: screen links with one-click rotation, the test-mode toggle and Reset rehearsal (§11.13), and the live monitor (§18.3) polling every 5 s while the tab is visible.
- **Modals** follow the shared contract: overlay element with `data-app-modal` (or an id containing "modal") and `position: fixed`, a single `data-modal-panel` child, `.hidden` toggled for visibility. `assets/js/modal-manager.js` then provides centring, scroll lock, inert background and focus handling. Drawers (side panels) use `justify-end` so the manager ignores them.

### 13.14 Accessibility (WCAG 2.2 AA)

- Contrast from the theme engine: text ≥ 4.5:1, large text/UI ≥ 3:1.
- Visible focus (3 px `--se-focus` ring, offset 2 px) on every interactive element; logical tab order; skip link on the portal.
- Sheets/modals: `role="dialog" aria-modal="true"`, labelled; focus trapped and restored.
- Live regions: quiz countdown announces at 10 s and 5 s (`aria-live="polite"`); results announce once.
- Answer tiles are buttons with `aria-label="Answer A: Jonah"`; shapes and letters ensure **colour is never the only cue**; teams always show a name/label.
- Inputs: visible labels, `autocomplete` tokens, errors linked via `aria-describedby`, ≥ 16 px.
- Targets ≥ 48×48 px; the BuzzButton is huge.
- Reduced motion and reduced transparency honoured (§13.1.2, §13.1.4).
- Media: hero video is decorative (muted, `aria-hidden`); voice-over audio has a transcript; alt text is required for every uploaded image (the Studio blocks saving without it).
- `lang="en-NG"`; dates/times via `Intl.DateTimeFormat('en-NG', { timeZone: 'Africa/Lagos' })`.

### 13.15 Performance budgets and device matrix

| Budget (portal, first visit, mobile) | Target |
|---|---|
| LCP (slow 4G, mid Android) | ≤ 2.5 s (the server-rendered hero text is the LCP element) |
| INP | ≤ 200 ms |
| CLS | ≤ 0.05 |
| JS (gzip) on first load | ≤ 90 KB (Preact+signals+htm ~15, GSAP+ScrollTrigger+SplitText ~45, app ~30); games code loads only in `/play` (≤ 40 KB more) |
| CSS (gzip) | ≤ 25 KB |
| Fonts | 2 families, ≤ 4 weights, WOFF2, display font preloaded |
| Hero video | ≤ 2.5 MB, 720p H.264, ≤ 15 s, poster ≤ 120 KB (WebP) |
| Snapshot JSON | ≤ 8 KB (public), ≤ 6 KB (room) |
| Lighthouse (mobile) | Performance ≥ 90, Accessibility ≥ 95, Best Practices ≥ 95 |

**Device matrix** (manual QA before Chara): iPhone SE 2nd gen (iOS 16.4+), iPhone 12/13 (iOS 17+), Samsung Galaxy A12/A14 (Chrome), a Tecno/Infinix ~3 GB RAM phone (Chrome, very common locally), an older Android 10 device (Chrome); Samsung Internet; desktop Chrome/Edge/Safari for stage, studio, console; the venue projector + laptop at its native resolution.

---

## 14. Share cards and the Format Studio

### 14.1 Brand asset kit

Studio → **Assets**. Every file is an `se_assets` row with a **role**:

| Role | Kind | Limits | Processing |
|---|---|---|---|
| `logo`, `wordmark` | image (PNG/WebP/SVG) | ≤ 5 MB | raster: GD re-encode to WebP + PNG; SVG: sanitised (§19.6) |
| `hero` | image | ≤ 8 MB, ≥ 1600 px wide | WebP variants 480/960/1600/2400 |
| `hero_video` | video (MP4 H.264) | ≤ 25 MB, ≤ 20 s | stored as is. **Poster**: the Studio grabs a frame client-side (`<video>` → canvas at 1 s) and uploads it as the poster (no ffmpeg on the host). |
| `flyer` | image/PDF | ≤ 15 MB | image variants; PDF stored as is |
| `illustration`, `background`, `gallery`, `sponsor` | image | ≤ 8 MB | variants |
| `sfx`, `music` | audio (MP3/M4A) | ≤ 10 MB | stored as is (magic-byte check) |
| `lottie` | JSON | ≤ 1 MB | JSON parse check |
| `template` | SVG (Format Studio template) | ≤ 2 MB | sanitised; tokens indexed in `meta_json` |
| `program_source`, `songs_source` | image/PDF (AI input) | ≤ 15 MB | kept 30 days then purged by cron |
| rendered outputs (`og_card`, `story`, `square`, `portrait`, `projector`, `poster_a4`, `poster_a3`) | PNG (+ PDF for posters) | — | written by Format Studio |

Storage: `/uploads/se/<public_id>/<role>/<yyyymmdd>-<8 hex>.<ext>`. File names are never derived from user input. `uploads/` is git-ignored and preserved across deploys. `uploads/se/.htaccess` (created by the code on first use, and also documented for manual creation) denies script execution and sets `nosniff` (§19.6).

### 14.2 Format Studio (auto formats)

**Goal**: one click turns the event's data + palette + brand kit into ready-to-post graphics in every size Envision needs, so designers only design once.

**Built-in templates** (`assets/se/templates/*.svg`):

| Key | Size (px) | Use |
|---|---|---|
| `wa_status` | 1080 × 1920 | WhatsApp Status / Instagram Story |
| `ig_square` | 1080 × 1080 | Instagram/Facebook feed |
| `ig_portrait` | 1080 × 1350 | Instagram portrait |
| `og_card` | 1200 × 630 | Link preview (set as the event's OG image with one click) |
| `projector` | 1920 × 1080 | Stage standby slides |
| `qr_poster_a4` | 2480 × 3508 (300 dpi) | Check-in poster (downstairs) — QR → `/e/<slug>/in` |
| `qr_poster_a3` | 3508 × 4961 (300 dpi) | Large check-in poster |
| `table_tent` | 1748 × 2480 (A5, 300 dpi) | Optional tabletop "Join the games" card — QR → `/e/<slug>/play` |

**Rendering pipeline (client-side, in the Studio browser).** The server has no headless browser; client rendering gives pixel-perfect typography.
1. Load the template SVG text.
2. Resolve tokens (§14.4) from the event data: text, palette colours, team colours, images converted to **data URIs** (Safari won't load external images inside an SVG drawn to canvas), and QR codes as SVG paths (`qrcode-generator`, error correction H).
3. Embed the event fonts as `@font-face` with WOFF2 data URIs inside the SVG `<style>` (fetched from Google Fonts, which allows CORS for font files).
4. Lay out text: wrapping and auto-fit inside the token's box (§14.4, using `canvas.measureText` with the loaded fonts).
5. Serialise → `Blob('image/svg+xml')` → `Image` → `canvas` at 1:1 → `canvas.toBlob('image/png')`.
6. Upload with `render_save` → stored as an asset with the template's role. Posters also get a PDF via dompdf (PNG embedded full-bleed at the right paper size) for printing.

The Studio shows all formats as a grid with **Render all**, **Download zip** (client-side zip of the PNGs; JSZip is vendored for the Studio only), **Set as link preview** (OG) and **Use on stage** (adds to the standby loop).

### 14.2b Programme poster (A4 / 16:9)

**Goal**: the run of show as one picture — printed for the door, or on the lobby TV — without a designer and without anything being cut off.

Studio → Programme → **Programme poster**. Two shapes only: `a4` 2480 × 3508 (210 × 297 mm at 300 dpi) and `screen` 1920 × 1080. **Download JPEG** is the primary action (PNG is next to it); the file is `<slug>-<edition>-programme-<a4|screen>.jpg` and nothing is uploaded — a poster is reprinted every time the programme changes, so it is not worth an asset row.

Server: `program_poster_data {id}` (`insights.view`) → `se_program_poster_payload()` in `cards.php`. It returns the **public** programme from `se_program_public()` (so the poster can never show a crew-only item), the day's date/doors line, `se_card_colors()`, the event fonts, the hero asset's path and the portal URL for the QR. The publish gate is deliberately **not** applied: crews print the running order long before the page may show it.

Client: `assets/se/js/studio/program_poster.js`. Unlike every other render in §14.2 there is **no `se__*` SVG template** — a programme is a list of unknown length, and fixed tokens cannot answer "does all of it fit?". The module builds the SVG source itself, then reuses the §14.2 pipeline from step 3 (`embedFontCss` → `rasterise` → `shareOrDownload`).

- **Fitting.** `planPosterRows(count, avail, metrics)` picks the row height that makes every item fit the page, capped so six items do not become six slabs; past a per-format threshold (18 on A4, 8 on screen) it opens a second column. Then two passes settle the time column (as wide as the widest time), the title size (shrunk until the *longest* title fits its column) and the time size. Blurbs are drawn only while rows are tall enough; the host name moves to the right of the title when they are not. Nothing is ever dropped — it gets smaller.
- **Measuring.** `makeMeasurer()` uses a canvas 2D context, so `primeFonts()` first injects the already-fetched `@font-face` CSS into the Studio document and awaits `document.fonts.load()`. Without it the browser measures Arial and lays out for a face a third narrower. With no metrics at all (node tests, a blocked font) it falls back to a deliberately generous average-advance estimate.
- **Background.** The portal hero asset, fetched same-origin and inlined as a data URI (an SVG drawn to a canvas cannot fetch), `xMidYMid slice`, under a palette scrim plus a primary/secondary glow. No hero: the gradients alone.
- **JPEG.** `rasterise(svg, w, h, {type, quality, background})` — the options argument is new and optional, so the §14.2 and §14.3 callers still get a PNG. JPEG has no alpha, hence the opaque `background` fill before `drawImage`.
- Tests: `tests/special_events/js/program_poster.test.mjs` draws both formats for 1 … 40 items and asserts every box and baseline lands inside the canvas.

### 14.3 Personal share cards

Rendered **on the attendee's phone** with the same engine. Data comes from `public_api action=card` (§12.2). Defaults are 1080 × 1920; a "Square" toggle switches to 1080 × 1080.

| Card | When | Content |
|---|---|---|
| **I'm going** | after registering (and on the manage page) | wordmark, "I'm going!", first name, date · time · venue, short URL, QR to the guest's **referral link** (`?r=`), organiser credit. **Optional photo**: if the guest adds a photo, the template's photo **circle** (ring in `--se-primary-raw` + glow) shows it; without a photo, the photo-less layout is used (`se__if__has_photo` / `se__ifnot__has_photo` groups). |
| **Welcome verse** | at check-in (and in `/play` → Me) | "Dear Ada," + KJV verse text + reference + prayer line + script signature "Chara 2026 by Envision" (M8), team colour accent. Same spirit as the Exousia card. |
| **Team** | after check-in | team colour field, team name/label, "#47", first name, event wordmark |
| **My Night** | after the event (manage link → recap) | team final rank and points, personal quiz points/rank, correct answers, MVP badge (if any), song sung (if any), "Thank you for bringing the joy" |

**Photo circle cropper** (`PhotoCircleCropper`):
1. `<input type="file" accept="image/*">` (no `capture` attribute, so people can choose the camera or the gallery).
2. `createImageBitmap(file, { imageOrientation: 'from-image' })` respects EXIF rotation. Downscale to ≤ 1600 px.
3. A circular mask preview: drag to move, pinch to zoom (two pointers), slider fallback, **Reset**. Export the circle's square bounding box as a JPEG data URI (q 0.9).
4. Insert into the template's `se__image__photo` element (which sits under a circle `clipPath`).
5. **The photo never leaves the device** (no upload, no storage). The UI says so: "Your photo stays on your phone."

**Save/share** (same proven approach as `checkin.php`): build a `File` from the PNG blob. If `navigator.canShare({ files })` → `navigator.share({ files, title })`; otherwise download via an `<a download>`. File name: `<slug>-<edition>-<card>-<firstname>.png` (slugified).

### 14.4 SVG template authoring rules (for Envision designers)

Designers make templates in Figma/Illustrator and export SVG with layer ids enabled ("Include id attribute"). Tokens are carried by **layer names**, exported as ids:

| Layer name / id | Meaning |
|---|---|
| `se__text__<field>` | Text element whose content is replaced. Fields: `title`, `edition`, `tagline`, `date`, `time`, `venue`, `url`, `first_name`, `dear_name`, `verse_text`, `verse_ref`, `prayer`, `signature`, `team_name`, `player_no`, `stat_1…4`, `organizer`, `headline`, `cta`. |
| `se__box__<field>` | Invisible rectangle defining the wrapping/fit box for `se__text__<field>`. Optional attributes in the layer description are not exported, so fit rules are given by the suffix: `se__box__verse_text--wrap-6` (wrap, max 6 lines, shrink to fit), `--fit` (single line, shrink to fit). |
| `se__fill__<role>` | Fill set from a token: `primary`, `secondary`, `accent`, `bg`, `surface`, `text`, `team`, `team_on`, `glow`. Suffix `--N` allows duplicates (ids must be unique). |
| `se__stroke__<role>` | Stroke colour token. |
| `se__stop__<role>` | Gradient `<stop>` colour token. |
| `se__image__<slot>` | `<image>` replaced with a data URI: `photo`, `hero`, `logo`, `wordmark`, `illustration`. |
| `se__qr__<target>` | `<rect>` placeholder replaced by a QR of the same box: `checkin_url`, `portal_url`, `play_url`, `ref_url`. |
| `se__if__<flag>` / `se__ifnot__<flag>` | Group shown/hidden by a flag: `has_photo`, `has_team`, `has_song`, `is_mvp`, `multi_day`. |

Rules: artboard at the exact output size; text as live text (not outlined) in one of the event fonts; no `<foreignObject>`, no scripts, no external links; images embedded or placeholders only; keep safe margins (64 px stories, 48 px squares; 10 mm bleed on posters). The Studio validates uploaded templates and lists recognised tokens before accepting.

---

## 15. AI layer

### 15.1 Architecture

```php
/**
 * Run an AI task and return validated, post-processed data.
 * @throws SeAiException  code ∈ AI_UNAVAILABLE | AI_INVALID_OUTPUT | AI_LIMIT | AI_TIMEOUT
 */
function se_ai(PDO $pdo, string $task, array $input, array $ctx): array;
```

- **Provider adapter**: `SeAiGemini` (only one in v1), behind `interface SeAiProvider { public function generate(array $request): array; }`. A future provider means one class and a config switch (D14).
- **Endpoint**: `POST https://generativelanguage.googleapis.com/v1beta/models/<model>:generateContent`, header `x-goog-api-key: <GEMINI_API_KEY>` (header, not URL query, so the key never lands in logs), `Content-Type: application/json`.
- **Models**: `SE_AI_MODEL_TEXT` and `SE_AI_MODEL_VISION` from `.env`, both defaulting to `gemini-2.5-flash` (the model the codebase uses today). Verify the current model names when implementing; they are configuration, not code.
- **Request**:

```php
[
  'systemInstruction' => ['parts' => [['text' => $prompt['system']]]],
  'contents' => [['role' => 'user', 'parts' => $parts]],   // text + optional inlineData {mimeType, data: base64}
  'generationConfig' => [
      'temperature' => $prompt['temperature'],
      'responseMimeType' => 'application/json',
      'responseSchema' => $prompt['schema'],               // OpenAPI-subset schema (Appendix D)
      'maxOutputTokens' => $prompt['max_tokens'],
      'thinkingConfig' => ['thinkingBudget' => $prompt['thinking_budget']], // only when the model supports it
  ],
]
```

- **Response**: concatenate `candidates[0].content.parts[*].text`, `json_decode`, then validate against the task's schema with `se_schema_validate()` (types, required keys, enums, string lengths, array bounds). Then run task post-processing (hex normalisation, KJV fetch, dedupe, mapping). Failure → one automatic retry with a non-sensitive "Your previous output was invalid because …" hint → then `AI_INVALID_OUTPUT`. When Gemini reports `finishReason = MAX_TOKENS` or usage reaches the configured ceiling, the retry also raises the output-token budget so it is not a repeat of the same truncation.
- **Reasoning budget**: for extraction tasks (programme, songs, clustering) latency matters more than deep reasoning. If the configured model supports a thinking budget (`generationConfig.thinkingConfig`), set a low budget per task in the prompt front matter (`thinking_budget`). Simple copywriting uses `thinking_budget: 0` on Gemini 2.5 Flash so visible output tokens are spent on the reviewed variants, not hidden reasoning. Verify support for the chosen model; omit the field otherwise.
- **Timeouts/retries**: text 30 s, vision 60 s (cURL `CURLOPT_TIMEOUT`). One retry on HTTP 429/500/503 after 1.5 s. Missing key or cURL → `AI_UNAVAILABLE`, and the UI hides the AI buttons with a tooltip.
- **Limits**: per user 30 calls/hour, per event 300/day (`se_ai_requests` counts). Images are downscaled client-side to ≤ 2048 px (JPEG 0.85) before upload. PDFs ≤ 10 MB. Total request ≤ 18 MB.
- **Logging**: every call writes `se_ai_requests` (user, event, task, model, prompt version, token counts from `usageMetadata`, latency, ok/error). This request log never stores raw prompts, raw outputs, secrets or attendee data. Review jobs may store the already-validated result needed for a human Apply step, but PHP/error logs must never include prompt text or model output.
- **Privacy rule (MUST)**: AI inputs never contain names, phones or emails of attendees. Feud clustering sends anonymous answer strings only. The report narrative sends aggregates only.
- **Prompts**: `includes/special_events/prompts/<task>.md` with front matter (`version`, `temperature`, `max_tokens`, optional `thinking_budget`) and a system prompt. Schemas live next to them as `<task>.schema.json`. Changing a prompt bumps its version (logged per call).
- **Human in the loop**: AI output is **never applied automatically**. Every task ends in a review UI (diff/preview, edit, then Apply), and applied rows are marked `source = 'ai'`.

### 15.2 Palette suggestions (`palette_suggest`)

- Input: `{primary, secondary, accent?, mood: ["joy","night","karaoke"], preset: "marquee"}`.
- Output: 4 × `{name, rationale (≤ 120 chars), accent, background_hint, team_suggestions?: [4 hex], mood_words: [3]}`.
- Post: P and S forced unchanged; every hex validated; tokens derived by §13.2.2 (ignoring the AI's background except as a hue hint, which is only used if contrast rules pass); contrast report attached.

### 15.3 Programme extraction (`program_extract`)

- Input: pasted text **or** an image/PDF (`inlineData`), plus `{event_days: [{date, starts_at, ends_at}], kinds: [enum list]}`. Studio uploads image/PDF sources directly as the event's `program_source` asset through the normal magic-byte, size and sanitisation checks; a source id is accepted only for that event and role.
- Output: `{items: [{day_index, title, kind, start_time?: "HH:MM", end_time?: "HH:MM", duration_min?, host?, notes?, confidence: 0–1}], warnings: []}`.
- Post: map kinds to the enum (`other` fallback), calculate written time ranges first, then derive missing durations from the next start. The producer can edit title, kind, day, start, duration and append/replace mode in the review table; no row is created before **Apply**. If AI is unavailable, the UI keeps paste and manual-row options visible and explains the fallback rather than leaving a blank panel.

### 15.4 Deck generation (`deck_generate`)

- Input: `{content_type, count (1–30), topic (e.g. "Old Testament heroes", "Joy"), difficulty_mix: {easy: 40, medium: 40, hard: 20}, audience: "mixed church members and first-time guests, ages 16–45, Lagos", translation: "KJV", avoid: [short list of recent prompts/answers from the series]}`.
- Output per content type (Appendix C): MCQ `{prompt, choices[4], answer_index, explanation, ref}`; charade `{phrase, category, hint, ref?}`; clues `{clues[5], answer, accept[], ref}`; emoji `{emojis, answer, accept[], ref}`; verse `{ref}` (verse text fetched, never generated); open `{prompt, answer, accept[], ref}`; survey `{question}`.
- Post:
  1. Validate `ref` format (`Book Chapter:Verse[-Verse]`) against the canonical book list.
  2. **Fetch KJV text** (§15.9) for every `ref`. Unknown reference → flag ✗ "reference not found"; the item cannot be approved until fixed.
  3. For `verse` items: compute `lead` (first ~55–65 % of words) and `answer` (the rest) from the **fetched** text, plus 3 distractor endings for MCQ mode from other fetched verses.
  4. Dedupe against the library (normalised prompt/answer similarity ≥ 0.85 → "duplicate" flag).
  5. Shuffle MCQ choices (store the new `answer_index`).
- Review UI: each item card shows the KJV text, a ✓ approve / ✎ edit / ✗ reject control, and "Regenerate this one". Only approved items can be added to games.
- **Sensitivity rule** (in the system prompt and the review checklist): questions must be respectful and Scripture-faithful; no denominational controversy; no trick questions on disputed interpretations; light, inclusive humour only.

### 15.5 Song list extraction (`songs_extract`)

- Input: image/PDF/text of a song list. Output: `{songs: [{title, artist, duration?: "m:ss"}], warnings: []}`.
- Post: normalise, parse durations, dedupe, compare with the library (§10.8.1 preview).

### 15.6 Family Feud clustering (`feud_cluster`)

- Input: `{question, responses: [{id, text}]}` (anonymous; ≤ 400 responses; long lists are pre-grouped by exact normalised text with counts).
- Output: `{clusters: [{label (≤ 24 chars, title case), response_ids: [...]}], junk_ids: [...]}`.
- Post: counts = number of responses per cluster; sort desc; top `board_size_max` → draft board; crew edits/approves.

### 15.7 Verse suggestions (`verses_suggest` + `verses_accept`)

- Input: `{theme: "joy", count: 12, translation: "KJV", tone: "warm, celebratory"}`. Output: `{verses: [{ref, why (≤ 80 chars), prayer_template (≤ 160 chars, must contain "{name}")}]}`.
- Post: fetch KJV text per ref; drop unknown refs (with reasons); nothing is saved. The Studio opens a **picker modal** where crew tick verses, may edit a reference (the KJV text re-fetches live via `bible_lookup`) or the prayer line, and add.
- Accepting IS the §15.9 human review: `verses_accept` re-looks-up every (possibly edited) reference server-side — client-sent text is never trusted — inserts approved with `suggested_by_ai = 1` (migration `20261103090000`), returns each failed pick with a reason, and writes one audit row (`verse_save:accept_ai`) naming the suggestion job. Text always comes from the lookup service (`text_source = 'lookup'`), so the single-approval rule stays sound. Hand-added references keep the separate Approve step.

### 15.8 Copywriting (`copywrite`)

- Purposes: `tagline` (≤ 12 words), `description` (≤ 120 words), `activity_blurb` (≤ 25 words), `faq_answer`, `sms_reminder`/`sms_thanks` (GSM-7 only, ≤ 150 chars excluding `{{link}}`), `card_headline`.
- Input: event facts (title, edition, date, venue, activities, tone words). Output: `{variants: [3 strings]}`. The schema requires exactly three strings and permits enough characters for a full 120-word portal description; the prompt budgets 4096 visible output tokens and disables Gemini 2.5 Flash thinking for this simple task. SMS variants are checked with `sms_segments()` and must be GSM-7 and ≤ 2 pages including a typical link.
- **Prompt (v3) is purpose-aware.** `SE_COPYWRITE_GUIDANCE` gives each purpose its own craft note, `SE_COPYWRITE_ANGLES` names a different angle for each of the three options (guest-first invitation / activity-forward / community and belonging, for `description`), `SE_COPYWRITE_WORD_TARGETS` sets the 70–110-word window for `description`, and `SE_COPYWRITE_BANNED_PHRASES` lists the filler the prompt forbids. The prompt also forbids restating the event tagline, emoji, hashtags and ALL-CAPS, and repeats the "no personal data" rule. `se_ai_copywrite_guidance()`, `se_ai_copywrite_angles()`, `se_ai_copywrite_length_note()` and `se_ai_copywrite_banned_list()` render those into the template; an unknown purpose falls back to neutral text rather than failing.
- **Post-processing (`se_ai_copywrite_variants()`) is gentle by design.** After schema validation it clamps to the purpose's character cap, removes a sentence that is only the tagline echoed back (exact match after case/punctuation normalisation, and only when the variant has more than one sentence), then drops near-duplicates using `se_ai_copywrite_similarity()` — a Jaccard overlap of stop-word-filtered word sets, threshold `SE_COPYWRITE_DUPLICATE_THRESHOLD` (0.72). It **never** rewrites wording and never returns an empty list when the model returned something, so a strict threshold can only reduce the choice on offer (the Studio then shows two options), never turn a successful generation into an error. Every surviving string still goes to the human-review list (§15.1).

### 15.9 Bible lookup (not AI)

`se_bible_lookup(PDO $pdo, string $ref, string $translation = 'KJV'): ?array` returns `{ref_display, text}`:
1. Normalise the reference (`ref_norm`, e.g. `john 3:16`, `ps 100:1-2`; books from a canonical list with common abbreviations).
2. Check `se_bible_cache`.
3. Else `GET {SE_BIBLE_API_BASE or https://bible-api.com}/<url-encoded ref>?translation=kjv` (5 s timeout). Read `text` (trimmed, whitespace collapsed) and `reference`.
4. Cache forever.

It is used at **authoring time only** (Studio), never during the live event. If the service is down, the Studio allows pasting the verse text manually, marked "manual" and requiring a second crew approval.

### 15.10 Report narrative (`report_summary`)

Input: aggregate metrics only (§18). Output: `{headline, paragraphs[3], highlights[5], recommendations[3]}`. Used in the PDF report (§18.5). Same approach as the existing `reach_report_pdf.php` (falls back to a template text if AI is unavailable).

---

## 16. Messaging (SMS)

### 16.1 Principles

- All SMS go through **SMS Studio's tables and worker**: the module creates an `sms_campaigns` row and `sms_queue` rows; `cron/sms_queue_worker.php` sends via `sms_send_one()`, which applies the spam guard, suppression, logging and delivery reports. The module **never** writes `sms_log` (AGENTS.md).
- Campaign fields: `audience = 'special_event'`, `event_id = NULL` (standalone, D2), `filters_json = {"se_event_id": <id>, "kind": "<kind>"}`, `title = "<Event> · <Kind label>"`, `created_by` = the actor (or the event's producer for cron runs).
- Queue `recipient_json`: `{"source": "se_registration", "source_id": <registration_id>, "name": "...", "first_name": "...", "last_name": "...", "phone": "<234…>", "event_title": "...", "link": "<per-recipient URL>"}`. `{{link}}` needs the small `sms_render()` extension (§21.2).
- **Service vs marketing**: reminders, waitlist promotions, requested links and the thank-you note are **service messages** about the person's own registration. Any other follow-up is the receiving department's job after hand-off. Contacts with `opted_out_at` receive nothing from this module.
- Before any automatic run, `sms_health($pdo)` must show the worker heartbeat < 3 min old. Otherwise the run still enqueues (the worker sends once it recovers) and the console/Studio show a red warning.

### 16.2 Message kinds

| Kind | Default | Audience | Timing |
|---|---|---|---|
| `reminder_1` | ON | registrations `confirmed` (any pool), not opted out | day before the first day, `settings.messages.reminder_1.at` (default 18:00) |
| `reminder_2` | ON | `confirmed`, not yet checked in | `starts_at − settings.messages.reminder_2.minutes_before` (default 120) |
| `thank_you` | ON | checked in at least once | the day after the last day at `settings.messages.thank_you.at` (default 09:00) |
| `waitlist_promotion` | ON (if waitlist on) | the promoted person | immediately after promotion |
| `link_on_demand` | ON | the requester (existing registration only) | on request, rate-limited (§12.1) |
| `adhoc` | manual | a chosen segment | when the Producer confirms |

Multi-day events: `reminder_2` runs per day for people not yet checked in that day; `thank_you` runs once after the last day.

### 16.3 Templates and merge fields

- Event-level fields are substituted by the module **before** enqueueing, so the stored campaign template is already event-specific: `{{event_title}}` (title + edition), `{{date}}` ("Sat 24 Oct"), `{{time}}` ("5:00 PM"), `{{venue}}` (venue name), `{{map}}` (short map link if set).
- Per-recipient fields are rendered by `sms_render()` in the worker: `{{first_name}}`, `{{link}}`.
- Links: `reminder_1`/`reminder_2`/`waitlist_promotion`/`link_on_demand` → a fresh manage token per recipient (`/e/<slug>/me/<token>`); `thank_you` → manage token + `#recap`.
- Default templates are in Appendix E (`messages.<kind>.template`), with their length check. The editor shows a live preview with a sample person, GSM-7 cleaning (same table as `sms_gsm_clean()`), the segment count via the JS port of `sms_segments()`, and **estimated units = Σ pages over the audience**.

### 16.4 Scheduling (cron, every 5 minutes)

`cron/special_events.php` (CLI only; sets `$_SERVER['DOCUMENT_ROOT']` before requiring `includes/db.php`, like the other cron files):

```
for each event with status published and an enabled message kind due (scheduled_for <= now) and no se_message_runs row for its run_key:
    BEGIN
      INSERT se_message_runs (event_id, kind, run_key, scheduled_for, status='queued')   ← UNIQUE(event_id, run_key) → only one cron run wins
      build audience (SQL per kind, exclude opted-out contacts, dedupe by phone, valid NG mobiles only)
      issue a manage token per recipient
      INSERT sms_campaigns (…, status='queued') ; INSERT sms_queue rows (chunks of 200)
      UPDATE se_message_runs SET sms_campaign_id, recipients, est_units
    COMMIT
skip (status='skipped', detail) when the audience is empty or the event was cancelled
```

- If the scheduled time passed by more than `settings.messages.max_lateness_min` (default 90) — e.g. the cron was down — the run is **skipped** (a reminder 2 h *after* the start is worse than none) and the Producer is notified.
- The Studio's Messages tab lists runs with status, counts and a link to the SMS Studio campaign history (`/modules/sms_studio/index.php?campaign=<id>`).

### 16.5 Waitlist promotion and on-demand links

Same mechanism, single-recipient campaigns (`run_key = waitlist:<registration_id>:<n>` / `link:<registration_id>:<YYYYMMDDHH>`), enqueued right after the triggering transaction commits.

### 16.6 Ad-hoc messages

Producer-only. Segments: `confirmed`, `waitlisted`, `checked_in`, `confirmed_not_checked_in`, `karaoke_singers`, `cancelled`. Flow: write template → preview with a sample → **Confirm** dialog stating recipients and estimated units → enqueue (`run_key = adhoc:<timestamp>`). Use cases: venue change, cancellation notice, "doors open in 30 minutes".

---

## 17. Hand-off to Reach and Embrace

### 17.1 Principles

- Explicit, reviewable, **idempotent** and **audited** (D17). Nothing reaches another department without a human pressing **Push**.
- Follow the receiving module's own data rules. Reach leads look exactly like leads Reach creates; Embrace first-timers look exactly like `push_to_embrace` users.
- **Never duplicate people**: match existing `users` by phone (same rule as Reach's push: `phone LIKE %<last 9 digits>%`).

### 17.2 Who goes where (defaults; every rule is a wizard toggle)

| Person | Default destination | Notes |
|---|---|---|
| Guest (not a member), checked in, consent given, `wants_visit = 1` (registration, success screen or feedback) | **Embrace** (1st-timer queue) | If their phone already matches a `users` row → outcome `linked_existing`, no insert |
| Guest, checked in, consent given, `wants_visit = 0` | **Reach** campaign lead | |
| Guest without consent (optional-consent mode only) | **excluded** (`skipped_no_consent`) | |
| Registered but never checked in | excluded (toggle "Include registered no-shows" → Reach, note "Registered, did not attend") | |
| Member (`is_member = 1`) | not pushed (`skipped_member`) | Listed in insights as "members who came" |
| Already handed off for this event | skipped (`already_handed_off`) | |
| Opted out | excluded | |

### 17.3 Reach mapping

**Campaign** (created once per event; reused on re-runs; stored in `se_handoffs.reach_campaign_id`):

| `reach_campaigns` column | Value |
|---|---|
| `slug` | `se-<event slug>-<YYYYMMDD of first day>`, made unique by appending `-2`, `-3`… (same loop as `reach_unique_slug`) |
| `title` | `<Title> <Edition> (Envision)` |
| `campaign_type` | `Special_Event` (seeded by migration; `Other` if missing) |
| `campaign_date`, `start_time`, `end_time` | first day date and times |
| `location` | venue name |
| `meta_description` | "Guests who came to <Title> <Edition>, handed off by Envision." |
| `payload_tier` | `Rapid` (no extra capture fields, consistent with `reach_sync_campaign_fields`) |
| `status` | `Active` |
| `created_by` | the actor |

**Lead** per person (`reach_leads`):

| Column | Value |
|---|---|
| `campaign_id` | the campaign |
| `first_name`, `last_name` | registration snapshot |
| `phone` | `0` + last 10 digits for NG numbers (`08031234567`); `+<e164>` for international |
| `category` | `Other` |
| `willing_for_visit`, `will_attend_church` | 0, 0 |
| `notes` | "Met at Chara 2026 (Envision). Team: Joy Bringers. Karaoke: yes. Heard via: Instagram. Feedback: 9/10, favourite: Charades." (only fields that exist) |
| `status` | `Not_Spoken_To` |

Plus one `reach_lead_captures` row: `captured_by_user_id = actor`, `captured_by_guest_name = 'Envision — <Title> <Edition>'`. Within a campaign, a phone that already has a lead is skipped (`already_handed_off`). Notify Reach managers (HOD/Director of the Reach/Evangelism department) via `reach_notify()`: "42 guests from Chara 2026 are waiting in a new Reach campaign".

### 17.4 Embrace mapping

`INSERT INTO users` with: `first_name`, `last_name`, `phone` (local format as above), `gender`, `email` (only if not `@hodlc.com`), `marital_status = 'Single'`, `physical_address = 'To be updated'`, `spiritual_status = '1st_Timer'`, `invitation_source = 'Other'` (Embrace's "Other (Special Event / HQ)"), `invited_by = 'Envision: <Title> <Edition>'`, `qr_code_hash = hash('sha256', bin2hex(random_bytes(16)) . <digits>)`.

- Do **not** call `embrace_auto_checkin_today()`: they attended an event, not a service.
- Notify Embrace leaders (`departments.name LIKE '%Embrace%'`, role HOD/Director) and IDI (department 1) for new profiles, mirroring `push_to_embrace`.
- The new `users.id` is stored on `se_handoff_items.target_id` **and** on `se_contacts.member_user_id`, so future events recognise them.

### 17.5 Wizard (Studio → Hand-off)

1. **Summary**: totals by category (attended guests, members, consenting, wants-visit, no-shows), feedback highlights.
2. **Rules**: toggles from §17.2 with live counts.
3. **Preview**: a table of every person with their destination (Reach / Embrace / excluded + reason); per-row override (move destination or exclude, with a reason); search.
4. **Push**: confirm dialog → server-side transaction per 50 people; progress bar; final report (created / linked / skipped by reason) with links to the Reach campaign and the Embrace queue.
5. **History**: every run with its counts. **Re-run** later (e.g. after late survey opt-ins) only processes people without a successful item.

### 17.6 Idempotency and audit

- `se_handoff_items` has a generated column `done_contact_key = IF(outcome IN ('created','linked_existing'), contact_id, NULL)` with `UNIQUE(event_id, done_contact_key)`. A second success for the same person cannot be written.
- Each run is one `se_handoffs` row (actor, time, rule snapshot in `summary_json`). Every item is logged. The audit log records `handoff_run`.

---

## 18. Insights and reporting

### 18.1 Metric definitions

| Metric | Definition | Source |
|---|---|---|
| Portal views | count of `beacon m=view` (per day, per source) | `se_metrics_daily` |
| Registration starts / completions | `beacon reg_start` / successful `register` (confirmed + waitlisted) | metrics / registrations |
| Completion rate | completions ÷ starts | — |
| Confirmed | registrations `confirmed` | `se_registrations` |
| Waitlist peak, promotions, cancellations | from timestamps/audit | registrations, audit |
| Show-up rate | registrations with ≥ 1 check-in ÷ confirmed online registrations (walk-ins excluded) | check-ins |
| Walk-ins | check-ins whose registration channel is `walkin_*` | — |
| Members vs guests, gender | at first check-in | registrations |
| Returning guests | contacts with a check-in at an earlier event of the same series | contacts ↔ check-ins |
| Check-in pace | check-ins per 5 minutes | `se_checkins.checked_in_at` |
| Games join rate | devices with `joined_games_at` ÷ checked-in registrations | devices |
| Answer rate / accuracy per question | answers ÷ eligible; correct ÷ answers | `se_answers` |
| Karaoke | interested, picked, performed, no-shows | registrations, entries |
| NPS | %promoters (9–10) − %detractors (0–6) | `se_feedback` |
| Hand-off | per destination/outcome | `se_handoff_items` |
| Follow-up outcomes (≥ 30 days later) | Reach leads by status; Embrace profiles' follow-up status; later church attendance of handed-off people at 30/60/90 days, computed **only** through `assim_attendance_union_sql()` (AGENTS.md rule) | Reach/Embrace/attendance union |

### 18.2 Attribution

- `?s=<code>` on any portal link is stored on the registration (`src`) and in view metrics. Default codes (editable): `wa` WhatsApp, `ig` Instagram, `fb` Facebook, `tt` TikTok, `x` X, `flyer`, `poster`, `sms`, `pulpit` (church announcement), `qr` (generic QR), `email`.
- The Studio's **Share kit** (Overview) generates the link + QR for each code. The whole kit is fetched in one `share_kit` call, but the panel opens showing only the two rows the crew reaches for every time — the plain link and the check-in poster QR (`PRIMARY_CODES` in `assets/se/js/studio/share_kit.js`) — with the rest behind **View more**. `splitShareLinks()` is pure and forgiving: a primary row the server did not send is skipped, and any code added later falls into the "more" group rather than disappearing.
- `?r=<ref_code>` stores `referred_by_registration_id` (top inviters are visible to crew only).

### 18.3 Live monitor (Studio → Live)

Check-in pace chart (5-min buckets), team balance bars (size and gender per team), joined-games %, karaoke queue length, snapshot age/version, cron and SMS health, and recent audit events. Auto-refresh every 10 s (Studio API, not snapshots).

### 18.4 Insights dashboard (Studio → Insights)

Sections: **Reach** (views, starts, completions by day and source; funnel), **Attendance** (confirmed vs show-up vs walk-ins; members/guests; gender; returning; pace), **Teams & games** (final standings, scores by game, participation, hardest/easiest questions, MVP), **Karaoke**, **Feedback** (NPS gauge, favourite moments, word cloud of one-word answers), **Hand-off & follow-up**. Charts use Chart.js with an accessible categorical palette that is **not** the team palette (team charts use team colours with labels). Every chart has a table view toggle.

### 18.5 PDF report (dompdf)

`report_pdf` → "<Title> <Edition> — Event Report" (A4):
1. Cover (event art, date, organiser).
2. At a glance (6 KPIs).
3. AI narrative (§15.10) or the template fallback.
4. Registration & attendance (tables + simple bar charts rendered as HTML/CSS bars, since dompdf cannot run JS).
5. Teams & games (standings, champion, MVP).
6. Karaoke.
7. Feedback highlights (anonymised quotes only).
8. Hand-off summary.
9. Recommendations.
10. Appendix: definitions.

No phone numbers or emails in the PDF.

### 18.6 Excel export (PhpSpreadsheet)

Sheet "Attendees": reg code, display name, first name, last name, phone (crew with PII capability only), email, gender, member/guest, status, pool, channel, source, referred by, karaoke interest/song/performed, team, player #, check-in times per day, wants visit, consent, feedback NPS/favourite, hand-off destination/outcome. Sheet "Check-ins", "Scores" (ledger), "Karaoke", "Feedback". The file name is `<slug>-attendees-<YYYYMMDD-HHmm>.xlsx`. Each export is audited.

### 18.7 Series insights

For events in a series: edition-over-edition registrations, show-up, returning guests %, NPS, champion teams (**Hall of Fame**).

---

## 19. Security and privacy

### 19.1 Threat model

| Asset | Threat | Mitigation |
|---|---|---|
| Attendee PII (names, phones, emails) | Scraping via public endpoints or snapshots | `public.json` has no names; names only in key-protected snapshots and only as "Ada O."; lookups return display names only, in-window and rate-limited; full PII only to crew with the PII capability (§6.2) |
| Seats | Overselling under concurrency; bot registrations | Event-row lock; honeypot + timing + rate limits; per-phone uniqueness |
| Someone's registration | Cancelling or hijacking by typing their phone | Phone alone never grants manage rights before the event (manage token required); duplicate same-day check-in from another device → read-only; desk transfer codes |
| Game integrity | Answering for others; multiple answers; pre-reading questions | One answer per registration per round (unique key); device bound to a checked-in registration; scoring window starts at `opens_at`; pre-reading accepted as low-impact (§8.5.5) |
| Crew powers | Unauthorised control of the show; CSRF | ERP session + capability per action + `X-SE-CSRF` + Origin checks; audit log |
| Display keys | Leaked stage/lobby URLs | Keys in URL fragment; read-only data; rotation in one click; lobby names toggle |
| SMS credit | Abuse of "Text me my link" | Strict per-phone/IP/device limits; only for existing registrations; identical public response (no enumeration) |
| AI credit | Abuse via the Studio | Logged-in crew only; per-user/event limits |
| Server | Malicious uploads (polyglots, SVG XSS, PHP upload) | Magic bytes + GD re-encode; SVG sanitiser; no execution in `uploads/se`; random names |
| Availability | Bursty polling exhausting PHP | Static snapshots; short requests; backoff; host throttle check |

### 19.2 Authentication and authorisation

- **Crew/Studio**: the existing ERP session (`includes/db.php` → `security_enforce_session()`), with capability checks from §6.2 in every action (`se_require_capability()`). `must_change_password` is honoured: crew pages under `/e/…/host|desk|dj` redirect to `/auth/change_password.php`, exactly as `header.php` does.
- **Attendees**: device cookie (§10.3.5) and manage tokens (§10.3.6). No passwords, no OTP in v1.
- **Displays**: room/lobby keys (read) and stage key (tick only).
- **Drafts**: hidden (404) unless crew or `?preview=<preview_key>`.

### 19.3 Request integrity

- Every POST: `X-SE-Request: 1`; `Origin` (if present) host must equal `HTTP_HOST`; `Sec-Fetch-Site` (if present) must be `same-origin` or `none`.
- Crew/Studio POSTs: `X-SE-CSRF` equal to `$_SESSION['se_csrf']` (compared with `hash_equals`).
- Cookies: device cookie `HttpOnly; Secure; SameSite=Lax`. The PHP session cookie stays as configured by the host.

### 19.4 Rate limiting and bots

- Fixed-window counters in `se_rate_limits` (§12.1 table). The helper `se_rate_limit(PDO $pdo, string $bucket, string $subjectHash, int $limit, int $windowSeconds): bool` uses `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1` and then reads `hits`.
- The registration honeypot field `website` (visually hidden, `tabindex=-1`, `autocomplete=off`) and the minimum fill time of 1.5 s trigger a silent fake success.
- Lookups never reveal more than a display name, and only to `purpose: register` (any time) or `purpose: checkin` (only inside the check-in window).
- Optional later: Cloudflare Turnstile on registration (roadmap), behind a setting.

### 19.5 Input validation and output encoding

- Server-side validation for every field (type, length, enum). Names: 1–80 chars, letters, spaces, `'`, `-`, `.` (Unicode letters allowed); trimmed; collapsed spaces; ucfirst on display.
- **Descriptions and FAQ answers are Markdown** (`description_md`), rendered server-side with `se_markdown()`, an escape-first renderer modelled on `reach_markdown()` (headings, bold, italic, lists, links with http(s)/relative only). Never store or echo raw HTML from users.
- Server-rendered HTML uses `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`. JSON in `<script type="application/json">` uses `json_encode` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- Preact escapes by default. `dangerouslySetInnerHTML` is allowed **only** for HTML produced by `se_markdown()` on the server.
- SQL: PDO prepared statements only (AGENTS.md). Dynamic `ORDER BY`/columns come from allowlists.

### 19.6 Uploads

1. Size limit per role (§14.1).
2. Detect type by **magic bytes**: `\x89PNG`, `\xFF\xD8\xFF` (JPEG), `RIFF….WEBP`, `GIF8`, `%PDF-`, MP4/M4A `ftyp` box at offset 4, MP3 (`ID3` or frame sync `\xFF\xFB|\xF3|\xF2`), SVG (XML parse, root `<svg>`), JSON (parse). The extension is derived from the detected type, never from the client name.
3. Raster images are **re-encoded with GD** (strips EXIF/GPS and defeats polyglots); variants are generated as WebP (`imagewebp`, quality 82), with JPEG fallback if WebP support is missing.
4. **SVG sanitiser** (`se_svg_sanitize()`): parse with `DOMDocument` (`LIBXML_NONET`, no external entities). Remove `<script>`, `<foreignObject>`, `<iframe>`, `<object>`, `<embed>`, `<use>` with external hrefs, every `on*` attribute, `href`/`xlink:href` not starting with `#` or `data:image/`, `<style>` containing `@import` or `url(` with remote URLs. Re-serialise.
5. Store under random names in `uploads/se/<public_id>/<role>/`. `uploads/se/.htaccess`:

```apache
Options -Indexes
<FilesMatch "\.(php|phtml|phar|pl|py|cgi|sh)$">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  <FilesMatch "\.svg$">
    Header always set Content-Security-Policy "default-src 'none'; img-src data:; style-src 'unsafe-inline'"
  </FilesMatch>
</IfModule>
```

### 19.7 HTTP headers for `/e/` pages

Sent by `e/index.php`:

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self' 'nonce-<NONCE>';
  style-src 'self' 'nonce-<NONCE>' https://fonts.googleapis.com;
  font-src 'self' https://fonts.gstatic.com data:;
  img-src 'self' data: blob: https://i.ytimg.com;
  media-src 'self' blob:;
  connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com;   (fonts are fetched to embed in share cards; + Ably origins only if the push driver is on)
  frame-src https://www.youtube-nocookie.com;
  frame-ancestors 'self';                                  (Studio preview iframe is same-origin)
  base-uri 'self'; form-action 'self'; object-src 'none'
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), screen-wake-lock=(self), fullscreen=(self)
X-Content-Type-Options: nosniff
Cache-Control: no-store                                     (HTML shell; it embeds per-device boot data)
```

Notes: GSAP and Preact change styles through the CSSOM, which CSP does not block. The `<style nonce>` carries the theme variables. The optional service worker is same-origin. The photo cropper uses only `<input type=file>`, so it needs no camera permission.

APIs send `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.

### 19.8 Privacy (Nigeria Data Protection Act 2023)

*This is an engineering summary, not legal advice. Leadership must approve the consent text and privacy notice before launch (§27).*

- **Controller**: Household of David Lekki Centre. **Processors/recipients**: hosting provider; BulkSMS (phone + message text); Google Gemini (no attendee personal data, by design); bible-api.com (no personal data). Internal recipients: Envision (event administration), Reach and Embrace (follow-up, through the hand-off only).
- **Lawful bases**:
  - Running the event (registration, check-in, reminders, safety): the person's request and the church's legitimate interest.
  - Follow-up by other departments: **consent**.
- **Consent modes** (`settings.registration.consent_mode`):
  - `required_followup` (**default, D7a**): a required tick: "I agree that HOD Lekki Centre may keep my details and contact me after this event (e.g. a thank-you and invitations to other events). I can opt out anytime." This bundles consent with attendance. **Leadership must confirm** they accept this mode.
  - `notice_plus_optional_optin` (privacy-preferred alternative): required acknowledgement of the privacy notice plus an **optional** tick for follow-up. The hand-off then excludes people without the tick.
- **Consent record**: `consent_followup`, `consent_followup_at`, `consent_text_hash` (sha256 of the exact text shown), event id (via the registration), IP HMAC.
- **Privacy notice** (`/e/<slug>/privacy`, Markdown, default text in the module settings): who we are, what we collect and why, who receives it, retention, rights, contact (an email address set in `se_settings.privacy_contact_email`).
- **Data minimisation**: no church affiliation, address or date of birth (M2); photos for share cards never leave the device; screens show "Ada O." only.
- **Retention** (cron): guests' contact data from events archived more than `retention_months_guest` (default 24) ago, without consent, are **anonymised**. Device rows deleted 60 days after the event. Tokens expire 30 days after the event. AI source uploads deleted after 30 days. Rate-limit rows deleted after 2 days.
- **Rights**:
  - Access/correction: on request, via the crew (Studio → Attendees).
  - Erasure: Studio → Attendees → **Erase** (§9.5).
  - Withdrawing consent: the manage page → **Stop contacting me** (`optout`), which sets `opted_out_at`, `consent_followup = 0` and excludes the person from future runs and hand-offs.
- **Children**: the portal states the event is for ages 16+ (configurable `settings.registration.min_age_note`). No age data is collected; under-16s attend with a guardian and are not registered separately.

### 19.9 Secrets

New `.env` keys (§20.1). `SE_HASH_PEPPER` (64 hex) is **required**; without it the module refuses to issue tokens and returns `SERVER_ERROR` with a clear log line. Tokens, codes and keys MUST never be written to `error_log`. Rotating `SE_HASH_PEPPER` invalidates all device cookies and manage links; only do it after an incident.

### 19.10 Audit log events

`event_create`, `event_update:<section>`, `event_publish`, `event_unpublish`, `event_cancel`, `event_archive`, `slug_change`, `slug_reclaim`, `override_set`, `capacity_change`, `register`, `register_bot_suspected`, `cancel`, `promote`, `checkin`, `checkin_undo`, `walkin_override`, `transfer_code_issued`, `device_transfer`, `team_assign`, `team_move`, `team_rename`, `captain_set`, `scene_set`, `program_op`, `game_op`, `round_op`, `score_award`, `score_void`, `karaoke_op`, `crew_add`, `crew_revoke`, `keys_rotate`, `export`, `handoff_run`, `erase`, `optout`, `ai_job_apply`, `messages_run`, `adhoc_message`, `test_mode`, `notice_sent:<kind>`, `client_error`.

---

## 20. Configuration reference

### 20.1 Environment variables (`.env`)

| Key | Required | Default | Purpose |
|---|---|---|---|
| `SE_HASH_PEPPER` | **yes** | — | HMAC key for device/manage tokens, IP/UA hashing. 64 hex (`php -r "echo bin2hex(random_bytes(32));"`). |
| `GEMINI_API_KEY` | for AI | (existing) | Reused by `se_ai()` |
| `SE_AI_MODEL_TEXT` | no | `gemini-2.5-flash` | Text tasks |
| `SE_AI_MODEL_VISION` | no | `gemini-2.5-flash` | Image/PDF tasks |
| `SE_REALTIME_DRIVER` | no | `poll` | `poll` or `ably` (v1: `poll`) |
| `ABLY_API_KEY` | only if `ably` | — | Push driver (roadmap) |
| `SE_BIBLE_API_BASE` | no | `https://bible-api.com` | Verse lookup |

### 20.2 Module settings (`se_settings`)

| Key | Default | Meaning |
|---|---|---|
| `envision_department_id` | `3` | Department granting studio membership |
| `default_brand_primary` / `default_brand_secondary` | `#1D356A` / `#D11920` | HOD blue/red for new events (until cloned) |
| `default_theme_preset` | `marquee` | |
| `default_privacy_notice_md` | (text) | Privacy notice template with `{event}` placeholders |
| `privacy_contact_email` | (set by admin) | Shown in the notice |
| `retention_months_guest` | `24` | §19.8 |
| `ai_user_hourly_limit` / `ai_event_daily_limit` | `30` / `300` | §15.1 |
| `cron_last_run` | (written by cron) | Health |
| `source_codes_json` | §18.2 list | Share-kit codes |

### 20.3 Per-event settings

Hot-path settings are columns on `se_events` (capacity, overrides, windows, brand, fonts, slug). Everything else is in `settings_json`, normalised by `se_settings_normalize(array $in): array` (unknown keys dropped, defaults merged, types coerced, enums checked). The complete default document is **Appendix E**, and it is the contract between the Studio and the server.

### 20.4 Constants (`includes/special_events/constants.php`)

`SE_ENVISION_DEPT_ID = 3`, `SE_RESERVED_SLUGS`, `SE_CREW_ROLES`, `SE_CAPABILITIES` (map role → capabilities), `SE_GAME_TYPES`, `SE_CONTENT_TYPES`, `SE_PROGRAM_KINDS` (`welcome, worship, prayer, word, game, karaoke, debate, break, food, announcement, performance, buffer, closing, other`), `SE_ASSET_ROLES`, `SE_SCENES`, `SE_SFX_CUES`, `SE_HOW_HEARD` (`whatsapp, instagram, facebook, tiktok, friend_family, church_announcement, flyer_poster, sms, other`), `SE_PRELOAD` (per-surface module list), limits (`SE_MAX_TEAMS = 8`, `SE_MIN_TEAMS = 2`, upload caps, `SE_SNAPSHOT_DEBOUNCE_MS = 400`, `SE_TICK_MS = 1000`).

---

## 21. Changes to existing code and docs

The module is standalone, so changes outside its own files are few and small. Each is listed with the reason.

### 21.1 `includes/header.php`: navigation and command palette

- Add **"Special Events"** to the *Specialized Units* group, right after "Envision (Media)". Visible when `se_nav_visible($pdo, $uid)` is true (manager, studio member or active crew; computed inside `try/catch` like the Assimilation badge so a missing table never breaks the layout). Optional badge: number of events currently `live` for the user.
- The group's wrapper condition `userHasNavAccess($pastors, [1, 3, 4, 7, 11])` must also be true for crew-only users: `|| $se_nav`.
- Command palette: `$gsAdd('special_events', 'Special Events', '/modules/special_events/index.php', 'Specialized Units', <icon path>, ['events','karaoke','games','chara','check-in','envision'], ['overview' => 'Overview', 'registration' => 'Registration', 'teams' => 'Teams', 'games' => 'Games', 'attendees' => 'Attendees', 'insights' => 'Insights'])`, under the same gate.
- `$moduleTitles['special_events'] = 'Special Events';`

### 21.2 `includes/sms_functions.php`: one merge field

In `sms_render()`, add `'{{link}}' => trim((string)($data['link'] ?? ''))` to `$map`. Backward compatible: existing templates never contain `{{link}}`. SMS Studio's composer strips unknown recipient keys, so it is unaffected. Document `{{link}}` in the SMS Studio help as "module-generated messages only".

### 21.3 `.gitignore`

```
# Special Events live snapshots (runtime). Keep the folder's .htaccess and .keep tracked.
live/*
!live/.htaccess
!live/.keep
```

`.deployignore`: `live/` needs no entry (runtime files are never in the repo, and tar never deletes them). The rest is already done: **PR0** rewrote the whole file in tar's pattern format, because `bin/deploy.sh` copies with **tar**, and tar silently ignores every pattern that starts or ends with `/` (tested with GNU tar 1.35) — the old `tests/` entry kept nothing out of the docroot. `./tests` and `./docs` now do (plus `docs/.htaccess` deny-all), and the lint job's "Deploy exclusions must hold under tar" step fails the build if any of them regresses. When PR1 adds files, follow the format documented at the top of `.deployignore`: root-only entries are written `./name`, bare names match at any depth.

### 21.4 Login "return to" (`auth/login.php`, `api/auth_api.php`)

Crew open `/e/chara/host` on a fresh device, so they need to land back there after login:
- `auth/login.php?next=<path>` stores `$_SESSION['post_login_next']` **only if** `next` matches `^/(e|modules)/[A-Za-z0-9/_\-.?=&#]{0,200}$` and contains no `//` and no `\`.
- `api/auth_api.php`, after a successful login (not when a password change is forced), returns `redirect = $_SESSION['post_login_next']` if set (then unsets it); otherwise the existing logic.
- This is the only change to auth code. It needs a security review (open-redirect safe by the regex, same-origin only).

### 21.5 CI (`.github/workflows/deploy.yml`, lint job)

Add steps:
1. **Special Events guide rule** (pull requests): if the diff touches `modules/special_events/`, `api/special_events_`, `includes/special_events/`, `e/` or `assets/se/js/`, it must also touch `modules/special_events/how_to_use.md`. Same pattern as the Reach/Assimilation steps.
2. **CSS freshness**: download the pinned Tailwind standalone CLI (version in `assets/se/css/TAILWIND_VERSION`), run `bin/build_se_css.sh --check`; fail if `assets/se/css/se.css` differs from a fresh build.
3. **Unit tests**: `php tests/special_events/run.php` and `node --test "tests/special_events/js/*.test.mjs"` (GitHub runners ship Node; these tests never run on the server).
4. **Secret sweep**: fail on a hard-coded `SE_HASH_PEPPER` or `ABLY_API_KEY` value in PHP/JS.

### 21.6 `AGENTS.md`: new section "Special Events module (added 2026-10)"

Content to add (summarised; write it in AGENTS.md style):
- Where things are (§8.7), table prefix `se_`, the four API files and their auth.
- **Preact + htm + signals, GSAP and the precompiled Tailwind are allowed inside this module only** (`assets/se/**`, `e/`, the Studio page). The global "no React/Vue/Alpine" rule stands everywhere else.
- After any state mutation that affects screens, call `se_live_publish()`. **Never put personal names, phones, answers-before-reveal or charades phrases in `public.json`**; names go only in key-protected snapshots, and phrases only through `me`/console.
- Seats, teams, player and queue numbers are allocated **only** inside `se_lock_event()` transactions; uniqueness via the unique keys, never check-then-insert.
- Scores are a ledger: never UPDATE points; void and re-award.
- The module never writes `events`, `event_registrations`, `checkins`, `attendance` or `users`. The one exception is the hand-off's Embrace insert (§17.4).
- SMS only via SMS Studio campaigns/queue (`{{link}}` merge field); never `sms_log`.
- AI only via `se_ai()`; no attendee PII in prompts; human review before apply.
- Studio/API changes must update `modules/special_events/how_to_use.md` (CI-enforced).
- Rebuild `assets/se/css/se.css` with `bin/build_se_css.sh` whenever classes change (CI-checked).
- Cron line and `SE_HASH_PEPPER` requirement.

### 21.7 `README.md`, `.env.example`, `modules/reach/how_to_use.md`

- README: add Special Events to the modules overview (under "Public front door & follow-up" or a new "Events & experiences" group), the cron line (§23.4), and the env vars (§20.1).
- `.env.example`: add `SE_HASH_PEPPER`, `SE_AI_MODEL_TEXT`, `SE_AI_MODEL_VISION`, `SE_REALTIME_DRIVER`, `ABLY_API_KEY`, `SE_BIBLE_API_BASE`, with comments.
- Reach how-to (courtesy, not CI-required since no Reach code changes): "Campaigns of type *Special Event* are created by the Special Events hand-off; their leads arrive with notes about the event."

---

## 22. Testing strategy

There is still no full test suite in the repo. This module adds focused automated tests where bugs would be costly, plus manual and load tests.

### 22.1 Unit tests (CLI, no database)

`php tests/special_events/run.php` runs each `*_test.php` (tiny assert helpers, same style as `tests/security_helpers_test.php`). JS parity tests run with `node --test` in CI.

| Test file | Cases |
|---|---|
| `phone_test.php` + `js/phone.test.mjs` | Shared fixture `fixtures/phones.json` (≥ 40 vectors: `0803…`, `803…`, `+234 (0) 803…`, `00234…`, spaces/dashes, landlines → null, `+44…` international, garbage). PHP and JS must agree. |
| `slug_test.php` | format, reserved words, case folding, suggestions |
| `display_name_test.php` | casing, unicode, missing last name, length cap |
| `capacity_state_test.php` | table-driven `se_registration_state()` over phases × overrides × counts × waitlist settings; `se_walkin_pool()`, seats-left modes, `se_can_self_cancel()` |
| `team_algorithm_test.php` | I1–I3 over 100 000 random sequences (k = 2…8); deterministic replay; pointer rotation |
| `eta_test.php` | planned timeline, live drift, skips, explicit start times, overlaps |
| `scoring_test.php` | Kahoot formula bounds; team normalisation; weights; idempotency keys; Who-Am-I clue points; Feud bank × multipliers |
| `timing_test.php` | elapsed clamp (§8.5.5); buzz effective time and ordering incl. ties and early/late clients |
| `theme_test.php` + `js/theme.test.mjs` | shared vectors: hex → tokens; contrast ≥ thresholds; black team gets a ring; PHP = JS |
| `sms_template_test.php` | event-level substitution, `{{link}}`, GSM-7 cleaning, page counts |
| `schema_test.php` | `se_schema_validate()` accepts/rejects per Appendix D schemas |
| `markdown_test.php` | `se_markdown()` escapes HTML/JS, allows only safe links |
| `svg_sanitize_test.php` | strips scripts, `on*`, `foreignObject`, external hrefs, CSS `@import` |
| `js/svg_tokens.test.mjs` | template token parsing, text fitting, conditional groups |
| `preload_test.php` | `SE_PRELOAD` lists match the import graph of each entry module |
| `snapshot_privacy_test.php` | builds `public.json` from fixtures and asserts no `first_name`, `last_name`, `display_name`, `phone`, `email`, `phrase`, `correct_index` (before reveal) keys anywhere |

### 22.2 Integration tests (local MySQL)

`tests/special_events/integration/run.php` (run locally with a disposable database; not in CI until a DB service is added). Build the database first, then run it:

```bash
php tests/special_events/db_setup.php --fresh --seed \
    --host=127.0.0.1 --port=3306 --user=root --pass=secret --db=se_test
SE_TEST_DB_NAME=se_test SE_TEST_DB_USER=root SE_TEST_DB_PASS=secret \
    php tests/special_events/integration/run.php
```

The cases:
- **Seat race**: 60 parallel PHP CLI processes register distinct phones against capacity 20 → exactly 20 `confirmed`, 40 `waitlisted` (waitlist on) or `full` responses.
- **Check-in race**: 40 parallel check-ins → team sizes within ±1; player numbers 1…40 unique.
- **Karaoke race**: 10 parallel picks of the same song with unique songs on → exactly 1 success.
- **Scoring idempotency**: score the same round 5 times concurrently → one set of ledger rows.
- **Cancel/promotion**: cancel 3 confirmed → top 3 waitlisted promoted in order.

### 22.3 Manual smoke tests

Use the checklists in Appendix H for every surface after each phase deploy (AGENTS.md: "manual smoke of the module you touched before declaring done").

### 22.4 Load test

`tests/special_events/load/quiz.k6.js` (k6, run from a laptop):
- 250 virtual players: poll `public.json` and `room-<key>.json` at 1 Hz with jitter; on each armed round, answer once at a random time inside the window; 20 rounds.
- Pass: static p95 < 300 ms, answer p95 < 800 ms, error rate < 0.5 %, snapshot freshness p95 < 1.5 s (measured from the host's `round_arm` response to clients seeing `v`).
- Run 1: from an office network against production during a quiet time, with a dedicated test event (test mode). Run 2: **from the venue Wi-Fi** with 30–50 real phones during the dress rehearsal, to surface per-IP throttling.

### 22.5 Dress rehearsal (T−7 days)

With the real crew and ~20 volunteers' phones, in **test mode**: register, check in (self + desk + walk-in), lobby screen, full games block (every game type once), karaoke queue, finale, then **Reset rehearsal**. Record issues and fix them before the event.

### 22.6 Accessibility, performance and security checks

- Lighthouse (mobile) on the portal and `/in` (§13.15 targets); axe DevTools on portal, sheet, `/in`, `/play`.
- Authorisation matrix test: for each capability, call a representative action as each crew role and as a non-crew user → expected allow/deny.
- CSRF/Origin: POST from a foreign origin page → rejected. Rate limits trigger at the documented thresholds. Upload polyglots and malicious SVGs are neutralised.
- Run a security review of the branch before merging each phase (for example Claude Code's built-in `/security-review`), with special attention to §21.4 (login `next`), uploads and the public API.

---

## 23. Deployment and operations

### 23.1 Runtime directories

- `live/`: tracked `live/.htaccess` and `live/.keep` (created by the deploy because they are in git); event subfolders `live/<public_id>/` are created by PHP (`0755`). `live/.htaccess`:

```apache
Options -Indexes
<FilesMatch "^(?!.*\.json$).*$">
  Require all denied
</FilesMatch>
<FilesMatch "\.json$">
  <IfModule mod_headers.c>
    Header set Cache-Control "no-cache"
    Header set X-Content-Type-Options "nosniff"
    Header set X-Robots-Tag "noindex, nofollow"
  </IfModule>
</FilesMatch>
```

- `uploads/se/`: created on first upload with its `.htaccess` (§19.6).

### 23.2 Front-end build (CSS only)

`bin/build_se_css.sh`:
1. Ensure `./tailwindcss-linux-x64` exists at the version in `assets/se/css/TAILWIND_VERSION` (download from the official GitHub release if missing; the binary is git-ignored).
2. `./tailwindcss-linux-x64 -i assets/se/css/se.input.css -o assets/se/css/se.css --minify`.
3. Write `assets/se/BUILD` (UTC timestamp + short git SHA).

`--check` builds to a temp file and diffs. Developers run it before committing; CI runs `--check`. The built CSS **is committed** because the server has no Node/Tailwind.

### 23.3 Migrations

Ship the phase's migrations with that phase's code (§9.4). Before merging to `main`, apply the new files to scratch databases on **MySQL 8.0 and MariaDB 10.x** (Appendix A rules; `--dry-run` only lists files and does not check SQL). Production applies them on deploy via `bin/deploy.sh`; a failing file stops the deploy and is re-run from the top on the next one. Code tolerates missing tables until then (§9.4).

### 23.4 Cron

Add in cPanel → Cron Jobs:

```
*/5 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/special_events.php >/dev/null 2>&1
```

The script is CLI-only (`PHP_SAPI !== 'cli'` → 403), sets `$_SERVER['DOCUMENT_ROOT']` before requiring `includes/db.php`, takes `GET_LOCK('se_cron', 0)` (skips if another run holds it), runs its jobs, and writes `se_settings.cron_last_run`. Jobs, in order: message runs; karaoke hold releases; test-mode auto-off; token/device/rate-limit cleanup; AI source purge; retention anonymisation (daily, after 03:00).

### 23.5 Configuration steps (first deploy)

1. Add `SE_HASH_PEPPER` (and optional model overrides) to production `.env` via cPanel File Manager.
2. Deploy Phase A; confirm migrations applied (`php db/migrate.php --status` in cPanel Terminal).
3. Studio → Settings: privacy contact email, review the default privacy notice.
4. Add the cron line.
5. Create Chara, configure, publish (§7.1).

### 23.6 Monitoring

- **Studio → Live → Health**: snapshot age and version, last tick, cron last run, SMS worker heartbeat (`sms_health()`), AI availability and usage today, error counts (last hour).
- **Logs**: every server error line starts with `SE ` (`error_log`), e.g. `SE public/register: …`. Client errors are sent to `public_api action=beacon m=error` (rate-limited) and logged as `SE client: <surface> <message>`.
- **Event-day watch**: the host console's health dots (§13.10).

### 23.7 Backups and fallbacks

- T−1 day: full cPanel database backup. Export the attendee Excel and keep it on the desk laptop (offline fallback list).
- If the live stack fails on the night, the show can continue with the stage in **blank/standby** while the game master runs a manual round, and scores can be entered later as awards.

### 23.8 Rollback

- Code: `git revert` on `main` → automatic deploy (DEPLOY.md). Removed files linger in the docroot (tar deploy), so a revert MUST NOT rely on deletion for security.
- Data: migrations are forward-only. Disable features per event with switches (`teams.enabled`, `karaoke.enabled`, `games.enabled`, message kinds) instead of schema rollbacks.

---

## 24. Event-day runbook and failure playbook

### 24.1 Devices and people

| Who | Device | Opens |
|---|---|---|
| Producer | laptop | Studio → Live; host console as backup |
| Host/MC | tablet or laptop | `/e/chara/host` |
| Game master | laptop (beside the host) | `/e/chara/host` (game tab) |
| Stage | the projector laptop, wired to the PA | `/e/chara/stage#k=…&t=…` (click to start; volume set) |
| Lobby | TV + mini-PC/laptop or smart TV browser downstairs | `/e/chara/lobby#k=…` |
| Desk ×2 | their phones (charged, power bank) | `/e/chara/desk` |
| Karaoke DJ | tablet | `/e/chara/dj` |
| Media | phone | take photos; Studio → Assets after the event |

### 24.2 Timeline

| When | Task |
|---|---|
| T−10 days | Programme imported; teams' hex colours entered; song list published; decks approved; Format Studio renders done; QR posters printed (A3 ×2 + A4 ×2) |
| T−7 days | Dress rehearsal + venue load test (§22.4–22.5); fix list |
| T−3 days | Content freeze; ask the hosting provider about per-IP throttling and request the allow-list (if needed) |
| T−1 day 18:00 | `reminder_1` goes out (check SMS Studio History); database backup; attendee export on the desk laptop |
| T−2 h | `reminder_2`; open stage (click to start, test sound), lobby, consoles; check snapshot age < 3 s; scene `standby`; test mode **off** |
| Doors | Check-in opens automatically; desk ready; watch team balance and walk-in counter |
| Show | Run of show from the host console; games; karaoke; finale |
| End | Scene `recap`; announce the My Night cards |
| T+1 day 09:00 | `thank_you` SMS; check feedback trickling in |
| T+1–3 days | Insights review; PDF report; hand-off wizard with the Follow-up liaison; archive after 7+ days |

### 24.3 Failure playbook

| Symptom | Check | Action |
|---|---|---|
| Phones not updating | Console health: snapshot age | **Publish now**; if age keeps growing, check `live/` writable and PHP errors (`SE live/…`) |
| Many "Reconnecting…" on Wi-Fi only | Host per-IP throttle | Ask guests to switch to mobile data (announcement); raise poll interval in console (`settings.realtime.min_poll_ms` = 2000) |
| Stage frozen | Stage tab | Reload the stage URL (state is server-side), click to start |
| Host laptop dies | — | Any crew device opens `/e/chara/host` (ERP login); continue |
| Check-in queue long | Desk counts | Open a second desk; announce "type your number on the poster"; consider the lobby QR on more screens |
| Walk-in cap reached | Desk shows `WALKIN_FULL` | Producer decides; desk uses override per person, or raise the walk-in capacity in the Studio |
| Wrong team assignment | — | Desk **Move team** with a reason |
| Duplicate person (two phones) | Attendees → possible duplicates | Remove one (reason), keep the other |
| A question is wrong | — | **Void round** (with reason); re-run another item |
| Scores disputed | Console score history | Void/award with reasons; everything is audited |
| SMS reminder didn't go | Studio → Messages runs; SMS Studio health | If the worker is down: SMS Studio "Send now"; if a run was skipped for lateness, use an ad-hoc message |
| AI unavailable during prep | Studio shows AI disabled | Enter content manually; nothing in the live show depends on AI |
| Power/network outage | — | Desk works offline and syncs later; host switches to manual MC mode; enter scores afterwards as awards |

---

## 25. Delivery plan for Chara

**Constraint:** the event is 3–4 weeks away (target last week of October 2026), and registration was promised "by Sunday" to align with the flyer. Each phase is shippable, deployed behind its own migrations, and smoke-tested.

The phases are built as pull requests: Phase A = PR1–PR2, Phase B = PR3–PR4, Phase C = PR5–PR6, Phase D = PR7 (plus PR0, the deploy fix). The PR map and the progress tracker are in §28.

### Phase A — Registration live (days 1–5)

**Scope:** migrations A; `e/` router + shell (portal, privacy, ICS, 404); theme engine (PHP + JS) + Marquee portal (hero, intro, chapters, venue, FAQ, sticky bar); registration sheet (phone-first, member/returning/new, consent) with capacity engine (all 4 behaviours), manage link (status, cancel, cards), "I'm going" card with photo circle; Studio (Home, Overview, Details incl. days and slug, Brand incl. AI palettes, Registration, Crew, Attendees list + export, Assets kit); `{{link}}`; rate limits; audit; metrics beacons; nav link; AGENTS.md/README/.env.example updates; how_to_use.md v1.

**Acceptance:**
1. Producer creates Chara with `chara`, hex colours and capacity 120 in < 30 min and publishes.
2. A guest registers in ≤ 30 s on a mid-range Android.
3. A member is recognised by phone.
4. The seat race test passes.
5. Waitlist, self-cancel, seats-left and auto-close work as configured.
6. The link preview shows the OG card.
7. Lighthouse targets are met.

### Phase B — Check-in, teams and the live backbone (week 2)

**Scope:** migrations B; check-in (`/in`, self, walk-in, gender prompt, reveal, welcome card, verses with KJV lookup); team engine + Teams tab (paste hex, labels, names, captains); desk mode (+ offline queue); lobby display; live state + snapshots + tick + clock sync; stage display (standby, welcome, program, teams, leaderboard, karaoke, announcement, break, blank, recap); host console (show tab, scenes, program run-of-show, announcements, sound board, awards); programme builder + AI import; karaoke library + import + pre-pick + queue + DJ console; reminders (`reminder_1`/`reminder_2`) + Messages tab + cron; test mode + Reset rehearsal for check-in (§11.13).

**Acceptance:** the check-in race test passes; team sizes within ±1 and gender within ±1 in a 60-person rehearsal; the lobby animates arrivals; host actions reach phones in ≤ 1.5 s p95; reminders schedule and send correctly in test.

### Phase C — Games (week 3)

**Scope:** migrations C; decks + deck editor + AI generation + KJV enrichment + review; games setup; engine + Live Quiz, Trivia (captain/suggestions), Buzzer family (Buzzer, Finish the Verse, Emoji Bible), Who Am I?, Charades (presenter by number), Family Feud (survey collection, clustering, board, play); scoring ledger, MVP, finale; `/play` portal; SFX; test mode + reset; load test; dress rehearsal.

**Acceptance:** every game type is played end-to-end in rehearsal; scoring idempotency holds; the load test passes; the rehearsal fix list is closed.

### Phase D — After the event (event week + 1)

**Scope:** migrations D; thank-you SMS + recap (My Night card) + feedback; Insights + PDF report; hand-off wizard (Reach + Embrace); archive + slug reclaim; retention cron.

**Acceptance:** thank-you sent next morning; report generated; hand-off creates the Reach campaign/leads and Embrace first-timers with zero duplicates on re-run.

### Cut line (if time runs short, defer these without hurting Chara)

1. Format Studio auto-render (designers export sizes manually; the OG image uses the hero).
2. Who Am I? (its content can run as buzzer questions).
3. AI deck generation (enter questions manually; KJV lookup stays).
4. Service worker; hub page `/e/`; series insights.
5. Trivia suggestions bar (captains answer alone).

**Never cut:** capacity correctness, check-in, team balance, privacy rules, audit, scoring ledger.

### Responsibilities

| Who | Owns |
|---|---|
| Tom-Blake Asaah | Engineering of all phases; trivia/quiz questions (M9) with AI help |
| Envision (designers) | Brand hex colours (M13), team hex colours, brand kit (logo, hero art/video), template polish, share-card art direction |
| Odun-Ayo Funmilola | Registration copy and content (how-to-register video), consent text with leadership, department-head alignment (M11) |
| Chidera | Programme document (M12), follow-up department alignment (M11) |
| Tommy (events lead) | Lineup, timing, game slots, karaoke slot (M12) |
| "The group" | Karaoke song list (title, artist, duration) |
| Reach & Embrace HODs | Receiving the hand-off; follow-up plan |

---

## 26. Roadmap and idea bank

Everything below is out of scope for v1 but designed to fit. Ideas are grouped; ⭐ marks the ones with the highest expected impact.

### 26.1 Live show and engagement

- ⭐ **Audience moments**: live polls, **debate voting** (the debate segment: audience votes the winning side live), word cloud ("Describe tonight in one word"), hype/applause meter, emoji reactions floating over the stage, moderated **shout-out wall**.
- ⭐ **Party mechanics**: lucky-draw raffle (slot-machine reveal of a player number), spin-the-wheel (challenges/teams), **Bible Bingo** cards on every phone, QR scavenger hunt around the venue.
- **More games**: **Sword Drill** (race to the verse; type its first word), Two Truths & a Lie (Bible), Bible Pictionary (draw on phone, mirrored on stage), Guess the Hymn (audio intros), Speed round, Mystery box, Hot seat.
- **Karaoke+**: duets/groups, audience voting + "Voice of the Night", time-synced lyrics on stage (LRCLIB lookup, tap-to-sync tool), uploaded instrumentals with a branded player, YouTube karaoke links.
- **Photo booth & gallery**: branded frames, moderated live photo wall on stage, post-event gallery downloads.
- **Live captions** on stage for accessibility; **remote play-along** for livestream viewers.

### 26.2 Growth and community

- ⭐ **Referral challenge**: "Bring 3 friends → +300 for your future team"; top inviters on the recap.
- **Season/series leaderboards** and the **Hall of Fame** page per series; Chara Wrapped (yearly recap).
- **Companions**: register a +1 without their own phone (designed in `companion_of_registration_id`; per-event `companions_max`).
- **Squads**: friends who arrive together stay on one team (balance by later arrivals).
- Opt-in attendee directory ("meet people from Chara").
- Prayer request box routed to Zoe (with consent); testimony capture → Testimonies module.

### 26.3 Payments and ticketing (hooks reserved, D20)

Planned design so v1 does not paint us into a corner:
- Tables `se_ticket_types` (event, name, price_kobo, capacity, sale window), `se_orders` (event, contact, amount, status `pending|paid|failed|refunded`, Paystack reference), `se_order_items`.
- Registration gains status `pending_payment` with a 15-minute hold that counts against capacity (released by cron/tick on expiry). The seat engine (§10.4) already allocates under one lock, so holds are a small extension.
- Paystack Standard checkout (cards, bank transfer, USSD) with webhook verification (`x-paystack-signature` HMAC-SHA512 over the raw body), idempotent order completion, receipts by SMS. Finance module integration for reconciliation.
- Donations and merch pre-orders as optional ticket types with price ≥ 0.

### 26.4 Platform

- **Push driver (Ably)** per §8.5.7.
- **Email channel** (Brevo/SMTP via PHPMailer) for confirmations and thank-yous with branded templates.
- **AI image generation** for illustrations/backgrounds in the event palette (designer-approved).
- **Cloudflare Turnstile** on registration if bots appear.
- **Per-day capacity and day selection** for multi-day events.
- Full offline PWA for check-in kiosks (tablet at the door).
- Name badges/wristbands: printable badges with team colour + player number + QR (dompdf), NFC later.
- Multi-language copy (Yoruba/Pidgin fun mode).
- A "Special Events" public hub with past-event galleries and highlight reels from Envision's media archive.
- AI FAQ assistant on the portal (answers from the event's FAQ/details only).

---

## 27. Open items and inputs needed

| # | Item | Owner | Needed by |
|---|---|---|---|
| O1 | Exact date, doors/start/end times and venue details for Chara | Odun-Ayo / Tommy | Phase A day 1 |
| O2 | Brand primary + secondary hex codes (M13) | Envision | Phase A day 2 (defaults used until then) |
| O3 | Team hex codes (4) and labels | Envision | Phase B |
| O4 | Consent wording and mode (§19.8) + privacy contact email | Leadership + Odun-Ayo | Before publishing |
| O5 | Capacity numbers confirmation (online 120, walk-ins 30, hard cap?) and which of waitlist/self-cancel/seats-left are on for Chara | Odun-Ayo / Chidera | Phase A |
| O6 | Programme document (M12) | Chidera / Tommy | Phase B |
| O7 | Karaoke song list (title, artist, duration) | The group | Phase B (before "publish list") |
| O8 | Trivia/quiz content review (M9) | Tom-Blake + reviewer | Phase C |
| O9 | Family Feud survey questions (3–5) | Envision | Phase B (so registrants can "play ahead") |
| O10 | Brand kit: logo/wordmark, hero art/video, flyer | Envision designers | Phase A (hero), Phase B (rest) |
| O11 | Crew list with roles | Producer | Phase B |
| O12 | Hosting provider: LiteSpeed per-IP throttle values; allow-list for the venue IP on the night | Tom-Blake | T−3 days |
| O13 | SMS: sender ID confirmed, units budget for ~300 reminders + ~150 thank-yous | Tom-Blake / Finance | Phase B |
| O14 | Reach/Embrace HODs agree to the hand-off rules (§17.2) | Chidera / Odun-Ayo | Phase D |
| O15 | Whether `/e/` hub should be public | Envision | Phase D (optional) |
| O16 | ✅ **Fixed by PR0.** *(Deploy exclusions did not work under tar: `.deployignore` was written for rsync, and tar silently ignores entries that start or end with `/`, so `.git/`, `.github/`, `tests/`, `/AGENTS.md`, `/DEPLOY.md` and `/php.ini` were copied into the docroot on every deploy.)* Every entry is now in tar form, and a lint-job step re-runs the deploy's tar pipeline on each push and pull request and fails if any of those paths would be copied. **Two things are still on the owner:** (a) delete the copies already in the docroot — tar never deletes — via cPanel File Manager in `public_html/hodlc.lpc.cm/`: `.git/`, `.github/`, `tests/`, `docs/`, `AGENTS.md`, `DEPLOY.md`, and confirm `https://hodlc.lpc.cm/.git/config` then returns 403/404; (b) note that `./php.ini` is excluded, so edits to the repo's `php.ini` no longer deploy — the docroot copy is authoritative, as that file intends. | Tom-Blake | Cleanup after the PR0 merge, before the Phase A deploy |

---

## 28. Build plan: pull requests and progress

The module is built in **eight pull requests**. PR0 fixes the deploy exclusions (O16), and PR1–PR7 build the module. Each PR is built in its own chat from its prompt in [`docs/build_prompts.md`](build_prompts.md), then reviewed and merged before the next one starts. The §25 phases map onto them: Phase A = PR1–PR2, Phase B = PR3–PR4, Phase C = PR5–PR6, Phase D = PR7.

### 28.1 The pull requests

| PR | Name | What it delivers | Migrations | Needs |
|---|---|---|---|---|
| PR0 | Deploy exclusions fix | `.deployignore` rewritten for tar, plus a CI guard and cleanup steps. `.git`, tests and repo docs stop reaching the docroot (O16). | — | — |
| PR1 | Foundation & Studio core | Core libraries; the Studio (create, edit, clone, brand with hex codes and AI palettes, registration settings, crew, assets, publish checklist); the `/e/<slug>` router and branded shell; front-end tooling; CI; the ERP nav link | A.1–A.4 | the guide on `main` (PR0 recommended) |
| PR2 | Public portal & registration | The Marquee portal; phone-first registration; the capacity engine with every behaviour and the waitlist; the manage link; SMS links; the "I'm going" card with the photo circle; Attendees with Excel export; the share kit. **Registration can open after this PR.** | — | PR1 |
| PR3 | Check-in, teams & live backbone | Poster-QR check-in (self, walk-in, desk); the team engine and Teams tab; welcome verses; live snapshots with the tick; lobby, stage and host console; check-in posters; the live monitor; test mode with Reset rehearsal | A.5, A.7 | PR2 |
| PR4 | Programme, karaoke & reminders | Programme builder with AI import and live ETAs; karaoke library, pre-pick, queue and DJ console; reminder SMS, the Messages tab and the cron; the Format Studio | A.6 | PR3 |
| PR5 | Games I: engine, decks & quiz games | Decks (with AI and KJV); the round engine; `/play`; Live Quiz, Bible Trivia and the Buzzer family; the score ledger and awards; test mode for games; the load test | A.8 | PR4 |
| PR6 | Games II: party games & finale | Who Am I?, Charades and Family Feud (survey and clustering); leaderboards, MVP and the finale; dress-rehearsal fixes | — | PR5 |
| PR7 | After the event | Thank-you SMS, recap with the My Night card, feedback; insights, PDF and Excel; hand-off to Reach and Embrace; archive, slug reclaim, retention | A.9–A.10 | PR6 (or alongside PR6 once PR5 is merged) |

Each PR's full scope, reading list, tests and acceptance checks are in `docs/build_prompts.md`.

**Order and parallel work.** Build in order, and merge each PR before starting the next. Only two overlaps are safe: PR0 alongside anything, and PR7 alongside PR6 once PR5 is merged. Parallel branches conflict in shared files (constants, the Studio tab list, `how_to_use.md`, this section). Rebuild `se.css` instead of merging it by hand.

**Timing for Chara.**
- Registration opens after PR2.
- PR3 and PR4 should be merged about ten days before the event, so posters can be printed and reminders scheduled.
- PR5 and PR6 should be merged before the dress rehearsal (§22.5).
- If time runs short, apply the §25 cut line: the Format Studio (PR4) and Who Am I? (PR6) go first.

### 28.2 Rules for every build PR

The full rules are in `docs/build_prompts.md` under "Shared rules". In short:
- Start from the latest `main`. Confirm in §28.3, and with `git log`, that the PRs yours depends on are merged.
- Build only your PR's scope. Code that runs before a later migration must degrade safely (§9.4).
- This guide is the spec. When the build has to differ, update the affected section and log the change in §28.4, in the same PR.
- Every PR updates:
  - its row in §28.3;
  - `modules/special_events/how_to_use.md` (CI-enforced from PR1);
  - its tests;
  - the compiled `se.css`, when classes change;
  - `AGENTS.md` or `README.md`, when conventions, cron lines or env keys change.
- **Done** means:
  - every Build item is done, or deferred in §28.4;
  - every acceptance check passed, with evidence in the PR description;
  - the migrations were applied twice on MySQL 8 and on MariaDB;
  - the manual smoke test (Appendix H.2) was done;
  - CI is green, and the diff had a security review.

  The owner merges.

### 28.3 Progress tracker

Status: ⬜ not started · 🟡 in progress · 🔵 in review · ✅ merged · ⛔ blocked.

Each PR sets its **own** row to ✅, with the PR link, the date and notes for the next PR. The row reaches `main` only when the PR merges, so `main` is always accurate.

| PR | Status | Pull request | Merged | Notes for the next PR |
|---|---|---|---|---|
| Guide | ✅ | [#28](https://github.com/tomblakeasaah196/hodlekki/pull/28) | 2026-10-03 | Design, build plan and prompts. The Appendix A SQL was tested on MySQL 8.0.46 and MariaDB 10.11.14. |
| PR0 | ✅ | [#29](https://github.com/tomblakeasaah196/hodlekki/pull/29) | 2026-10-03 | `.deployignore` is now in **tar** pattern format: root-only entries are `./name`, bare names match at any depth, and a pattern starting or ending with `/` is silently ignored (that was O16). Read the header comment in the file before adding entries. The lint job step "Deploy exclusions must hold under tar" re-runs the deploy's tar pipeline on every push and pull request and fails if `.git`, `.github`, `.cpanel.yml`, `.deployignore`, `php.ini`, `tests`, `docs`, `AGENTS.md` or `DEPLOY.md` would be copied, or if `index.php`, `includes/db.php` or `webhook/.htaccess` would not be — so PR1 must add `./live` in `./name` form and keep `assets/se/**` copyable. The deploy copy **never deletes**: a renamed or removed public file lingers in the docroot (§5 risk table). Owner cleanup of the already-published `.git/`, `.github/`, `tests/`, `docs/`, `AGENTS.md`, `DEPLOY.md` is tracked in §27 (O16). |
| PR1 | ✅ | [#30](https://github.com/tomblakeasaah196/hodlekki/pull/30) | 2026-10-04 | Foundations are in. **`includes/special_events/bootstrap.php` is the only entry point** — require it after `includes/db.php`, never instead of it. `includes/special_events/db.php` is a PR1 addition to the §8.7 layout and holds `se_table_exists()`, `se_lock_event()`, `se_schema_check()`, `se_audit()` and the module's exception types (`SeValidationException`, `SeStaleVersionException`, `SeNotFoundException`, `SeRuleException`) — throw those and let `se_api_fail()` map them to the §12.1 envelope. **No `style="…"` attributes in server HTML**: the CSP nonce does not cover style attributes and Chromium drops them; per-team colours go through `se_theme_css_vars($theme, $teams)` into the nonced `<style>` block (§13.1.2 corrected). **Asset and crew actions name their own row in `id`** and derive the event from it, so a caller cannot pair someone else's row with their own event. Vendored libraries live at `assets/se/vendor/<name>-<version>/`; add a new one with a new versioned directory and update `se_import_map()` in bootstrap.php — the one place both the portal and the Studio read. Add a Studio tab by listing it in `SE_STUDIO_TABS` **and** `SE_STUDIO_TABS_READY`; until then it stays hidden. `tests/special_events/db_setup.php --fresh --seed` builds a local database (stand-ins + migrations, applied exactly as `db/migrate.php` does), and `dev_router.php` serves `/e/` under `php -S`. Run the suite with `php tests/special_events/run.php` and `node --test "tests/special_events/js/*.test.mjs"`. |
| PR2 | ✅ | [#31](https://github.com/tomblakeasaah196/hodlekki/pull/31) | 2026-10-04 | Registration is live. **`includes/special_events/db.php` aside, every new library is required by `bootstrap.php`** — `identity.php`, `capacity.php`, `registration.php`, `messages.php`, `attendees.php`, `cards.php`, `export.php`, `portal.php`. **All seat allocation must go through `se_lock_event()`**: call `se_register()` / `se_registration_cancel()` / `se_promote_registration()` rather than touching `se_registrations.status` yourself, and call `se_after_capacity_change($pdo, $event)` (two arguments) after anything that frees or takes a seat — it recomputes the counters and auto-closes. `se_promote_waitlist_force($pdo, $event, $seats)` takes a **seat count**, not an id. **Every Studio action takes the event in `id`**, so attendee actions name the person in `attendee_id`; keep that for check-in, teams and karaoke (a check-in action should take `attendee_id` or `reg_code`, never `id`). The public API is `api/special_events_public_api.php`: new actions go after `se_require_request_integrity()` and use `se_public_event()`, `se_public_require_writable()`, `se_public_actor()` and `se_public_limit()` — copy an existing case. `GET ?action=time` is the only GET. The portal is **server-rendered** in `includes/special_events/portal.php` and `e/index.php`; `/in`, `/play`, `/stage` must follow the same rule — no `style="…"` attributes, classes in `se.input.css`, and a new entry module needs an `SE_PRELOAD` entry or `preload_test.php` fails. The SVG engine is `assets/se/js/core/svg.js` with templates in `assets/se/templates/`; a new card kind adds a template, a `SE_CARD_KINDS_READY` entry and a `card` payload branch. SMS goes out through `se_message_runs` run keys (`waitlist:<reg_id>:<n>`, `link:<reg_id>:<YYYYMMDDHH>`) into the SMS Studio tables — never write `sms_log`. The §22.2 integration tests exist (`tests/special_events/integration/run.php`) but are **not** in CI: run them with `db_setup.php --fresh --seed` against a scratch MySQL, and use `smoke_seed.php` + `dev_router.php` for the manual smoke. |
| PR3 | ✅ | [#32](https://github.com/tomblakeasaah196/hodlekki/pull/32) | 2026-10-04 | Check-in, teams and the live backbone are in. **The live state machine is `includes/special_events/live.php`** — never write `se_live_state` directly: `se_live_mutate($pdo, $event, $expectedVersion, $fn, $action, $meta, $actorId)` is the only writer, it bumps `version`, marks the row dirty and raises `SeStaleVersionException` (`STALE_VERSION`) when two consoles act at once. Publishing is `se_live_publish($pdo, $eventId, $force, $kinds)`; call it with `$force = true` **after** the transaction commits, never inside it. **Snapshots are files, not endpoints**: a new field goes into the builder in `live.php` *and* into `tests/special_events/snapshot_privacy_test.php`, which reads `se_snapshot_public()`'s source and fails if it ever mentions a personal column — `public.json` is world-readable. Crew APIs are split: `api/special_events_live_api.php` (ERP session + CSRF + capability, for host/desk/dj) and `api/special_events_display_api.php` (key in the URL, no session, for the unattended screens); `SE_LIVE_LATER_ACTIONS` returns `FEATURE_NOT_READY` for every PR4+ action, so add games there rather than inventing a new endpoint. **Anything allocated per person — team, player number, queue place — must happen inside `se_lock_event()`**: `se_checkin()` already holds it, so call that rather than inserting into `se_checkins`. `se_team_choose()` is pure and its invariants are pinned by `tests/special_events/teams_test.php` over 100,000 sequences; do not "improve" the four rules without it. Five new browser surfaces (`stage`, `lobby`, `host`, `desk`, `dj`) each need an `SE_PRELOAD` entry whose **first** item is `/<surface>/main.js` and which lists every `@se/core/` module in the static import graph — use a dynamic `import()` for anything heavy and optional (the poster engine and the `/in` client both do). Client-side realtime is `@se/core/realtime.js` (`watchSnapshot`, `PollDriver`, `paceFor`) and `@se/core/clock.js` (`serverNow`, `elapsedSince` — always clamp). **The SFX sprite `assets/se/sfx/se-sfx.mp3` is not in the repo**: the cue map and licence file ship, `core/sfx.js` degrades silently, and PR4 must add the audio and complete `assets/se/sfx/LICENSES.md`. Test mode lives in `se_events.settings_json`, not a column; `se_reset_rehearsal()` deletes only `is_test = 1` rows and PR4–PR6 must extend it to their tables. |
| PR4 | ✅ | [#33](https://github.com/tomblakeasaah196/hodlekki/pull/33) | 2026-10-04 | Programme, karaoke and the reminder machinery are in. **`se_program_items` and `se_karaoke_entries` are never written directly**: the programme goes through `se_program_save()` / `se_program_op()` / `se_program_move()` (every op runs inside `se_live_mutate()`, so it bumps the version and publishes), and a karaoke claim goes through `se_karaoke_pick()` / `se_karaoke_release()` / `se_karaoke_set_status()`. Uniqueness is the **database's** job: `se_karaoke_entries` has stored generated columns `active_song_key` and `active_singer_key` with UNIQUE indexes, so a double pick raises a duplicate-key error that `se_karaoke_pick()` translates into `SONG_TAKEN` — never add a `SELECT`-then-`INSERT` check in front of it. The ETA engine (`se_program_plan()`, `se_program_eta()`) is **pure** and pinned by `tests/special_events/program_eta_test.php`; it is what the console, the stage and every phone repeat, so change it with the tests. Times in the database are parsed with `se_parse_datetime()` (event timezone) — never mix a bare `DateTimeImmutable` with them. **Settings toggles that are not a form** (publish the song list, the public time mode) use the new `se_event_settings_patch($pdo, $event, $patch, $actorId)` instead of `se_event_update()`, which demands `expected_row_version`. Messages: `se_message_schedule()` produces the slots and their **run keys**, `se_messages_run_due()` claims them, and `se_message_runs` has a UNIQUE `(event_id, run_key)` — that, not a flag, is what makes a reminder un-sendable twice; PR5+ must mint a new key format rather than reuse one. `cron/special_events.php` is the module's only cron and takes `GET_LOCK('se_cron')`; add a job to it rather than a second file, and note that **retention anonymisation (§20.4) is not implemented in it yet** — PR6 or PR7 owns that. `se_reset_rehearsal()` now clears `se_karaoke_entries` **before** registrations (FK is RESTRICT, §9.5); PR5's game tables must do the same. The **Format Studio (§14.2) is deferred to PR6** — see §28.4 — as is the SFX sprite PR3 left for PR4: `assets/se/sfx/se-sfx.mp3` and its licence table are still missing and still degrade silently. |
| PR5 | ✅ | [#34](https://github.com/tomblakeasaah196/hodlekki/pull/34) | 2026-10-04 | Games engine, decks, Live Quiz, captain Trivia, Buzzer family, score ledger, `/play`, rehearsal reset and load profile are in. PR6 should extend the existing round engine for Who Am I?, Charades, Feud, richer game views and the finale; PHP/MySQL smoke evidence remains to be run in a container with the required services. |
| PR6 | ✅ | [#35](https://github.com/tomblakeasaah196/hodlekki/pull/35) | 2026-10-04 | Party games and finale are in. PR7 can use `se_finale_payload()` for recap data (`teams`, `champion`, `mvp`, `mvp_winner`, named `awards`). Survey responses are cleared by Reset rehearsal; charades phrases are returned only by `me.presenter` and the capability-protected console. The physical 20-phone venue dress rehearsal remains an owner event-day task; run the checklist in `how_to_use.md` §22 before Chara. |
| PR7 | ✅ | [#36](https://github.com/tomblakeasaah196/hodlekki/pull/36) | 2026-10-04 | The after-event loop is complete. `thank_you` uses the existing message-run uniqueness ledger and appends `#recap` to each fresh manage link. Feedback, insights, the aggregate-only PDF, the full workbook, and the reviewed Reach/Embrace hand-off are available after A.9–A.10 migrate. Hand-off reruns rely on `uniq_se_handoff_done` rather than a check-then-insert guard. Retention is part of the one `special_events.php` cron. Before Chara, run Appendix H.5 and confirm the physical dress-rehearsal item left by PR6. |
| Review | ✅ | [#40](https://github.com/tomblakeasaah196/hodlekki/pull/40) | 2026-10-04 | Review of PR1–PR7 with the fixes in one PR (§28.4 "Review and fix" rows). **Run the checks AGENTS.md lists before pushing**: CI now parses every `assets/se/js` module, rejects calls to functions that do not exist, and runs the migrations and the integration suite on MySQL 8.0 and MariaDB 10.11. Games run only through the engine in `games_engine.php` / `party_games.php`; correct a result by voiding the round. The stage sound sprite is still not delivered (O-list). |

### 28.4 Deviations and decisions log

Every design change made during the build is logged here (newest last), and the affected section is updated in the same PR.

| Date | PR | Sections | Change | Why |
|---|---|---|---|---|
| 2026-10-03 | Guide | §9.4, A.3, A.7 | `se_message_runs` moved from the Phase B migration into `se_ops` (Phase A). A.7 renamed `20261013090200_se_live_state.sql`. | Waitlist promotions and on-demand link SMS ship with registration (PR2). |
| 2026-10-03 | Guide | Appendix A rules, §9.5 | `fk_se_karaoke_reg` is `ON DELETE RESTRICT`, and deleting a draft removes karaoke rows first. | MySQL 8 rejects `CASCADE` on a column that feeds a stored generated column. |
| 2026-10-03 | Guide | §11.13 | Test mode and Reset rehearsal start in PR3, with the toggle in Studio → Live and the host console. PR4–PR6 extend the reset to their tables. | The check-in rehearsal (PR3) needs test mode before any game exists. |
| 2026-10-03 | PR0 | §21.3, §27 (O16) | `./composer.phar`, `./tailwindcss-linux-x64`, `./input.css` and `./tailwind.config.js` are root-anchored in `.deployignore`, not bare. `node_modules`, `.env*`, `error_log`, `.vscode`, `.idea`, `.DS_Store` and `Thumbs.db` stay bare (any depth), as PR0 specifies. | A bare `input.css` would also match `assets/se/css/input.css`, the Tailwind source PR1 adds (§13.1), and silently stop it deploying. The four are root-only dev artefacts, so the root-only form is the correct one. |
| 2026-10-03 | PR0 | §27 (O16) | The CI guard also asserts that `index.php`, `includes/db.php` and `webhook/.htaccess` **would** be copied, on top of the excluded-path checks PR0 lists. | A pattern broad enough to exclude the whole tree would otherwise pass a guard that only looks for paths that must be absent. `webhook/.htaccess` is the regression test for the root-anchored `./.htaccess` entry (§21.3). |
| 2026-10-04 | PR1 | §13.1.2 | **No `style="…"` attributes in server-rendered HTML.** §13.1.2 told later PRs to colour teams with `style="--t: #000000"`, which the §19.7 CSP forbids. Team colours now go into the nonced `<style>` block via `se_theme_css_vars($theme, $teams)`, which emits `--team-<i>`, `-on`, `-glow` and `-ring`. | A CSP nonce covers `<style>` elements but **not** style attributes: Chromium dropped all 41 of them in PR1's first render, leaving the hero unstyled. Only the Preact `style` prop is safe, because it goes through the CSSOM. |
| 2026-10-04 | PR1 | Appendix E, §20.3 | Added `registration.capacity_reviewed` (bool, default `false`) to the per-event settings. `capacity_save` sets it. | The H.1 checklist requires "a capacity number **or** 'unlimited' explicitly chosen", and `online_capacity IS NULL` means both "unlimited, on purpose" and "never opened the tab". Without a flag the item could never be satisfied, so an event with no seat limit could never be published. |
| 2026-10-04 | PR1 | §8.7 | New file `includes/special_events/db.php`, holding `se_table_exists()`, `se_lock_event()`, `se_schema_check()`, `se_audit()` and the module's exception types. | The PR1 build list names these four helpers without giving them a home, and they cannot live in `util.php`, which must stay database-free so it is unit-testable from the CLI. |
| 2026-10-04 | PR1 | §8.6.1 | Vendored paths carry the version (`assets/se/vendor/preact-10.27.2/preact.module.js`), and the import map moved into `se_import_map()` in `bootstrap.php`, shared by `e/index.php` and the Studio. | §8.6.1 asks for `Cache-Control: immutable` on `vendor/**` *because* "file names contain the version", but its illustrative import map showed unversioned paths. An unversioned immutable URL would serve a stale library for a year after an upgrade. One shared function stops the portal's and the Studio's maps drifting apart. |
| 2026-10-04 | PR1 | §12.5 | `asset_update`, `asset_delete` and `crew_revoke` take the **row's** id in `id` (as §12.5 writes it) and derive the event from that row, rather than also reading `id` as the event. | Reading one key as both ids is ambiguous, and deriving the event from the row additionally stops a caller pairing someone else's asset or crew row with an event they happen to control. |
| 2026-10-04 | PR1 | §21.3 | `.gitignore`'s `vendor/` is now root-anchored (`/vendor/`), and `live/*` plus its two exceptions were added. `./live` was **not** added to `.deployignore`. | The unanchored `vendor/` also matched `assets/se/vendor/`, hiding the very files that must be committed because the host has no Node (§8.6.1). The §28.3 note on PR0 asked PR1 to exclude `./live` from the deploy, but §23.1 requires `live/.htaccess` and `live/.keep` to **reach** the docroot so the directory exists; runtime snapshots are never in the repo, and tar never deletes, so there is nothing to exclude. Verified against the tar guard. |
| 2026-10-04 | PR1 | §22.1 | CI runs the Node tests as `node --test "tests/special_events/js/*.test.mjs"`, not against a bare directory. | `node --test <dir>` is unsupported on Node 22, which resolves the path as a module and fails with "Cannot find module". |
| 2026-10-04 | PR1 | §28.3 (PR0 note) | The PR0 note asking PR1 to add `./live` to `.deployignore` is superseded; see the §21.3 row above. | Kept here so the next PR does not re-apply it. |
| 2026-10-04 | PR2 | §8.7, §12.5, §18.6 | The attendee workbook is built in a new `includes/special_events/export.php` and streamed by a new `api/special_events_export.php?event=<public_id>` (GET, ERP session + `attendee.export`). The Studio's `attendees_export` action returns `{url, filename}` instead of a file. | A binary `.xlsx` cannot travel inside the `{status, message, data}` envelope §12.1 requires of every action. Splitting the builder from the endpoint keeps the §8.7 `export.php` entry honest and lets later phases reuse it. |
| 2026-10-04 | PR2 | §12.5 | Attendee actions name the person in **`attendee_id`**; `id` stays the event, as it is for every other Studio action. | §12.5 writes `id` for both. PR1 already resolved the same clash for assets and crew by naming the row; attendees cannot use that form because almost every attendee action also needs the event for its capability check, so the person gets the second key. |
| 2026-10-04 | PR2 | §8.7 | `se_site_origin()`, `se_event_url()`, `se_studio_url()` and `se_import_map()` moved from `bootstrap.php` to `util.php`. | The portal, the share kit and the card payloads all build URLs, and the CLI unit tests build them with no database. `util.php` is the database-free file the harness can load; `bootstrap.php` is not. |
| 2026-10-04 | PR2 | §10.3 | A bare international number with no `+` and no `00` (for example `447911123456`) is **rejected**, not guessed. Only Nigerian forms (`0803…`, `803…`, `234…`, `+234…`) are normalised without a prefix. | `0803…` and a nine-digit foreign subscriber number are indistinguishable, and silently inventing a country code would send the welcome SMS to a stranger. The fixtures in `tests/special_events/fixtures/phones.json` state the idempotence invariant over `'+'.$e164` and the display form for that reason. |
| 2026-10-04 | PR2 | §13.3 | Marquee screen **S4 (the YouTube trailer / voice-over)** is deferred. S0–S3 and S5–S7 are built. | No per-event setting holds a video URL (Appendix E has no key for it), and inventing one here would clash with the brand-kit work. PR3 adds `portal.trailer_url` alongside the other portal settings, or it is dropped. |
| 2026-10-04 | PR2 | §22.1, §22.2 | The §22.2 integration tests are committed at `tests/special_events/integration/run.php` but are **not** run by the CI job, and were **not** executed before merge. `tests/special_events/smoke_seed.php` was added so the portal smoke can be run by hand. | The shared runner has no MySQL service, and the GitHub App used for this branch cannot push workflow files, so the temporary job that would have added one could not be created. The seat race and the cancel/promotion test must be run against a scratch MySQL before Phase A is declared done (Appendix H.2). |
| 2026-10-04 | PR3 | §10.5, §12.5 | `se_checkin()`, `se_desk_search()` and `se_checkin_undo()` all take `$days` as an argument, which the guide's signatures omit. | Every one of them needs `se_event_phase()` to decide which day a check-in belongs to, and re-reading `se_event_days()` inside each would be three extra queries per tap at the busiest moment of the night. The caller already has it. |
| 2026-10-04 | PR3 | A.5, A.7, §9.4 | The two migrations keep Appendix A's placeholder timestamps, `20261013090000_se_checkin_teams.sql` and `20261013090200_se_live_state.sql`. `…090100` is left free for PR4's A.6. | They already sort after `20261006090300_se_seed_settings.sql`, and renaming them would have broken the gap Appendix A deliberately leaves between A.5 and A.7 for the Phase B file that lands next. |
| 2026-10-04 | PR3 | §11.12 | `SE_SCENES` adds **`finale`** to the eleven scenes §11.12 lists. | §13.8's scene table and §11.11's run-of-show both describe a closing frame that §11.12's enumeration leaves out. Adding it now means PR6 does not have to migrate `se_live_state.scene`, and an unbuilt scene already falls back to standby on the stage. |
| 2026-10-04 | PR3 | §10.6.5 | `se_parse_hex_list()` drops a colour that has already appeared in the list; the first occurrence keeps its position. | §10.6.5 says the paste box "fills teams in order" and is silent on duplicates. Two teams with the same hex get the same auto-generated label, and "Team Red, the other one" is not something a host can say across a hall. |
| 2026-10-04 | PR3 | §10.6.5, §13.2.4 | `se_team_similarity_warnings()` keeps its Studio-wide default of ΔE `0.12`; `se_teams_payload()` passes `0.08` explicitly for teams. | §10.6.5 specifies 0.08 for team colours, but the same helper already warns about the brand palette at 0.12. Passing the threshold at the call site keeps one implementation and two honest thresholds. |
| 2026-10-04 | PR3 | §13.13 | A **Check-in** tab was added to the Studio (verses and the QR posters), alongside Teams and Live. | §13.13 lists Teams and Live but gives welcome verses (§10.9) and the check-in posters (§14.2) no home. They belong together: both are about the moment somebody walks in. |
| 2026-10-04 | PR3 | §12.5 | `verse_approve` and `verse_delete` name the verse in **`verse_id`**; `id` stays the event, as everywhere else in the Studio API. | The same `id`-means-two-things clash PR1 resolved for assets and PR2 for attendees. |
| 2026-10-04 | PR3 | §14.2 | `render_save` accepts `poster_a4` and `poster_a3` only, and the PDF is a separate GET endpoint, `api/special_events_poster_pdf.php?event=<public_id>&asset=<id>`. | A PDF cannot travel inside the §12.1 JSON envelope — the same reason PR2 split the attendee workbook out. The other render kinds in §14.2 belong to formats PR4 and PR6 have not built yet; adding them now would create roles with nothing to put in them. |
| 2026-10-04 | PR3 | §13.6, §8.6.1 | The `/in` check-in client is `assets/se/js/portal/checkin.js`, loaded by a **dynamic** `import()` from `portal/main.js`, rather than being its own preloaded surface. | `/in` is the portal with a different view, and giving it a surface entry would mean preloading the whole check-in flow on every visit to the event page. The dynamic import is also invisible to `preload_test.php`'s static graph, which is correct: it is not on the critical path. The Studio's poster engine is loaded the same way. |
| 2026-10-04 | PR3 | §8.5, §12.4 | Two functions the guide names but does not specify, `se_live_console()` and `se_desk_state()`, were written in `live.php`. The console payload carries `program`, `game`, `round` and `karaoke` as `null`. | §12.4 lists the `console` and `desk_state` actions and Appendix B gives the snapshot shapes, but nothing defines what the crew endpoints return. Fixing the shape now — including the four nulls — means the host console can be written once and lit up by PR4 without a client rewrite. |
| 2026-10-04 | PR3 | §13.1.6 | The SFX sprite **`assets/se/sfx/se-sfx.mp3` is not in this PR**. The cue map `se-sfx.json` and `LICENSES.md` ship with every source marked *Pending*, and `@se/core/sfx.js` fails silently when the sprite 404s. | The thirteen cues need audio that is actually licensed for this use, and picking it is a decision for the owner, not a thing to guess at in a build. Nothing else in the module depends on it: a missing sprite costs the room its stingers and nothing more. PR4 must add the file and complete the licence table. |
| 2026-10-04 | PR3 | §14.3 | `cards.php` called `se_event_theme($pdo, $event)`; the function takes only the event row. Fixed. | A latent fatal from PR2 — `se_card_payload()` is only reached from the public `card` action, which no automated test covered until the welcome and team cards were added. |
| 2026-10-04 | PR3 | §22.2 | The check-in race is committed in `tests/special_events/integration/run.php` but, like PR2's seat race, was **not executed** before this PR. | The sandbox this was built in has no MySQL or MariaDB and no reachable package mirror to install one. The suite must be run against a scratch MySQL before Phase A is declared done (Appendix H.2); the test forks forty workers and asserts one check-in row per person, unique player numbers 1…40, and team sizes within one. |
| 2026-10-04 | PR4 | §12.5, §9.3 | A new `se_event_settings_patch($pdo, $event, $patch, $actorId)` writes one branch of `settings_json` **without** `expected_row_version`. The Studio uses it for *publish the song list* and the programme's *public time mode*; every form save still goes through `se_event_update()`. | §9.3 requires a version stamp on writes to `se_events` because two people editing the same tab must collide. A single toggle is not an edit session — the client has no form state to lose — and demanding a version there meant the Karaoke tab had to re-read the event before every switch, which is both slower and a worse race than last-press-wins. |
| 2026-10-04 | PR4 | §12.4 | `api/special_events_live_api.php` carried its own copies of `se_live_console()` and `se_desk_state()` as well as the ones in `live.php`; the API copies were deleted. | PR3 defined both functions in `live.php` (logged above) and then redefined them in the endpoint that includes it. PHP fatals on a duplicate function, so the live API was dead for any request that reached it — caught here because the console now has a programme and a karaoke block to return. |
| 2026-10-04 | PR4 | §10.8, §15.5 | `se_songs_parse_text()` also splits on en and em dashes and on the word "by", drops a leading list number ("12. Imela"), and pulls a duration off the end of the artist ("Sinach 5:12"). `se_song_duration_parse()` accepts `h:mm:ss` and caps a song at two hours (`SE_SONG_MAX_SECONDS`). | §15.5 specifies the AI path for pictures but leaves the paste path as "title, artist, optional length". Real lists are numbered and use whichever dash the phone keyboard offered, and a parser that turns "12. Imela — Nathaniel Bassey" into a song called "12. Imela" produces exactly the duplicates the normalised library exists to prevent. |
| 2026-10-04 | PR4 | §10.8 | `se_song_norm()` strips the quote characters glibc's `iconv` TRANSLIT leaves behind (`'a` for `á`) rather than turning them into spaces. | Otherwise "Imelá" normalises to `imel a` and "Imela" to `imela`, and the library holds both — the one outcome the normalised unique key is there to stop. |
| 2026-10-04 | PR4 | §14.2 | **Format Studio is deferred to PR6.** The six templates (`wa_status`, `ig_square`, `ig_portrait`, `og_card`, `projector`, `table_tent`), Render all, the JSZip download and the sanitised custom templates are not in this PR. | It was the explicit cut-line item in the PR4 build list, and the programme, karaoke, messages and cron work — all of which the night actually depends on — took the budget. Nothing else references the missing templates: `render_save` still accepts only the two poster kinds PR3 shipped, so there are no empty asset roles. |
| 2026-10-04 | PR4 | §13.1.6 | The SFX sprite is **still** missing: PR3 left `assets/se/sfx/se-sfx.mp3` and its licence table to PR4, and PR4 has not added them. | Unchanged reason: the thirteen cues need audio that is licensed for this use, which is an owner decision rather than a build guess. `@se/core/sfx.js` still fails silently, so the cost is the room's stingers and nothing more. Re-assigned to PR6. |
| 2026-10-04 | PR4 | §22.2 | The two new integration tests — the ten-way karaoke song race and cron idempotency — are committed in `tests/special_events/integration/run.php` but were **not executed** before merge, as for PR2 and PR3. | The sandbox still has no MySQL or MariaDB and no reachable package mirror. They must run against a scratch MySQL before Phase B is declared done (Appendix H.2): the race asserts exactly one claim out of ten parallel picks and that a loser can take a different song, and the cron test asserts one `se_message_runs` row and one SMS campaign per run key however often the job fires. |

| 2026-10-04 | PR5 | §11.2, §13.7, §13.10 | The first PR5 delivery exposes the games setup API and a functional `/play` join shell; the richer per-round phone and stage visualisations remain extension points for PR6. | Keep the engine and privacy/scoring invariants shippable without duplicating the existing stage renderer; the host controls and APIs are ready for the next game surfaces. |
| 2026-10-04 | PR6 | §11.9, §12.4 | Added `charades_end` to the live API. It explicitly closes a presenter turn before its deadline, using the same game-control capability and version guard as `charades_start` and `charades_mark`. | The guide requires an **End turn** console control but its §12.4 action table named only start and mark, leaving no safe endpoint for the button. |
| 2026-10-04 | PR6 | §11.11, §12.4 | Added the `finale` live action and `se_finale_payload()`. The action atomically selects the finale scene and stores champion, MVP, awards and team standings in its payload for PR7's recap. | §11.11 requires Console → Finale and recap data, but §12.4 omitted an action that can run it. A generic scene action would have forced the browser to calculate authoritative winners. |
| 2026-10-04 | PR6 | H.3 | The automated party-game scoring/privacy checks were run, but the physical dress rehearsal (20 phones on venue Wi-Fi, projector and sound) could not be performed in the build environment. Its step-by-step runbook is in `how_to_use.md` §22 and remains required before Chara. | H.3 depends on the venue, crew, projector, licensed sound and 20 real devices; claiming it passed in a headless repository runner would be false. |
| 2026-10-04 | PR6 | §13.1.6, §14.2 | The licensed SFX sprite and the PR4-deferred Format Studio remain deferred. Party games continue to degrade silently without the sprite; PR3's poster/card renderer is unchanged. | Neither item is in PR6's Build list. The sprite still requires an owner-approved licensed source, and absorbing the separate Format Studio delivery into the games/finale PR would violate the scope rule. |
| 2026-10-04 | PR7 | §22.2, Appendix H.5 | The hand-off idempotency, report privacy and insight-count tests were added/reviewed, but the database-backed migration and integration suite and browser smoke could not be executed in the build runner. | The runner has no PHP or database runtime, Debian package mirrors are unreachable, and the pinned Tailwind binary host was also unreachable. JavaScript tests (118 checks) and `git diff --check` passed; CI supplies repository PHP lint. Run A.9–A.10 twice on scratch MySQL 8 and MariaDB and perform H.5 before merge. |
| 2026-10-04 | AI copywriter hotfix | §15.1, §15.8, Appendix D.7 | `copywrite` moved to prompt version 2 with `max_tokens: 4096`, `thinking_budget: 0` for Gemini 2.5 Flash, an exact-three-variants schema and a 1200-character description post-processing cap. Invalid JSON retries now name parse/truncation problems without echoing model output and raise the output-token budget when `MAX_TOKENS` is reported. | Production showed portal-description generation failing with “The AI did not return usable JSON” while shorter taglines worked. The old 1024-token ceiling could be consumed by hidden thinking and truncate the JSON before it closed; the 600-character API clamp also left less room than a 120-word description. |
| 2026-10-04 | Programme import repair | §10.7.3, §12.5, §15.3, Appendix D.2 | Programme sources upload directly from the Programme tab with role `program_source`, then use the existing asset id for the AI request. Review rows now carry editable day, start and duration; append can retain rows across days while replace remains one explicit selected day. `program_extract` v2 includes `end_time` so written ranges provide exact durations. | The prior UI pointed producers to Assets but exposed no picker, so an asset id could never reach `program_import`; the Programme tab also read a nonexistent `current.value.event` wrapper and rendered blank. |
| 2026-10-05 | AI copywriter quality pass | §15.8 | `copywrite` moved to prompt version 3: a Lagos church creative-team persona, purpose-specific guidance, three named variant angles, a 70–110-word target for portal descriptions, an explicit banned-cliché list and a ban on restating the tagline. Post-processing now strips an echoed tagline sentence and drops near-duplicate variants (Jaccard ≥ 0.72) without ever emptying the list. Studio shows per-purpose hints, a description word count and the full Markdown capability list. | After the JSON hotfix the task succeeded technically but returned three near-identical, cliché-heavy, much-too-short descriptions, because one generic prompt served every purpose and nothing asked for distinct angles. Token budget, thinking budget, schema, retry behaviour and the human-review rule are unchanged. |
| 2026-10-04 | Review and fix (PR1–PR7) | §11, §12.2, §12.4 | The games backend was rebuilt to the spec: one round state machine for every game type (pending → armed → open → locked → revealed → scored, or void); `public.json`'s `game.state` mirrors the round so `paceFor()` polls at 1 s only while a round runs; the public view never carries an answer before the reveal, a name, or a charades phrase; Family Feud never auto-locks; a void round's next round replays the same question (that is the correction path); Reset rehearsal voids TEST ledger rows and deletes test rounds and survey answers. Studio API gains `game_order`. | PR5/PR6 shipped engine, console and phone code that disagreed with each other and with the guide: most games could not be run end to end. |
| 2026-10-04 | Review and fix (PR1–PR7) | §13.6, §13.8, §13.10, §13.13 | Rebuilt the Studio Games tab (lineup, plain-unit settings, question banks with per-kind forms, Feud board builder), the host console's game runner (one big next step per moment; inline reasons instead of `prompt()`; three columns with games in the middle), `/play` (now **Preact**, driven by the snapshots; `me` only on personal triggers) and the stage game scenes (crossfade only on a new scene; in-place updates otherwise). The Studio's 17 tabs are grouped in a side navigation. | The old `/play` rebuilt its DOM every second (wiping typed survey answers) and polled PHP from every phone each second; the stage crossfaded on every snapshot; Insights, Hand-off and Settings were off-screen in an overflowing tab row. |
| 2026-10-04 | Review and fix (PR1–PR7) | §10.3.5, §13.6, §10.1 | New route `/e/<slug>/me` opens the guest page from the device cookie (the token route is unchanged); the check-in page and the read-only `/play` gain **I have a code from the desk** (the `transfer` API had no caller); on the night the portal's hero button says **Check in** then **Join the games** for a registered guest. | Walk-ins, moved phones and anyone who lost the texted link had no way to their page or the karaoke picker; desk transfer codes had nowhere to be typed. |
| 2026-10-04 | Review and fix (PR1–PR7) | §17.3 | `se_handoff_push()` overrides may only redirect a **ready** person between Reach and Embrace or hold them back. | Before, an override turned any row ready, so a crafted request could hand off a guest who never consented, had opted out, is a member or was already handed off. Integration-tested. |
| 2026-10-04 | Review and fix (PR1–PR7) | §22.1, §22.2 | CI now parses every module in `assets/se/js` (`node --check`), runs `tests/special_events/function_calls_test.php` (every plain call must be a built-in or defined in the repo), and runs the migrations twice plus the integration suite on **MySQL 8.0 and MariaDB 10.11** service containers; deploy waits for them. 116/116 locally on MySQL 8.0.46 and MariaDB 10.11.14. | A syntax error blanked the Studio (#39) and a call to a function never written cut every portal page off (PR4); neither was visible to `php -l`. PR7 noted the database-backed suite had not been run. |
| 2026-10-04 | Review and fix (PR1–PR7) | §13.1.6 | Unchanged and still open: `assets/se/sfx/se-sfx.mp3` has not been delivered, so the stage plays no sound (it fails quietly, as designed). Commit it only with every cue's source and licence recorded in `assets/se/sfx/LICENSES.md`. | Licensing has to be recorded by the owner; the review did not invent audio. |

---

## Appendix A — Complete SQL migrations

**Rules for these files** (in addition to `db/migrations/README.md`):
- `db/migrate.php` sends the whole file to MySQL in a single `PDO::exec()`. Tested with PHP 8.3 on MySQL 8.0 and MariaDB 10.11: an error in **any** statement raises an exception and stops the file there. The statements before it are already applied (DDL auto-commits), the statements after it are not run, the file is **not** recorded in `schema_migrations`, and the deploy stops. The next deploy re-runs the file **from the top**. So:
  1. Keep every statement idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT … ON DUPLICATE KEY UPDATE`), so that a re-run after a fix skips what already exists.
  2. **Never put a semicolon inside an SQL comment** (it would split a statement for anyone running the file statement by statement).
  3. After each deploy, check **Studio → Settings → Health → Schema**: `se_schema_check()` compares `information_schema` with the expected table/column list in `constants.php` and shows anything missing in red. It also catches a table that an earlier, half-applied run created with an outdated definition (`IF NOT EXISTS` never alters an existing table).
  4. Before merging, apply every new file to scratch databases on **both MySQL 8.0 and MariaDB 10.x** (the production engine is unconfirmed, §2.3). The engines differ: the rule below about generated columns only fails on MySQL.
- **No `CASCADE` / `SET NULL` foreign key on a base column of a `STORED` generated column.** MySQL 8 rejects it (error 1215 "Cannot add foreign key constraint"); MariaDB accepts it. Use `ON DELETE RESTRICT` there and delete the child rows explicitly first (§9.5 item 1). Today this applies to `se_karaoke_entries.registration_id` (base of `active_singer_key`) and `se_karaoke_entries.song_id` (base of `active_song_key`). `se_handoff_items.contact_id` (base of `done_contact_key`) has no foreign key.
- All ten files below were applied in order, and then re-applied (idempotency), on MySQL 8.0.46 and MariaDB 10.11.14 with `sql_mode` = `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`. Both produce the same 41 `se_*` tables.
- Filenames sort after `20261005090000_security_core.sql`; adjust timestamps at merge time, keeping the order.
- No foreign keys to pre-existing tables (`users`, `sms_campaigns`, `reach_*`). Module-internal FKs as declared.

### A.1 `20261006090000_se_core.sql` (Phase A)

```sql
-- 20261006090000_se_core.sql
-- Special Events core: module settings, series, events, slugs and event days.
-- Standalone module (decision D2): nothing here references events/checkins/attendance.

CREATE TABLE IF NOT EXISTS se_settings (
    setting_key    VARCHAR(60)  NOT NULL,
    setting_value  MEDIUMTEXT   NULL,
    updated_by     INT UNSIGNED NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_series (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    description  TEXT         NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at  DATETIME     NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_events (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id                 CHAR(12)     NOT NULL,
    preview_key               CHAR(22)     NOT NULL,
    series_id                 INT UNSIGNED NULL,
    cloned_from_event_id      INT UNSIGNED NULL,
    slug                      VARCHAR(40)  NOT NULL,
    title                     VARCHAR(120) NOT NULL,
    edition_label             VARCHAR(40)  NULL,
    tagline                   VARCHAR(160) NULL,
    description_md            MEDIUMTEXT   NULL,
    organizer_label           VARCHAR(80)  NOT NULL DEFAULT 'Envision',
    venue_name                VARCHAR(160) NULL,
    venue_address             VARCHAR(255) NULL,
    venue_map_url             VARCHAR(500) NULL,
    venue_notes               VARCHAR(255) NULL,
    starts_at                 DATETIME     NOT NULL,
    ends_at                   DATETIME     NOT NULL,
    status                    ENUM('draft','published','cancelled','archived') NOT NULL DEFAULT 'draft',
    cancel_reason             VARCHAR(255) NULL,
    visibility                ENUM('public','unlisted') NOT NULL DEFAULT 'unlisted',
    theme_preset              VARCHAR(30)  NOT NULL DEFAULT 'marquee',
    brand_primary             CHAR(7)      NOT NULL DEFAULT '#1D356A',
    brand_secondary           CHAR(7)      NOT NULL DEFAULT '#D11920',
    brand_accent              CHAR(7)      NULL,
    palette_json              TEXT         NULL,
    font_display              VARCHAR(60)  NOT NULL DEFAULT 'Unbounded',
    font_body                 VARCHAR(60)  NOT NULL DEFAULT 'Inter',
    logo_asset_id             INT UNSIGNED NULL,
    hero_asset_id             INT UNSIGNED NULL,
    hero_video_asset_id       INT UNSIGNED NULL,
    og_asset_id               INT UNSIGNED NULL,
    reg_opens_at              DATETIME     NULL,
    reg_closes_at             DATETIME     NULL,
    reg_override              ENUM('none','force_open','force_closed') NOT NULL DEFAULT 'none',
    reg_override_note         VARCHAR(255) NULL,
    reg_override_by           INT UNSIGNED NULL,
    reg_override_at           DATETIME     NULL,
    online_capacity           INT UNSIGNED NULL,
    auto_close_at_capacity    TINYINT(1)   NOT NULL DEFAULT 1,
    waitlist_enabled          TINYINT(1)   NOT NULL DEFAULT 0,
    waitlist_capacity         INT UNSIGNED NULL,
    waitlist_promotion        ENUM('auto_confirm','manual') NOT NULL DEFAULT 'auto_confirm',
    waitlist_notify_sms       TINYINT(1)   NOT NULL DEFAULT 1,
    self_cancel_enabled       TINYINT(1)   NOT NULL DEFAULT 0,
    walkin_enabled            TINYINT(1)   NOT NULL DEFAULT 1,
    walkin_capacity           INT UNSIGNED NULL,
    walkin_hard_cap           TINYINT(1)   NOT NULL DEFAULT 0,
    seats_left_mode           ENUM('never','threshold','always') NOT NULL DEFAULT 'never',
    seats_left_threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 70,
    team_rr_pointer           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_player_no            INT UNSIGNED NOT NULL DEFAULT 1,
    next_karaoke_no           INT UNSIGNED NOT NULL DEFAULT 1,
    settings_json             MEDIUMTEXT   NULL,
    ticketing_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
    row_version               INT UNSIGNED NOT NULL DEFAULT 1,
    created_by                INT UNSIGNED NULL,
    updated_by                INT UNSIGNED NULL,
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at              DATETIME     NULL,
    cancelled_at              DATETIME     NULL,
    archived_at               DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_events_public_id (public_id),
    UNIQUE KEY uniq_se_events_slug (slug),
    KEY idx_se_events_status_start (status, starts_at),
    KEY idx_se_events_series (series_id),
    CONSTRAINT fk_se_events_series FOREIGN KEY (series_id) REFERENCES se_series (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_slugs (
    slug          VARCHAR(40)  NOT NULL,
    event_id      INT UNSIGNED NOT NULL,
    is_canonical  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (slug),
    KEY idx_se_slugs_event (event_id),
    CONSTRAINT fk_se_slugs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_event_days (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    day_date           DATE         NOT NULL,
    label              VARCHAR(60)  NULL,
    doors_open_at      DATETIME     NOT NULL,
    starts_at          DATETIME     NOT NULL,
    ends_at            DATETIME     NOT NULL,
    checkin_closes_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_event_days (event_id, day_date),
    CONSTRAINT fk_se_event_days_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### A.2 `20261006090100_se_people.sql` (Phase A)

```sql
-- 20261006090100_se_people.sql
-- Contacts (people across events), registrations, access tokens, devices, custom form fields.

CREATE TABLE IF NOT EXISTS se_contacts (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone_e164            VARCHAR(16)  NOT NULL,
    phone_display         VARCHAR(24)  NULL,
    sms_capable           TINYINT(1)   NOT NULL DEFAULT 1,
    first_name            VARCHAR(80)  NOT NULL,
    last_name             VARCHAR(80)  NOT NULL DEFAULT '',
    email                 VARCHAR(190) NULL,
    gender                ENUM('Male','Female') NULL,
    member_user_id        INT UNSIGNED NULL,
    member_ambiguous      TINYINT(1)   NOT NULL DEFAULT 0,
    member_checked_at     DATETIME     NULL,
    consent_followup      TINYINT(1)   NOT NULL DEFAULT 0,
    consent_followup_at   DATETIME     NULL,
    consent_text_hash     CHAR(64)     NULL,
    opted_out_at          DATETIME     NULL,
    erased_at             DATETIME     NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_contacts_phone (phone_e164),
    KEY idx_se_contacts_member (member_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_registrations (
    id                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                     INT UNSIGNED NOT NULL,
    contact_id                   INT UNSIGNED NOT NULL,
    reg_code                     CHAR(8)      NOT NULL,
    ref_code                     CHAR(6)      NOT NULL,
    status                       ENUM('confirmed','waitlisted','cancelled','removed') NOT NULL,
    seat_pool                    ENUM('online','walkin') NULL,
    channel                      ENUM('portal','walkin_self','walkin_desk','studio') NOT NULL DEFAULT 'portal',
    is_member                    TINYINT(1)   NOT NULL DEFAULT 0,
    is_test                      TINYINT(1)   NOT NULL DEFAULT 0,
    first_name                   VARCHAR(80)  NOT NULL,
    last_name                    VARCHAR(80)  NOT NULL DEFAULT '',
    gender                       ENUM('Male','Female') NULL,
    email                        VARCHAR(190) NULL,
    display_name                 VARCHAR(40)  NOT NULL,
    name_correction              VARCHAR(160) NULL,
    how_heard                    VARCHAR(30)  NULL,
    how_heard_other              VARCHAR(80)  NULL,
    src                          VARCHAR(20)  NULL,
    referred_by_registration_id  INT UNSIGNED NULL,
    companion_of_registration_id INT UNSIGNED NULL,
    karaoke_interest             TINYINT(1)   NOT NULL DEFAULT 0,
    wants_visit                  TINYINT(1)   NOT NULL DEFAULT 0,
    answers_json                 TEXT         NULL,
    team_id                      INT UNSIGNED NULL,
    team_assigned_at             DATETIME     NULL,
    player_no                    INT UNSIGNED NULL,
    first_checkin_at             DATETIME     NULL,
    confirmed_at                 DATETIME     NULL,
    waitlisted_at                DATETIME     NULL,
    cancelled_at                 DATETIME     NULL,
    cancelled_by                 ENUM('self','crew','system') NULL,
    cancel_reason                VARCHAR(160) NULL,
    ip_hash                      CHAR(64)     NULL,
    created_at                   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_reg_contact (event_id, contact_id),
    UNIQUE KEY uniq_se_reg_code (event_id, reg_code),
    UNIQUE KEY uniq_se_reg_ref (event_id, ref_code),
    UNIQUE KEY uniq_se_reg_player (event_id, player_no),
    KEY idx_se_reg_status (event_id, status, seat_pool),
    KEY idx_se_reg_waitlist (event_id, status, waitlisted_at),
    KEY idx_se_reg_team (event_id, team_id),
    KEY idx_se_reg_contact (contact_id),
    CONSTRAINT fk_se_reg_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_reg_contact FOREIGN KEY (contact_id) REFERENCES se_contacts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_access_tokens (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    purpose          ENUM('manage','transfer') NOT NULL,
    token_hash       CHAR(64)     NOT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at       DATETIME     NOT NULL,
    last_used_at     DATETIME     NULL,
    used_at          DATETIME     NULL,
    revoked_at       DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_tokens_hash (token_hash),
    KEY idx_se_tokens_reg (registration_id),
    KEY idx_se_tokens_expiry (expires_at),
    CONSTRAINT fk_se_tokens_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_tokens_reg FOREIGN KEY (registration_id) REFERENCES se_registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_devices (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NULL,
    token_hash       CHAR(64)     NOT NULL,
    mode             ENUM('full','readonly') NOT NULL DEFAULT 'full',
    joined_games_at  DATETIME     NULL,
    ua_hash          CHAR(64)     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at     DATETIME     NULL,
    revoked_at       DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_devices_token (token_hash),
    KEY idx_se_devices_reg (registration_id),
    KEY idx_se_devices_games (event_id, joined_games_at),
    CONSTRAINT fk_se_devices_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_form_fields (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id     INT UNSIGNED NOT NULL,
    field_key    VARCHAR(40)  NOT NULL,
    label        VARCHAR(160) NOT NULL,
    type         VARCHAR(20)  NOT NULL,
    options_json TEXT         NULL,
    is_required  TINYINT(1)   NOT NULL DEFAULT 0,
    placeholder  VARCHAR(120) NULL,
    help_text    VARCHAR(255) NULL,
    audience     ENUM('everyone','guests','members') NOT NULL DEFAULT 'everyone',
    sort_order   INT          NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_form_fields_key (event_id, field_key),
    CONSTRAINT fk_se_form_fields_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`se_form_fields.type` ∈ `text, textarea, select, radio, checkbox, number, date` (validated in PHP).

### A.3 `20261006090200_se_ops.sql` (Phase A)

```sql
-- 20261006090200_se_ops.sql
-- Crew, assets, audit log, rate limits, daily metrics, AI jobs, AI usage log and SMS message runs.

CREATE TABLE IF NOT EXISTS se_crew (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    role        VARCHAR(20)  NOT NULL,
    added_by    INT UNSIGNED NULL,
    added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_crew (event_id, user_id, role),
    KEY idx_se_crew_user (user_id, revoked_at),
    CONSTRAINT fk_se_crew_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_assets (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED NULL,
    kind           VARCHAR(20)  NOT NULL,
    role           VARCHAR(30)  NOT NULL,
    title          VARCHAR(160) NULL,
    alt_text       VARCHAR(255) NULL,
    path           VARCHAR(255) NOT NULL,
    mime           VARCHAR(60)  NOT NULL,
    bytes          INT UNSIGNED NOT NULL,
    width          SMALLINT UNSIGNED NULL,
    height         SMALLINT UNSIGNED NULL,
    duration_ms    INT UNSIGNED NULL,
    sha256         CHAR(64)     NOT NULL,
    variants_json  TEXT         NULL,
    meta_json      TEXT         NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at     DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_se_assets_event_role (event_id, role, deleted_at),
    CONSTRAINT fk_se_assets_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_audit_log (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id               INT UNSIGNED NULL,
    actor_user_id          INT UNSIGNED NULL,
    actor_registration_id  INT UNSIGNED NULL,
    action                 VARCHAR(60)  NOT NULL,
    entity                 VARCHAR(40)  NULL,
    entity_id              INT UNSIGNED NULL,
    detail_json            TEXT         NULL,
    ip_hash                CHAR(64)     NULL,
    created_at             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_se_audit_event (event_id, created_at),
    KEY idx_se_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_rate_limits (
    bucket        VARCHAR(40)  NOT NULL,
    subject_hash  CHAR(64)     NOT NULL,
    window_start  DATETIME     NOT NULL,
    hits          INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (bucket, subject_hash, window_start),
    KEY idx_se_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_metrics_daily (
    event_id     INT UNSIGNED NOT NULL,
    metric_date  DATE         NOT NULL,
    metric       VARCHAR(30)  NOT NULL,
    dim          VARCHAR(30)  NOT NULL DEFAULT '',
    value        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (event_id, metric_date, metric, dim),
    CONSTRAINT fk_se_metrics_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_ai_jobs (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NULL,
    task             VARCHAR(40)  NOT NULL,
    input_json       MEDIUMTEXT   NULL,
    source_asset_id  INT UNSIGNED NULL,
    result_json      MEDIUMTEXT   NULL,
    status           ENUM('pending','ready','applied','discarded','failed') NOT NULL DEFAULT 'pending',
    error            VARCHAR(255) NULL,
    created_by       INT UNSIGNED NOT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at       DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_se_ai_jobs_event (event_id, task, created_at),
    CONSTRAINT fk_se_ai_jobs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_ai_requests (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id          INT UNSIGNED NULL,
    event_id        INT UNSIGNED NULL,
    user_id         INT UNSIGNED NULL,
    task            VARCHAR(40)  NOT NULL,
    model           VARCHAR(60)  NOT NULL,
    prompt_version  VARCHAR(20)  NOT NULL,
    input_tokens    INT UNSIGNED NULL,
    output_tokens   INT UNSIGNED NULL,
    latency_ms      INT UNSIGNED NULL,
    http_status     SMALLINT UNSIGNED NULL,
    ok              TINYINT(1)   NOT NULL DEFAULT 0,
    error_code      VARCHAR(40)  NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_ai_req_user (user_id, created_at),
    KEY idx_se_ai_req_event (event_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS runs are needed from Phase A: waitlist promotions and on-demand links.
CREATE TABLE IF NOT EXISTS se_message_runs (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    kind             VARCHAR(30)  NOT NULL,
    run_key          VARCHAR(80)  NOT NULL,
    scheduled_for    DATETIME     NULL,
    status           ENUM('scheduled','queued','skipped','failed') NOT NULL,
    sms_campaign_id  INT          NULL,
    recipients       INT UNSIGNED NOT NULL DEFAULT 0,
    est_units        INT UNSIGNED NOT NULL DEFAULT 0,
    detail           VARCHAR(255) NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_message_run (event_id, run_key),
    KEY idx_se_message_runs_kind (event_id, kind),
    CONSTRAINT fk_se_message_runs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Delivery progress (sent/delivered) is read from SMS Studio (`sms_campaigns.sent_count`/`failed_count`, `sms_log`) through `sms_campaign_id`. It is never copied.

### A.4 `20261006090300_se_seed_settings.sql` (Phase A)

```sql
-- 20261006090300_se_seed_settings.sql
-- Default module settings. Existing values are never overwritten.

INSERT INTO se_settings (setting_key, setting_value) VALUES
    ('envision_department_id', '3'),
    ('default_brand_primary', '#1D356A'),
    ('default_brand_secondary', '#D11920'),
    ('default_theme_preset', 'marquee'),
    ('retention_months_guest', '24'),
    ('ai_user_hourly_limit', '30'),
    ('ai_event_daily_limit', '300'),
    ('source_codes_json', '{"wa":"WhatsApp","ig":"Instagram","fb":"Facebook","tt":"TikTok","x":"X","flyer":"Flyer","poster":"Poster","sms":"SMS","pulpit":"Church announcement","qr":"QR code","email":"Email"}')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
```

The default privacy notice text (`default_privacy_notice_md`) is long Markdown. It ships as a file `includes/special_events/defaults/privacy_notice.md`, read when the setting is empty, which avoids quoting issues in SQL.

### A.5 `20261013090000_se_checkin_teams.sql` (Phase B)

```sql
-- 20261013090000_se_checkin_teams.sql
-- Teams, check-ins, team move history, welcome verses, Bible lookup cache.

CREATE TABLE IF NOT EXISTS se_teams (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                 INT UNSIGNED NOT NULL,
    sort_order               TINYINT UNSIGNED NOT NULL,
    color_hex                CHAR(7)      NOT NULL,
    color_label              VARCHAR(30)  NOT NULL,
    name                     VARCHAR(40)  NULL,
    name_set_at              DATETIME     NULL,
    name_set_by              INT UNSIGNED NULL,
    captain_registration_id  INT UNSIGNED NULL,
    team_key                 CHAR(22)     NOT NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_teams_order (event_id, sort_order),
    UNIQUE KEY uniq_se_teams_key (team_key),
    CONSTRAINT fk_se_teams_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_checkins (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    day_date         DATE         NOT NULL,
    method           ENUM('self','desk','studio') NOT NULL,
    is_walkin        TINYINT(1)   NOT NULL DEFAULT 0,
    is_test          TINYINT(1)   NOT NULL DEFAULT 0,
    device_id        INT UNSIGNED NULL,
    checked_in_by    INT UNSIGNED NULL,
    verse_id         INT UNSIGNED NULL,
    checked_in_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_checkins_day (event_id, registration_id, day_date),
    KEY idx_se_checkins_time (event_id, checked_in_at),
    CONSTRAINT fk_se_checkins_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_checkins_reg FOREIGN KEY (registration_id) REFERENCES se_registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_team_moves (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    from_team_id     INT UNSIGNED NULL,
    to_team_id       INT UNSIGNED NOT NULL,
    method           ENUM('auto','crew') NOT NULL,
    reason           VARCHAR(160) NULL,
    moved_by         INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_team_moves_reg (event_id, registration_id),
    CONSTRAINT fk_se_team_moves_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_event_verses (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    translation      VARCHAR(10)  NOT NULL DEFAULT 'KJV',
    ref_display      VARCHAR(60)  NOT NULL,
    text             TEXT         NOT NULL,
    text_source      ENUM('lookup','manual') NOT NULL DEFAULT 'lookup',
    prayer_template  VARCHAR(300) NULL,
    sort_order       INT          NOT NULL DEFAULT 0,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    approved_by      INT UNSIGNED NULL,
    approved_at      DATETIME     NULL,
    second_approved_by INT UNSIGNED NULL,
    times_used       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_verses_event (event_id, is_active),
    CONSTRAINT fk_se_verses_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_bible_cache (
    translation  VARCHAR(10)  NOT NULL,
    ref_norm     VARCHAR(60)  NOT NULL,
    ref_display  VARCHAR(60)  NOT NULL,
    text         TEXT         NOT NULL,
    fetched_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (translation, ref_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### A.6 `20261013090100_se_program_karaoke.sql` (Phase B)

```sql
-- 20261013090100_se_program_karaoke.sql
-- Run-of-show items, global song library, event song lists, karaoke entries.

CREATE TABLE IF NOT EXISTS se_program_items (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id          INT UNSIGNED NOT NULL,
    day_id            INT UNSIGNED NOT NULL,
    sort_order        INT          NOT NULL,
    kind              VARCHAR(20)  NOT NULL DEFAULT 'other',
    title             VARCHAR(120) NOT NULL,
    public_blurb      VARCHAR(400) NULL,
    icon              VARCHAR(40)  NULL,
    host_name         VARCHAR(120) NULL,
    planned_start_at  DATETIME     NULL,
    duration_min      SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    is_public         TINYINT(1)   NOT NULL DEFAULT 1,
    is_featured       TINYINT(1)   NOT NULL DEFAULT 0,
    game_id           INT UNSIGNED NULL,
    media_asset_id    INT UNSIGNED NULL,
    crew_notes        TEXT         NULL,
    source            ENUM('manual','ai') NOT NULL DEFAULT 'manual',
    status            ENUM('planned','live','done','skipped') NOT NULL DEFAULT 'planned',
    started_at        DATETIME     NULL,
    ended_at          DATETIME     NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_program_order (event_id, day_id, sort_order),
    CONSTRAINT fk_se_program_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_program_day FOREIGN KEY (day_id) REFERENCES se_event_days (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_songs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(160) NOT NULL,
    artist        VARCHAR(160) NOT NULL DEFAULT '',
    duration_sec  SMALLINT UNSIGNED NULL,
    title_norm    VARCHAR(160) NOT NULL,
    artist_norm   VARCHAR(160) NOT NULL DEFAULT '',
    tags          VARCHAR(160) NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_songs_norm (title_norm, artist_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_event_songs (
    event_id    INT UNSIGNED NOT NULL,
    song_id     INT UNSIGNED NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    added_by    INT UNSIGNED NULL,
    added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, song_id),
    KEY idx_se_event_songs_song (song_id),
    CONSTRAINT fk_se_event_songs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_event_songs_song FOREIGN KEY (song_id) REFERENCES se_songs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_karaoke_entries (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    registration_id    INT UNSIGNED NOT NULL,
    song_id            INT UNSIGNED NOT NULL,
    status             ENUM('held','queued','up_next','on_stage','done','skipped','no_show','released','cancelled') NOT NULL,
    source             ENUM('prepick','checkin','portal','desk','dj') NOT NULL,
    enforce_unique     TINYINT(1)   NOT NULL DEFAULT 1,
    is_test            TINYINT(1)   NOT NULL DEFAULT 0,
    queue_no           INT UNSIGNED NULL,
    position           INT          NULL,
    held_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queued_at          DATETIME     NULL,
    on_stage_at        DATETIME     NULL,
    finished_at        DATETIME     NULL,
    updated_by         INT UNSIGNED NULL,
    active_song_key    INT UNSIGNED GENERATED ALWAYS AS (IF(enforce_unique = 1 AND status IN ('held','queued','up_next','on_stage','done'), song_id, NULL)) STORED,
    active_singer_key  INT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('held','queued','up_next','on_stage'), registration_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_karaoke_song (event_id, active_song_key),
    UNIQUE KEY uniq_se_karaoke_singer (event_id, active_singer_key),
    UNIQUE KEY uniq_se_karaoke_no (event_id, queue_no),
    KEY idx_se_karaoke_queue (event_id, status, position),
    CONSTRAINT fk_se_karaoke_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_karaoke_reg FOREIGN KEY (registration_id) REFERENCES se_registrations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_se_karaoke_song FOREIGN KEY (song_id) REFERENCES se_songs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### A.7 `20261013090200_se_live_state.sql` (Phase B)

```sql
-- 20261013090200_se_live_state.sql
-- Live control state per event (scene, active round, display keys, version).

CREATE TABLE IF NOT EXISTS se_live_state (
    event_id            INT UNSIGNED    NOT NULL,
    version             BIGINT UNSIGNED NOT NULL DEFAULT 1,
    scene               VARCHAR(30)     NOT NULL DEFAULT 'standby',
    scene_payload_json  TEXT            NULL,
    active_game_id      INT UNSIGNED    NULL,
    active_round_id     INT UNSIGNED    NULL,
    announcement_json   TEXT            NULL,
    sfx_seq             INT UNSIGNED    NOT NULL DEFAULT 0,
    sfx_cue             VARCHAR(20)     NULL,
    dirty               TINYINT(1)      NOT NULL DEFAULT 0,
    last_published_at   DATETIME(3)     NULL,
    room_key            CHAR(22)        NOT NULL,
    lobby_key           CHAR(22)        NOT NULL,
    stage_key           CHAR(22)        NOT NULL,
    updated_at          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_by          INT UNSIGNED    NULL,
    PRIMARY KEY (event_id),
    CONSTRAINT fk_se_live_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### A.8 `20261020090000_se_games.sql` (Phase C)

```sql
-- 20261020090000_se_games.sql
-- Decks and items, games, rounds, answers, buzzes, Family Feud survey and boards, score ledger.

CREATE TABLE IF NOT EXISTS se_decks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(120) NOT NULL,
    content_type  VARCHAR(20)  NOT NULL,
    scope         ENUM('library','event') NOT NULL DEFAULT 'library',
    event_id      INT UNSIGNED NULL,
    description   VARCHAR(255) NULL,
    translation   VARCHAR(10)  NOT NULL DEFAULT 'KJV',
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_decks_type (content_type, scope),
    CONSTRAINT fk_se_decks_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_deck_items (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    deck_id             INT UNSIGNED NOT NULL,
    sort_order          INT          NOT NULL DEFAULT 0,
    payload_json        MEDIUMTEXT   NOT NULL,
    difficulty          ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
    scripture_ref       VARCHAR(60)  NULL,
    scripture_text      TEXT         NULL,
    media_asset_id      INT UNSIGNED NULL,
    review_status       ENUM('draft','approved','rejected') NOT NULL DEFAULT 'draft',
    reviewed_by         INT UNSIGNED NULL,
    reviewed_at         DATETIME     NULL,
    source              ENUM('manual','ai','import') NOT NULL DEFAULT 'manual',
    ai_job_id           INT UNSIGNED NULL,
    times_used          INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_event_id  INT UNSIGNED NULL,
    last_used_at        DATETIME     NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_deck_items_deck (deck_id, sort_order),
    KEY idx_se_deck_items_review (deck_id, review_status),
    CONSTRAINT fk_se_deck_items_deck FOREIGN KEY (deck_id) REFERENCES se_decks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_games (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    type             VARCHAR(20)  NOT NULL,
    title            VARCHAR(120) NOT NULL,
    settings_json    TEXT         NOT NULL,
    weight           DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    status           ENUM('draft','ready','live','paused','finished') NOT NULL DEFAULT 'draft',
    program_item_id  INT UNSIGNED NULL,
    sort_order       INT          NOT NULL DEFAULT 0,
    started_at       DATETIME     NULL,
    finished_at      DATETIME     NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_games_event (event_id, sort_order),
    CONSTRAINT fk_se_games_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_game_items (
    game_id       INT UNSIGNED NOT NULL,
    deck_item_id  INT UNSIGNED NOT NULL,
    sort_order    INT          NOT NULL,
    PRIMARY KEY (game_id, deck_item_id),
    KEY idx_se_game_items_order (game_id, sort_order),
    CONSTRAINT fk_se_game_items_game FOREIGN KEY (game_id) REFERENCES se_games (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_game_items_item FOREIGN KEY (deck_item_id) REFERENCES se_deck_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_rounds (
    id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                   INT UNSIGNED NOT NULL,
    game_id                    INT UNSIGNED NOT NULL,
    round_no                   INT UNSIGNED NOT NULL,
    deck_item_id               INT UNSIGNED NULL,
    state                      ENUM('pending','armed','open','locked','revealed','scored','void') NOT NULL DEFAULT 'pending',
    attempt                    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    team_id                    INT UNSIGNED NULL,
    opponent_team_id           INT UNSIGNED NULL,
    presenter_registration_id  INT UNSIGNED NULL,
    is_test                    TINYINT(1)   NOT NULL DEFAULT 0,
    arm_at                     DATETIME(3)  NULL,
    opens_at                   DATETIME(3)  NULL,
    closes_at                  DATETIME(3)  NULL,
    locked_at                  DATETIME(3)  NULL,
    revealed_at                DATETIME(3)  NULL,
    eligible_json              TEXT         NULL,
    state_json                 MEDIUMTEXT   NULL,
    result_json                MEDIUMTEXT   NULL,
    void_reason                VARCHAR(160) NULL,
    created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_rounds_no (game_id, round_no),
    KEY idx_se_rounds_event_state (event_id, state),
    CONSTRAINT fk_se_rounds_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_rounds_game FOREIGN KEY (game_id) REFERENCES se_games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_answers (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    round_id           INT UNSIGNED NOT NULL,
    registration_id    INT UNSIGNED NOT NULL,
    team_id            INT UNSIGNED NULL,
    role               ENUM('player','captain','suggestion') NOT NULL DEFAULT 'player',
    choice_index       TINYINT      NULL,
    answer_text        VARCHAR(160) NULL,
    received_at        DATETIME(3)  NOT NULL,
    client_elapsed_ms  INT UNSIGNED NULL,
    elapsed_ms         INT UNSIGNED NOT NULL DEFAULT 0,
    is_correct         TINYINT(1)   NULL,
    points             INT          NOT NULL DEFAULT 0,
    device_id          INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_answers_once (round_id, registration_id, role),
    KEY idx_se_answers_round_team (round_id, team_id, role),
    CONSTRAINT fk_se_answers_round FOREIGN KEY (round_id) REFERENCES se_rounds (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_buzzes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    round_id         INT UNSIGNED NOT NULL,
    attempt          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    team_id          INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    effective_ms     BIGINT UNSIGNED NOT NULL,
    received_at      DATETIME(3)  NOT NULL,
    judged           ENUM('pending','correct','wrong','ignored') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_buzz_team (round_id, attempt, team_id),
    KEY idx_se_buzz_order (round_id, attempt, effective_ms),
    CONSTRAINT fk_se_buzzes_round FOREIGN KEY (round_id) REFERENCES se_rounds (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_survey_responses (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    deck_item_id     INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    answer_text      VARCHAR(80)  NOT NULL,
    answer_norm      VARCHAR(80)  NOT NULL,
    feud_answer_id   INT UNSIGNED NULL,
    is_test          TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_survey_once (event_id, deck_item_id, registration_id),
    KEY idx_se_survey_item (event_id, deck_item_id),
    CONSTRAINT fk_se_survey_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_feud_answers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id      INT UNSIGNED NOT NULL,
    deck_item_id  INT UNSIGNED NOT NULL,
    label         VARCHAR(60)  NOT NULL,
    points        INT UNSIGNED NOT NULL,
    sort_order    TINYINT UNSIGNED NOT NULL,
    source        ENUM('survey','ai','manual') NOT NULL,
    approved      TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_se_feud_board (event_id, deck_item_id, sort_order),
    CONSTRAINT fk_se_feud_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_score_events (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    scope            ENUM('team','individual') NOT NULL,
    team_id          INT UNSIGNED NULL,
    registration_id  INT UNSIGNED NULL,
    game_id          INT UNSIGNED NULL,
    round_id         INT UNSIGNED NULL,
    kind             ENUM('auto','award','penalty','correction') NOT NULL,
    points           INT          NOT NULL,
    reason           VARCHAR(160) NULL,
    idempotency_key  VARCHAR(80)  NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    voided_at        DATETIME     NULL,
    voided_by        INT UNSIGNED NULL,
    void_reason      VARCHAR(160) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_score_idem (event_id, idempotency_key),
    KEY idx_se_score_team (event_id, scope, team_id, voided_at),
    KEY idx_se_score_reg (event_id, scope, registration_id, voided_at),
    KEY idx_se_score_round (round_id),
    CONSTRAINT fk_se_score_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### A.9 `20261027090000_se_post_event.sql` (Phase D)

```sql
-- 20261027090000_se_post_event.sql
-- Feedback survey and hand-off (Reach / Embrace) logs.

CREATE TABLE IF NOT EXISTS se_feedback (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    nps              TINYINT UNSIGNED NULL,
    favorite         VARCHAR(30)  NULL,
    one_word         VARCHAR(40)  NULL,
    comment          TEXT         NULL,
    wants_visit      TINYINT(1)   NOT NULL DEFAULT 0,
    future_optin     TINYINT(1)   NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_feedback (event_id, registration_id),
    CONSTRAINT fk_se_feedback_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_feedback_reg FOREIGN KEY (registration_id) REFERENCES se_registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_handoffs (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    reach_campaign_id  INT UNSIGNED NULL,
    summary_json       TEXT         NULL,
    created_by         INT UNSIGNED NOT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_handoffs_event (event_id, created_at),
    CONSTRAINT fk_se_handoffs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_handoff_items (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    handoff_id        INT UNSIGNED NOT NULL,
    event_id          INT UNSIGNED NOT NULL,
    registration_id   INT UNSIGNED NOT NULL,
    contact_id        INT UNSIGNED NOT NULL,
    destination       ENUM('reach','embrace','none') NOT NULL,
    outcome           ENUM('created','linked_existing','skipped_member','skipped_no_consent','skipped_no_show','skipped_opted_out','skipped_excluded','skipped_invalid_phone','already_handed_off') NOT NULL,
    target_table      VARCHAR(40)  NULL,
    target_id         INT UNSIGNED NULL,
    note              VARCHAR(160) NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    done_contact_key  INT UNSIGNED GENERATED ALWAYS AS (IF(outcome IN ('created','linked_existing'), contact_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_handoff_done (event_id, done_contact_key),
    KEY idx_se_handoff_items_handoff (handoff_id),
    CONSTRAINT fk_se_handoff_items_handoff FOREIGN KEY (handoff_id) REFERENCES se_handoffs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### A.10 `20261027090100_se_reach_campaign_type.sql` (Phase D)

```sql
-- 20261027090100_se_reach_campaign_type.sql
-- Reach campaign type used by the Special Events hand-off.

INSERT INTO reach_campaign_types (code, label, sort_order) VALUES
    ('Special_Event', 'Special Event', 50)
ON DUPLICATE KEY UPDATE code = code;
```

---

## Appendix B — Live snapshot examples

All snapshots share the envelope `{v, t, e, kind, data}` (§8.5.2). Field names are normative; add new fields only additively.

### B.1 `public.json` (no personal names, ever)

```json
{
  "v": 1842,
  "t": 1792863000123,
  "e": "k3m9q2x7p1za",
  "kind": "public",
  "data": {
    "event": { "phase": "live", "day": "2026-10-24", "checkin_open": true, "test_mode": false },
    "reg": { "state": "closed", "seats_left": null },
    "counts": { "checked_in": 97, "checked_in_today": 97, "joined_games": 88 },
    "teams": [
      { "id": 12, "label": "Black",   "name": "Joy Bringers", "hex": "#000000", "on": "#FFFFFF", "ring": true,  "n": 25, "score": 4200, "rank": 1 },
      { "id": 13, "label": "Crimson", "name": null,           "hex": "#D11920", "on": "#FFFFFF", "ring": false, "n": 24, "score": 3900, "rank": 2 },
      { "id": 14, "label": "Gold",    "name": "Psalmists",    "hex": "#F5C518", "on": "#0B0B0F", "ring": false, "n": 24, "score": 3100, "rank": 3 },
      { "id": 15, "label": "Navy",    "name": null,           "hex": "#1D356A", "on": "#FFFFFF", "ring": true,  "n": 24, "score": 2800, "rank": 4 }
    ],
    "program": {
      "time_mode": "approximate", "drift_min": 8,
      "now":  { "id": 55, "title": "Live Quiz — Round 1", "kind": "game", "eta_end": "2026-10-24T18:40:00+01:00" },
      "next": [ { "id": 56, "title": "The Great Debate", "kind": "debate", "eta_start": "~6:45 PM" } ]
    },
    "scene": { "key": "game", "since_ms": 1792862990000, "payload": null },
    "game": {
      "id": 7, "type": "live_quiz", "title": "Quiz · Round 1",
      "round": {
        "id": 901, "no": 3, "of": 10, "state": "open", "attempt": 1,
        "arm_ms": 1792862997000, "opens_ms": 1792863000000, "closes_ms": 1792863020000,
        "view": { "content_type": "mcq", "prompt": "Who was swallowed by a great fish?",
                  "choices": ["Jonah", "Elijah", "Peter", "Noah"], "media": null },
        "answered": 71, "eligible": 88,
        "reveal": null
      }
    },
    "announcement": null,
    "sfx": { "seq": 33, "cue": "arm" }
  }
}
```

After reveal, `round.reveal = {"correct_index": 0, "distribution": [64, 9, 7, 3], "ref": "Jonah 1:17", "explanation": "…"}`.

Per-type `round.view` (public parts only):
- **trivia**: as MCQ, plus `captains_submitted: [12, 14]` (team ids)
- **buzzer**: `{content_type, prompt | lead | emojis, buzz_open: true, locked_teams: [13], winner: {team_id} | null}`
- **who_am_i**: `{clues_shown: ["I was sold by my brothers", "…"], clue_index: 2, points_now: 400, winner: {team_id} | null}`
- **charades**: `{team_id: 12, running: true, words_done: 3, passes: 1, opens_ms, closes_ms}` (**never the phrase**)
- **feud**: `{question, slots: [{n: 1, revealed: true, label: "Animals", points: 38}, {n: 2, revealed: false}], strikes: 2, bank: 76, faceoff: [12, 14], control_team_id: 12, multiplier: 1, phase: "play"}`

### B.2 `room-<room_key>.json` (display names allowed)

```json
{
  "v": 1842, "t": 1792863000123, "e": "k3m9q2x7p1za", "kind": "room",
  "data": {
    "mvp": [
      { "rank": 1, "name": "Ada O.",   "team_id": 12, "points": 5400 },
      { "rank": 2, "name": "Tobi A.",  "team_id": 14, "points": 5120 },
      { "rank": 3, "name": "Kunle B.", "team_id": 13, "points": 4980 }
    ],
    "karaoke": {
      "on_stage": { "queue_no": 7, "name": "Tobi A.", "team_id": 14, "title": "Way Maker", "artist": "Sinach" },
      "up_next":  { "queue_no": 9, "name": "Ifeoma C.", "team_id": 12, "title": "Imela", "artist": "Nathaniel Bassey" },
      "later": [ { "queue_no": 11, "name": "Seyi D.", "team_id": 15 } ],
      "queue_len": 14
    },
    "presenter": { "name": "Ada O.", "team_id": 12, "player_no": 47 },
    "buzz_winner": { "name": "Seyi D.", "team_id": 15 },
    "captains": { "12": "Ada O.", "13": "Kunle B.", "14": "Tobi A.", "15": "Seyi D." }
  }
}
```

### B.3 `team-<team_key>.json`

```json
{
  "v": 1842, "t": 1792863000123, "e": "k3m9q2x7p1za", "kind": "team",
  "data": { "team_id": 12, "captain": { "name": "Ada O.", "registration_id_hash": "a1f3…" },
            "suggestions": { "round_id": 905, "counts": [7, 3, 1, 0] },
            "captain_choice": null, "members": 25, "joined": 23 }
}
```

`registration_id_hash` = HMAC of the id, which lets the captain's own device recognise "that's me" without exposing ids.

### B.4 `lobby-<lobby_key>.json`

```json
{
  "v": 1840, "t": 1792862980000, "e": "k3m9q2x7p1za", "kind": "lobby",
  "data": { "checked_in": 97, "teams": [ { "id": 12, "label": "Black", "name": "Joy Bringers", "hex": "#000000", "n": 25 } ],
            "arrivals": [ { "name": "Ada O.", "team_id": 12, "at_ms": 1792862977000 } ],
            "show_names": true, "starts_at": "2026-10-24T17:00:00+01:00" }
}
```

---

## Appendix C — Deck item payload schemas and samples

Payloads live in `se_deck_items.payload_json`. `scripture_ref`/`scripture_text` columns hold the reference and the **fetched** KJV text. Validation is by `se_schema_validate()`.

| Content type | Payload schema |
|---|---|
| `mcq` | `{prompt: string ≤ 160, choices: string[2..4] each ≤ 60, answer_index: int, explanation?: string ≤ 200}` |
| `open` | `{prompt: string ≤ 160, answer: string ≤ 60, accept: string[] ≤ 8}` |
| `charade` | `{phrase: string ≤ 40, category: "person"\|"story"\|"object"\|"place"\|"miracle"\|"parable", hint?: string ≤ 60}` |
| `clues` | `{clues: string[3..5] each ≤ 90, answer: string ≤ 40, accept: string[] ≤ 8}` |
| `emoji` | `{emojis: string ≤ 24 graphemes, answer: string ≤ 60, accept: string[], choices?: string[4], answer_index?: int}` |
| `verse` | `{lead: string, answer: string, choices?: string[4], answer_index?: int}` (computed from fetched text, §15.4) |
| `survey` | `{question: string ≤ 100}` |

### C.1 Samples (all references real; text is fetched at authoring time)

```json
[
  { "type": "mcq", "difficulty": "easy", "ref": "Jonah 1:17",
    "payload": { "prompt": "Who was swallowed by a great fish?", "choices": ["Jonah", "Elijah", "Peter", "Noah"], "answer_index": 0,
                 "explanation": "God prepared a great fish to swallow Jonah — three days and three nights." } },
  { "type": "mcq", "difficulty": "easy", "ref": "Genesis 7:12",
    "payload": { "prompt": "For how many days and nights did it rain during the flood?", "choices": ["7", "12", "40", "100"], "answer_index": 2 } },
  { "type": "mcq", "difficulty": "medium", "ref": "1 Samuel 1:20",
    "payload": { "prompt": "Who was the mother of the prophet Samuel?", "choices": ["Hannah", "Ruth", "Sarah", "Elizabeth"], "answer_index": 0 } },
  { "type": "mcq", "difficulty": "easy", "ref": "John 2:9",
    "payload": { "prompt": "At the wedding in Cana, what did Jesus turn water into?", "choices": ["Milk", "Wine", "Oil", "Honey"], "answer_index": 1 } },
  { "type": "mcq", "difficulty": "medium", "ref": "1 Kings 3:9",
    "payload": { "prompt": "Which king asked God for an understanding heart (wisdom)?", "choices": ["David", "Saul", "Solomon", "Hezekiah"], "answer_index": 2 } },
  { "type": "open", "difficulty": "easy", "ref": "Luke 22:61",
    "payload": { "prompt": "Which disciple denied Jesus three times?", "answer": "Peter", "accept": ["peter", "simon peter", "simon"] } },
  { "type": "charade", "difficulty": "easy", "ref": "1 Samuel 17",
    "payload": { "phrase": "David and Goliath", "category": "story" } },
  { "type": "charade", "difficulty": "medium", "ref": "Luke 19:4",
    "payload": { "phrase": "Zacchaeus climbing a tree", "category": "story", "hint": "A short man who wanted to see Jesus" } },
  { "type": "charade", "difficulty": "easy", "ref": "Daniel 6",
    "payload": { "phrase": "Daniel in the lions' den", "category": "story" } },
  { "type": "clues", "difficulty": "medium", "ref": "Genesis 37:3",
    "payload": { "clues": ["My father gave me a special coat", "My brothers sold me", "I ended up in prison in Egypt",
                           "I interpreted Pharaoh's dreams", "I became governor over Egypt"],
                 "answer": "Joseph", "accept": ["joseph"] } },
  { "type": "clues", "difficulty": "medium", "ref": "Esther 4:16",
    "payload": { "clues": ["I was raised by my cousin", "I became queen in Persia", "I asked my people to fast for three days",
                           "I said, \"if I perish, I perish\""], "answer": "Esther", "accept": ["esther", "queen esther"] } },
  { "type": "emoji", "difficulty": "easy", "ref": "Genesis 6:14",
    "payload": { "emojis": "🌧️🚢🦒🦁🌈", "answer": "Noah's Ark", "accept": ["noah", "noahs ark", "the flood"] } },
  { "type": "emoji", "difficulty": "medium", "ref": "Matthew 14:25",
    "payload": { "emojis": "🌊🚶‍♂️⛵😱", "answer": "Jesus walks on water", "accept": ["walking on water", "jesus walks on the sea"] } },
  { "type": "verse", "difficulty": "easy", "ref": "Philippians 4:13",
    "payload": { "lead": "I can do all things", "answer": "through Christ which strengtheneth me." } },
  { "type": "verse", "difficulty": "easy", "ref": "John 3:16",
    "payload": { "lead": "For God so loved the world, that he gave his only begotten Son,",
                 "answer": "that whosoever believeth in him should not perish, but have everlasting life." } },
  { "type": "survey", "payload": { "question": "Name something you'd find on Noah's Ark." } },
  { "type": "survey", "payload": { "question": "Name a gospel song everyone in Lagos knows the words to." } },
  { "type": "survey", "payload": { "question": "Name something people do at a Nigerian wedding reception." } }
]
```

The `verse` payloads above are shown for illustration. In the system, `lead`/`answer` are always computed from the fetched KJV text, never typed.

---

## Appendix D — AI prompt templates and response schemas

Each prompt file `includes/special_events/prompts/<task>.md` starts with front matter, and the response schema sits next to it in `<task>.schema.json` (Gemini `responseSchema`, OpenAPI subset: `type`, `properties`, `required`, `items`, `enum`, `minItems`, `maxItems`). The templates below are the v1 text; implementers may refine the wording, but MUST keep the rules and bump `version`.

### D.1 `palette_suggest.md`

```text
---
version: 1
temperature: 0.8
max_tokens: 2048
---
You are a senior brand designer for Envision, the creative department of a Lagos church (Household of David Lekki Centre).
Propose 4 distinct palettes for a special event web experience with a dark, cinematic "neon stage" look.
Rules:
- Keep the given PRIMARY and SECONDARY colours exactly as given in every palette.
- For each palette propose one ACCENT hex that harmonises with them and reads well on a near-black background.
- Optionally propose 4 TEAM colours that are clearly distinguishable from each other (also for colour-blind viewers) and from the primary.
- Give each palette a short evocative name and a one-sentence rationale. No more than 120 characters.
- Output JSON only, matching the schema. Hex format "#RRGGBB".
Event: {{title}} — {{tagline}}. Mood words: {{mood}}. PRIMARY {{primary}}. SECONDARY {{secondary}}.
```

```json
{ "type": "object", "required": ["palettes"], "properties": {
  "palettes": { "type": "array", "minItems": 4, "maxItems": 4, "items": { "type": "object",
    "required": ["name", "rationale", "accent", "mood_words"],
    "properties": {
      "name": { "type": "string" }, "rationale": { "type": "string" }, "accent": { "type": "string" },
      "background_hint": { "type": "string" },
      "team_suggestions": { "type": "array", "items": { "type": "string" }, "minItems": 4, "maxItems": 4 },
      "mood_words": { "type": "array", "items": { "type": "string" }, "minItems": 3, "maxItems": 3 } } } } } }
```

### D.2 `program_extract.md`

```text
---
version: 2
temperature: 0.1
max_tokens: 4096
---
You convert an event programme (typed text, a photo, a screenshot or a PDF) into structured run-of-show items.
Rules:
- Keep the original order. One item per programme line or block. Do not invent items.
- title: short, as written (fix obvious typos only).
- kind: one of {{kinds}}. Use "other" if unsure.
- start_time: "HH:MM" 24-hour only if a time is written for that item. Otherwise omit.
- end_time: "HH:MM" 24-hour if a written range has an end; for example, "3:30 PM–4:30 PM" becomes "15:30" and "16:30".
- duration_min: include a written or ranged duration; otherwise include it only if clearly implied by the next item's start time.
- host: the person leading the item, if written.
- day_index: 0-based index into the event days {{days}} (most programmes have one day → 0).
- confidence: 0.0–1.0 for how sure you are about this item's reading.
- warnings: list anything unreadable or ambiguous.
Output JSON only, matching the schema.
```

```json
{ "type": "object", "required": ["items", "warnings"], "properties": {
  "items": { "type": "array", "maxItems": 60, "items": { "type": "object", "required": ["title", "kind", "day_index", "confidence"],
    "properties": { "title": {"type": "string"}, "kind": {"type": "string"}, "day_index": {"type": "integer"},
      "start_time": {"type": "string"}, "end_time": {"type": "string"}, "duration_min": {"type": "integer"}, "host": {"type": "string"},
      "notes": {"type": "string"}, "confidence": {"type": "number"} } } },
  "warnings": { "type": "array", "items": { "type": "string" } } } }
```

### D.3 `songs_extract.md`

```text
---
version: 1
temperature: 0.1
max_tokens: 4096
---
Extract a karaoke song list from the input (text, photo, screenshot or PDF).
Return each song once with its title and artist exactly as written (fix only obvious OCR errors).
duration: "m:ss" only if written. Do not guess durations. Do not invent songs.
Output JSON only, matching the schema.
```

```json
{ "type": "object", "required": ["songs", "warnings"], "properties": {
  "songs": { "type": "array", "maxItems": 400, "items": { "type": "object", "required": ["title"],
    "properties": { "title": {"type": "string"}, "artist": {"type": "string"}, "duration": {"type": "string"} } } },
  "warnings": { "type": "array", "items": { "type": "string" } } } }
```

### D.4 `deck_generate.md` (shared rules + per content type)

```text
---
version: 1
temperature: 0.7
max_tokens: 8192
---
You write Bible game content for a joyful church games night in Lagos, Nigeria.
Audience: {{audience}}. Many guests are not regular churchgoers — be welcoming, clear and fun.
Rules:
- Every item MUST include a precise Bible reference "Book Chapter:Verse" (or a range) where the answer can be verified, using standard English book names.
- Never quote Bible text yourself — the system fetches the {{translation}} text from the reference.
- Be faithful to Scripture. Avoid denominational controversy, disputed interpretations, trick questions, politics, and anything that could embarrass a guest.
- Difficulty mix: {{difficulty_mix}}. Topic: {{topic}}.
- Do not repeat any of these recent items: {{avoid}}.
- Content type: {{content_type}}. Follow the type rules below exactly.
{{type_rules}}
Output JSON only, matching the schema.
```

Type rules inserted as `{{type_rules}}`:

| Type | Rules text |
|---|---|
| `mcq` | "Write a question ≤ 160 chars with exactly 4 short choices (≤ 60 chars), one correct, three plausible but clearly wrong distractors; give answer_index (0–3) and a one-line explanation." |
| `open` | "Write a question with a single short answer (≤ 4 words) and up to 5 accepted variants." |
| `charade` | "Write a phrase that can be acted silently (≤ 5 words): a person, story, miracle, parable, object or place, with a category and an optional hint." |
| `clues` | "Write 5 clues from hardest to easiest about one Bible person, place or object, then the answer and accepted variants." |
| `emoji` | "Represent a Bible story or person with 2–6 emojis; give the answer and accepted variants." |
| `verse` | "Choose well-known, encouraging verses. Return only references." |
| `survey` | "Write light 'Family Feud' survey questions answerable in 1–3 words, about church life, the Bible or Nigerian everyday life; no sensitive topics." |

```json
{ "type": "object", "required": ["items"], "properties": { "items": { "type": "array", "minItems": 1, "maxItems": 30,
  "items": { "type": "object", "required": ["ref", "difficulty", "payload"], "properties": {
    "ref": { "type": "string" }, "difficulty": { "type": "string", "enum": ["easy", "medium", "hard"] },
    "payload": { "type": "object", "properties": {
      "prompt": {"type": "string"}, "choices": {"type": "array", "items": {"type": "string"}}, "answer_index": {"type": "integer"},
      "explanation": {"type": "string"}, "answer": {"type": "string"}, "accept": {"type": "array", "items": {"type": "string"}},
      "phrase": {"type": "string"}, "category": {"type": "string"}, "hint": {"type": "string"},
      "clues": {"type": "array", "items": {"type": "string"}}, "emojis": {"type": "string"}, "question": {"type": "string"} } } } } } } }
```

(`survey` items may omit `ref`; the validator relaxes that rule for `survey` only.)

### D.5 `feud_cluster.md`

```text
---
version: 1
temperature: 0.2
max_tokens: 4096
---
You group anonymous survey answers for a "Family Feud" style board.
Question: {{question}}
Group answers that mean the same thing (synonyms, spelling variants, singular/plural). Give each group a short label (≤ 24 chars, Title Case).
Put jokes, gibberish or offensive answers in junk_ids. Every input id must appear exactly once (in one group or in junk_ids).
Output JSON only, matching the schema.
Answers (id: text): {{responses}}
```

```json
{ "type": "object", "required": ["clusters", "junk_ids"], "properties": {
  "clusters": { "type": "array", "items": { "type": "object", "required": ["label", "response_ids"],
    "properties": { "label": {"type": "string"}, "response_ids": {"type": "array", "items": {"type": "integer"}} } } },
  "junk_ids": { "type": "array", "items": { "type": "integer" } } } }
```

### D.6 `verses_suggest.md`

```text
---
version: 1
temperature: 0.6
max_tokens: 2048
---
Suggest {{count}} encouraging Bible verse references for welcome cards at a church event themed "{{theme}}".
Prefer well-known, uplifting verses suitable for first-time guests. Return references only (the system fetches the {{translation}} text).
For each, add a one-line reason and a warm personal prayer line template (≤ 160 chars) that MUST contain the placeholder {name}.
Output JSON only, matching the schema.
```

```json
{ "type": "object", "required": ["verses"], "properties": { "verses": { "type": "array", "items": { "type": "object",
  "required": ["ref", "why", "prayer_template"], "properties": { "ref": {"type": "string"}, "why": {"type": "string"},
  "prayer_template": {"type": "string"} } } } } }
```

### D.7 `copywrite.md`

```text
---
version: 2
temperature: 0.9
max_tokens: 4096
thinking_budget: 0
---
Write exactly {{count}} distinct options of {{purpose}} for {{title}} {{edition}} by {{organizer}}: {{facts}}.
Tone: joyful, warm, inclusive of guests who don't attend church yet, Nigerian-English friendly, no clichés, no hashtags unless asked.
Length limit for each option: {{limit}}. For SMS: plain GSM-7 characters only (no emoji, no curly quotes) and keep the literal token {{link}} if provided.
Output one complete JSON object only, matching this shape exactly: {"variants": ["…", "…", "…"]}.
```

```json
{ "type": "object", "required": ["variants"], "properties": { "variants": { "type": "array", "minItems": 3, "maxItems": 3, "items": { "type": "string", "minLength": 2, "maxLength": 1200 } } } }
```

### D.8 `report_summary.md`

```text
---
version: 1
temperature: 0.4
max_tokens: 2048
---
You write a short leadership report about a church special event from aggregate statistics only (no names).
Write a headline, three short paragraphs (what happened, who came, how it went), five highlight bullets and three recommendations for next time.
Be honest about weak numbers. Plain language. Output JSON only matching the schema.
Statistics: {{stats_json}}
```

```json
{ "type": "object", "required": ["headline", "paragraphs", "highlights", "recommendations"], "properties": {
  "headline": {"type": "string"}, "paragraphs": {"type": "array", "items": {"type": "string"}, "minItems": 3, "maxItems": 3},
  "highlights": {"type": "array", "items": {"type": "string"}, "maxItems": 5},
  "recommendations": {"type": "array", "items": {"type": "string"}, "maxItems": 3} } }
```

---

## Appendix E — Default per-event settings (`settings_json`)

`se_settings_normalize()` merges stored settings over this document. Unknown keys are dropped, types coerced and enums enforced. Capacity, overrides, windows, brand and fonts are **columns** on `se_events` and are not repeated here.

```json
{
  "registration": {
    "fields": { "gender": "required", "email": "optional", "how_heard": "optional", "karaoke_interest": true },
    "consent_mode": "required_followup",
    "consent_text": "I agree that HOD Lekki Centre may keep my details and contact me after this event (for example a thank-you and invitations to other events). I can opt out anytime.",
    "optin_text": "Keep me posted about future HOD Lekki events.",
    "ask_wants_visit_after_submit": true,
    "play_ahead_enabled": true,
    "link_on_demand_enabled": true,
    "companions_max": 0,
    "capacity_reviewed": false,
    "min_age_note": "For ages 16 and above. Younger guests are welcome with a parent or guardian."
  },
  "checkin": {
    "opens_minutes_before": 60,
    "self_checkin_enabled": true,
    "desk_enabled": true,
    "ask_missing_gender": true,
    "welcome_card": { "enabled": true, "signature": "{title} {edition} by {organizer}" }
  },
  "lobby": { "show_names": true },
  "teams": { "enabled": true, "count": 4, "naming_mode": "crew_entered", "balance": ["size", "gender", "membership"] },
  "karaoke": {
    "enabled": true, "prepick_enabled": true, "unique_songs": true, "list_published": false,
    "max_singers": null, "songs_per_person": 1, "release_holds_after_min": 30, "avg_song_min": 4
  },
  "games": {
    "enabled": true, "public_mvp_count": 5,
    "defaults": {
      "live_quiz": { "preroll_ms": 3000, "duration_ms": 20000, "grace_ms": 1500, "points_mode": "standard", "team_base": 1000, "streak_bonus": false, "auto_lock": true, "auto_score": true, "show_podium_every": 1 },
      "trivia":    { "preroll_ms": 3000, "duration_ms": 30000, "grace_ms": 1500, "points_correct": 300, "speed_bonus_max": 0, "use_suggestions_if_no_captain": true, "auto_lock": true, "auto_score": true },
      "buzzer":    { "preroll_ms": 2500, "window_ms": 15000, "reopen_window_ms": 10000, "points_correct": 300, "wrong_penalty": 0, "reopen_on_wrong": true, "who_can_buzz": "anyone", "buzzer_player_points": 0, "fairness_ms": 300 },
      "who_am_i":  { "points_by_clue": [500, 400, 300, 200, 100], "lockout_until_next_clue": true, "fairness_ms": 300 },
      "charades":  { "turn_ms": 60000, "points_per_word": 200, "max_passes": 2, "presenter_points": 0, "turns_per_team": 2 },
      "feud":      { "min_responses": 25, "board_size_min": 5, "board_size_max": 8, "round_multipliers": [1, 1, 2, 3], "matchups": "round_robin", "steal_enabled": true }
    }
  },
  "feud": { "survey_closes_at": null },
  "program": { "public_time_mode": "approximate", "published": false },
  "portal": {
    "intro_line": null,
    "show_countdown": true,
    "hero_video_enabled": true,
    "chapters_from_featured": true,
    "faq": [
      { "q": "Is it free?", "a": "Yes! Just register so we can save you a seat." },
      { "q": "What should I wear?", "a": "Come comfortable and colourful — it's a night of joy." },
      { "q": "Do I have to sing?", "a": "Not at all. Karaoke is optional — cheering counts too." },
      { "q": "Can I bring a friend?", "a": "Please do! Share your invite link so they can register too." },
      { "q": "What time should I arrive?", "a": "Doors open an hour early. Check-in is quick: scan the QR at the entrance." },
      { "q": "Will there be food?", "a": "We'll share details closer to the day." }
    ]
  },
  "recap": { "public": true },
  "share_cards": { "im_going": true, "welcome": true, "team": true, "my_night": true },
  "messages": {
    "max_lateness_min": 90,
    "reminder_1": { "enabled": true, "at": "18:00", "template": "{{first_name}}, {{event_title}} is tomorrow, {{time}} at {{venue}}! Check in at the entrance. Your link: {{link}}" },
    "reminder_2": { "enabled": true, "minutes_before": 120, "template": "{{first_name}}, {{event_title}} starts at {{time}}! Scan the QR at the entrance to check in & meet your team. {{link}}" },
    "thank_you":  { "enabled": true, "at": "09:00", "template": "Thank you for coming to {{event_title}}, {{first_name}}! Get your My Night card & tell us how it went (1 min): {{link}}" },
    "waitlist_promotion": { "template": "Good news {{first_name}}! A seat opened at {{event_title}} on {{date}}. You're in! Details: {{link}}" },
    "link_on_demand":     { "template": "{{first_name}}, here is your {{event_title}} link: {{link}}" }
  },
  "stage": { "volume": 0.8, "show_score_strip": true },
  "realtime": { "driver": "poll", "min_poll_ms": 1000 },
  "display": { "name_format": "first_initial" },
  "test_mode": false
}
```

**SMS length check** (computed with the same GSM-7 rules as `sms_segments()`). With `{{event_title}}` = "Chara 2026", `{{time}}` = "5:00 PM", `{{venue}}` = 18 chars, `{{first_name}}` = 9 chars and a 53-char link (`https://hodlc.lpc.cm/e/chara/me/` + 22-char token), the defaults are: reminder_1 ≈ 157, reminder_2 ≈ 153, thank_you ≈ 155, waitlist ≈ 138, link ≈ 95 characters, so each is **1 page**. A longer venue or first name can tip a message into 2 pages; the Studio shows the exact per-recipient estimate before a run, and Producers can shorten the venue text.

---

## Appendix F — Copy deck (microcopy)

Tone: joyful, warm, short, inclusive of guests; Nigerian-English friendly; no church jargon without a hint. These strings are the defaults; the Studio overrides portal headline/tagline (Details) and the FAQ (Details → Good to know, `portal_settings_save`) per event. The `portal.faq` default is only applied when the stored document has no `faq` key at all — an event that saves `[]` keeps an empty list and shows no FAQ section.

| Key | Text |
|---|---|
| `hero.kicker` | `{TITLE} {EDITION} · BY {ORGANIZER}` |
| `hero.cta.register` | Register |
| `hero.cta.waitlist` | Join the waitlist |
| `hero.cta.full` | We're full online — walk-ins welcome while space lasts |
| `hero.cta.checkin` | Check in |
| `hero.cta.play` | Join the games |
| `hero.cta.recap` | Relive the night |
| `hero.seats_left` | {n} seats left |
| `intro.meaning` | *Chara* (χαρά) means joy. |
| `reg.phone.label` | Your phone number |
| `reg.phone.hint` | We'll use it to welcome you at the door. |
| `reg.phone.invalid` | Please enter a mobile number, e.g. 0803 123 4567. |
| `reg.member.title` | Hi {name} 👋 — is this you? |
| `reg.member.yes` | Yes, register me |
| `reg.member.no` | Not me |
| `reg.returning.title` | Welcome back, {first}! |
| `reg.gender.label` | You are… |
| `reg.howheard.label` | How did you hear about {TITLE}? |
| `reg.karaoke.label` | I'd love to sing at karaoke 🎤 |
| `reg.consent.required` | Please tick the box to continue. |
| `reg.success.title` | You're in, {first}! 🎉 |
| `reg.success.save_link` | Save your link — it's how you manage your seat. |
| `reg.success.card` | Make your "I'm going" card |
| `reg.success.visit` | One more thing: would you like to visit HOD Lekki on a Sunday? |
| `reg.waitlist.title` | You're #{n} on the waitlist |
| `reg.waitlist.body` | We'll text you the moment a seat opens. |
| `reg.already.title` | You're already in, {name} 🎉 |
| `reg.already.lost` | Lost your link? Text it to me |
| `checkin.splash` | Welcome to {TITLE}! Let's check you in. |
| `checkin.confirm` | Is this you? |
| `checkin.reveal` | You're on |
| `checkin.not_open` | Check-in opens at {time}. See you soon! |
| `checkin.closed` | Check-in has closed — please see the desk. |
| `checkin.walkin_full` | We're at capacity right now — please see the desk. |
| `checkin.elsewhere` | You're already checked in on another phone. To play here, ask the desk for a transfer code. |
| `card.photo.add` | Add your photo (optional) |
| `card.photo.private` | Your photo stays on your phone. |
| `play.waiting` | Hang tight — the next game starts soon. |
| `play.locked_in` | Locked in ✓ |
| `play.too_late` | Time's up! Get ready for the next one. |
| `play.captain` | You're the captain — submit for your team. |
| `play.captain_chose` | Captain chose {choice} |
| `play.buzz` | BUZZ |
| `play.buzzed_by_mate` | {name} buzzed for your team! |
| `play.presenter` | You're acting! Only you can see this. |
| `karaoke.coming_soon` | The song list is coming soon 🎤 |
| `karaoke.taken` | Taken |
| `karaoke.up_next` | You're up next 🎤 — head to the stage! |
| `karaoke.ahead` | {n} singers before you |
| `net.reconnecting` | Reconnecting… |
| `error.generic` | Something went wrong. Please try again. |

**Privacy notice skeleton** (`defaults/privacy_notice.md`): *Who we are* · *What we collect* (phone, name, gender, optional email, your answers, check-in and game activity) · *Why* (to run {event}: registration, check-in, reminders, games; and — with your consent — to follow up with you and invite you to future events) · *Who sees it* (Envision; with consent, our follow-up teams Reach and Embrace; our SMS provider delivers our texts) · *How long* (see §19.8) · *Your choices* (opt out on your manage page, ask us to correct or delete your data: {privacy_contact_email}) · *Photos on your share card stay on your phone.*

---

## Appendix G — Chara starter content

**Theme verses (joy) — references only; the KJV text is fetched by the Studio:** Nehemiah 8:10 · Psalm 16:11 · Psalm 30:5 · Psalm 95:1 · Psalm 98:4 · Psalm 100:1–2 · Psalm 118:24 · Psalm 126:2 · Psalm 126:3 · Proverbs 17:22 · Ecclesiastes 3:4 · Isaiah 55:12 · Zephaniah 3:17 · Luke 2:10 · John 15:11 · John 16:24 · Romans 15:13 · Galatians 5:22–23 · Philippians 4:4 · James 1:2 · 1 Peter 1:8 · Psalm 5:11.

**Prayer line examples** (`{name}` placeholder):
- "{name}, may the joy of the Lord be your strength tonight and always."
- "{name}, may your heart be full of songs and your home full of laughter."
- "{name}, may God fill you with all joy and peace as you trust in Him."

**Card signature:** `Chara 2026 by Envision`.

**Programme skeleton** (illustrative, to be replaced by the real programme from Tommy/Chidera, M12): Doors & check-in (downstairs) → Welcome & opening prayer → Team naming huddle → Live Quiz (round 1) → Bible Charades → The Great Debate (topic; Pastor Billy wraps up) → 15-min buffer → Bible Family Feud → Buzzer round → Awards & finale → **Karaoke** (last, M7).

**Suggested games lineup** (≈ 75 minutes of games): Live Quiz 10 Qs (12 min) · Charades 2 turns × 4 teams (12 min) · Trivia 8 Qs (12 min) · Buzzer mix (Finish the Verse + Emoji Bible) 10 prompts (12 min) · Family Feud 4 rounds (20 min) · finale (5 min).

**Feud survey questions** (pick 3–5): "Name something you'd find on Noah's Ark." · "Name a gospel song everyone in Lagos knows the words to." · "Name something people do at a Nigerian wedding reception." · "Name a Bible character known for being strong." · "Name something you bring to a church picnic."

**Placeholder team colours** (until Envision chooses, O3): `#000000` Black · `#D11920` Crimson · `#F5C518` Gold · `#1D356A` Navy.

**Share kit source codes** for the flyer campaign: `wa`, `ig`, `tt`, `flyer` (printed QR), `pulpit` (Sunday announcement slide QR).

---

## Appendix H — Checklists

### H.1 Publish readiness (Studio → Overview)

| Item | Blocking? |
|---|---|
| Title, slug (valid & available), at least one day with valid times | **Yes** |
| Venue name | **Yes** |
| Brand primary + secondary hex | **Yes** (defaults count) |
| Registration settings reviewed (capacity number or "unlimited" explicitly chosen) | **Yes** |
| Consent text + privacy contact email set | **Yes** |
| At least one Producer in the crew | **Yes** |
| Hero image (or video + poster) with alt text | No (warning) |
| OG link card rendered | No (warning) |
| Programme has ≥ 1 public item | No (warning) |
| Teams configured (if teams enabled) | Required before check-in opens |
| Song list published (if pre-pick enabled) | No (warning) |
| Message templates previewed and test-sent (if enabled) | No (warning) |

### H.2 Smoke tests after each phase deploy

- **Portal**: loads on phone; hero animates (and is static with reduced motion); register as new/member/returning; waitlist when full (lower capacity to test); self-cancel promotes; seats-left visible per mode; manage link works on a second device; "I'm going" card renders with and without a photo; calendar file opens.
- **Check-in**: outside the window shows the countdown; inside: registered, walk-in, member-without-registration, missing gender, already checked in (same/other phone), desk check-in, transfer code, undo; lobby animates; team counts balanced.
- **Live**: stage start overlay, scene changes, announcement, sound cue; snapshot age < 3 s; host shortcuts work; `STALE_VERSION` handled when two consoles act.
- **Games**: one round of each game type end-to-end in test mode; void a round; award/penalty + undo; leaderboard and MVP correct; reset rehearsal clears everything.
- **Karaoke**: pre-pick (hold), unique-song conflict, release after the release time, queue numbering at check-in, DJ statuses, "up next" alert.
- **Messages**: preview, estimate, test send, scheduled run created once (run the cron twice → still one run).
- **After**: thank-you link opens recap + feedback; insights numbers match the database; PDF has no PII; hand-off preview → push → re-run creates nothing new.

### H.3 Dress rehearsal (T−7 days)

Test mode on · 20+ phones on the venue Wi-Fi · register 5 new, check in 20 (self + desk + walk-in) · lobby on the TV downstairs · stage on the projector with sound · run every game type once · karaoke 3 singers · finale · measure snapshot freshness and errors · **Reset rehearsal** · write the fix list.

### H.4 Event day (T−2 h)

Test mode **off** · stage started (sound test) · lobby started · host and DJ consoles open · desk phones logged in and charged · posters up (2–3 downstairs) · snapshot age < 3 s · SMS worker healthy · attendee export on the desk laptop · scene `standby`.

### H.5 After the event

Scene `recap` · export attendees · check `thank_you` scheduled · review feedback after 48 h · generate the PDF report · hand-off wizard with the Follow-up liaison · notify leadership · archive after ≥ 7 days.

---

*End of guide. Keep this document current: any pull request that changes behaviour described here MUST update the relevant section in the same PR.*
