# How to use Assimilation

## 1. Overview

Assimilation is how we go after the people who have quietly stopped coming.
It watches attendance, tells us who has drifted, hands each person to a
volunteer who will call them, and marks it the moment they walk back in.

- **Find people** — build a rule ("fewer than 3 services in 2 months"), see
  exactly who it catches, and send someone after them.
- **Follow-up** — every open case: who is calling, what has happened, and
  who is overdue.
- **Team** — the volunteers who make the calls, and how each one is doing.
- **Analytics** — how the ministry is doing, plus the monthly PDF report.
- **How to Use** — this guide, and the volunteer guide.

**Who can open it:** Super Admins, the Resident and Associate Pastors, any
active **Director or HOD of any department**, and anyone added to the
**Assimilation team**. Nobody else — the sidebar link is hidden and every
API action checks again on the server.

**Managers** (Super Admins, pastors, Directors and HODs) can search the
congregation, save watchlists, add and remove volunteers, assign and
reassign, change settings and see analytics. **Volunteers** see only the
people assigned to them, plus the unclaimed pool when it is switched on.

We never say "reclaimed". People are not property. They come **home**.

## 2. How we count attendance

A person counts as present on a day if they appear in either the **check-in**
record (QR, walk-in, the check-in monitor) or the **events attendance**
roster for that day. Both are combined and **de-duplicated per person per
calendar day**, so checking in twice on one Sunday counts once, and being in
both tables for the same service counts once.

This is one shared definition used by the rule builder, the analytics, the
returned-home detection and the nightly job — so all four always agree. It
also means a check-in taken by the QR or events modules feeds Assimilation
automatically: no extra step, and nothing in those modules had to change.

Attendance is matched by the member's profile, so a guest who checked in
without a profile is not counted as a member who has drifted.

## 3. Finding people who have drifted

On **Find people**, set the rule at the top:

> Fewer than **[3]** services in the past **[2]** **[months]**

Use days, weeks or months. The three shortcuts (`<1 in a month`,
`<3 in 2 months`, `<5 in 3 months`) fill it in for you.

Then narrow it with the filters:

- **Spiritual status** — tap any combination of 1st/2nd/3rd Timer, Visitor,
  Non Member, Member, Worker, Pastor.
- **Department**, **region**, **gender**, **age group**.
- **Last attended before / after** a date.
- **Assigned or not** — hide the people somebody is already calling.
- **Only people who have attended before** — on by default. Turn it off to
  include people with no attendance record at all.
- **Search** by name or phone.

Each result card shows their last attended date and how long ago in plain
words ("6 weeks ago"), the services in the window against the same-length
window before it (with an arrow for the direction of travel), a twelve-month
sparkline, their departments, and whether anyone is already on them.

People whose profile is marked **Relocated** are left out — they told us
they were going.

**Send someone after them:** tick the people you want, then in the bar that
appears choose a volunteer and click **Send someone after them**. Choose
*Leave unclaimed* to put them in the pool instead, for a volunteer to pick up
themselves. Anyone who already has an open follow-up is skipped, and the
message tells you how many.

**Export CSV** downloads everyone the rule catches, safe to open in Excel.

## 4. Watchlists

A watchlist is a saved rule with a name, for example *Workers — fewer than 3
in 2 months*. The easiest way to build one is the **Build a List** button on
Find people: a friendly step-by-step wizard that asks how long people have
been away, who to watch, any fine-tuning (department, region, gender, age),
and who should go after them — then shows a live count of who matches and
lets you name and launch it. Power users can still open **Advanced filters**,
build the rule by hand, and click **Save as watchlist**.

### Open watchlists (pushed to every volunteer)

On the wizard's *Who goes after them?* step you choose between two kinds:

- **Open to every volunteer** — everyone the list covers is pushed straight
  to the unclaimed pool on the public volunteer page, immediately at launch
  and again each morning for people who drift in later. New people also land
  there automatically between morning runs whenever a manager opens
  Assimilation. **Whoever picks someone first takes them**: the person moves
  to that volunteer's own list and leaves the shared one, and any volunteer
  can leave notes on them. Launching an open list switches on team self-pick
  in settings if it was off, so volunteers can actually claim people.
- **Managers assign** — the classic private list. Nobody is pushed anywhere;
  you hand people to volunteers yourself from Find people.

Each morning a job re-runs every active watchlist and sends **one in-app
digest per watchlist**, naming only the people who are **newly** drifted into
it — "4 people newly drifted: …". Managed lists tell the managers; open lists
tell the managers *and* every volunteer. Nobody is announced twice, however
many times the job runs. Someone who starts attending again drops off the
list, and if they drift a second time they are announced again. Two gentle
guards apply to open lists: anyone already being followed up is never
double-pushed, and anyone a past follow-up closed as *not interested*,
*relocated*, *attends elsewhere* or *unreachable* is never pushed again
automatically (a manager can still assign them by hand). Someone who came
home and later drifts again **is** pushed again — that is the point.

Open **Watchlists** to see each list's live count — matched people, how many
are waiting in the pool, how many are being called — to open a list back in
Find people, pause its digest, or delete it. Deleting a watchlist keeps every
follow-up that came from it.

## 5. Follow-ups

Assigning someone opens a **follow-up** for them. One person can only have
one open follow-up at a time, so two leaders cannot send two volunteers after
the same person.

The stages are plain words:

**To call → Reached → Promised to come → Returned home**

plus four ways a follow-up can close with a reason: *Could not reach them*,
*Not interested for now*, *Has relocated*, *Attends another church*.

The stage is worked out from what volunteers log, not set by hand. Logging
*We spoke* makes it **Reached**; *Promised to come* makes it **Promised**;
logging *Not interested*, *Asked for no contact*, *Has relocated* or
*Attends elsewhere* closes it with that reason. *Could not reach them* is a
deliberate choice — use **Close this follow-up** in the drawer when the
attempts have run out.

Tap any card to open the drawer:

- **Call essentials** — tap-to-call and WhatsApp, departments, region, last
  attended, who is on it, next touch, and a badge if the same person is also
  an open Reach lead or Embrace first timer (read-only, so nobody gets two
  calls in one day).
- **Attendance over the last year** — a monthly bar chart and the last ten
  days they were in the house.
- **Log a follow-up** — channel, outcome, notes, **Clean up with AI**, prayer
  points and the next touch date.
- **What has happened** — the full timeline. Where AI tidied the notes, the
  volunteer's own words are kept underneath.
- **Assignment history** — every assign, reassign, claim and unassign.

**Overdue** means somebody was assigned more than the overdue window ago
(7 days by default) and nothing has been logged since. The red banner at the
top of the tab filters to just those, and they also drive the sidebar badge.

## 6. Returned home

**Returned home** is detected automatically. When a person in an open
follow-up is recorded present on any day **after** the first time a volunteer
reached out, the follow-up is marked Returned home, dated to the day they
came, and the volunteer and the managers are notified.

It runs when the module is opened, when a follow-up is saved, and in the
nightly job — so a Sunday check-in is noticed on Sunday. Somebody we had
already closed as unreachable is still caught if they come back within three
months.

You can also mark it by hand with **They came back to church** in the drawer,
for the times someone walks in and nobody scanned them.

## 7. The team

Anyone in the congregation can be an Assimilation volunteer — **not only
workers**. Open **Team → Add volunteers** and search by name or phone. The
picker shows each person's photo, phone, spiritual status, departments and
when they were last in church, so you know who you are asking.

Each volunteer card shows their open follow-ups, contacts this month, how
many people they have brought home, and when they were last active.

**Removing** a volunteer deactivates them — their history and their notes
stay. If they still hold open follow-ups, the message says so; reassign
those from the Follow-up tab.

## 8. The volunteer page

Volunteers do not need a login. Share **`/assimilation.php`** with them (the
**Volunteer page** button at the top opens it).

They enter the phone number the church has for them, confirm it is them, and
see **Your people to call** — sorted by overdue first, then due today, then
nobody-has-called-yet. Each card has tap-to-call, WhatsApp, their attendance
trend, when they were last in church, and the last note.

Tapping a card opens the logging sheet: channel chips, outcome chips, notes
with a **microphone** and **Clean up with AI**, prayer points, and quick
next-touch pills (Tomorrow, In 3 days, Next Sunday, In a week).

**Only these things are shown on that page**: name as "First L.", phone, when
they were last in church, their attendance trend, their departments, their
spiritual status and previous follow-up notes. No address, no date of birth,
no finance, nothing else. Only active team members get in, the phone is
re-checked on every action, and a volunteer can only read and write their own
follow-ups.

The **How to follow up with love** guide sits at the bottom of that page, and
is also shown in this tab. Managers edit it under the settings gear.

## 9. AI help

Both features use Gemini and both degrade gracefully — with no API key
configured, they say so and nothing else breaks.

- **Clean up notes** rewrites what the volunteer wrote into a few factual,
  respectful bullet points: what was discussed, the reason for absence if it
  was shared, prayer points, commitments and the next step. It is told to
  keep names and dates exact, invent nothing, and never add judgement about
  the person. The volunteer reviews it and chooses to keep it; **both the raw
  and the tidied version are stored**, and the volunteer's own words remain
  the record.
- **Suggest a gentle opening line** writes one warm opening sentence from the
  first name, how long since they were last in church, the stage of the
  follow-up and the last note. Nothing else about the person is sent.

## 10. Settings and the report

The gear button (managers only) holds:

- **Overdue** — how many days before an assigned person is flagged.
- **The unclaimed pool** — whether volunteers may claim people themselves.
- **Default rule** for a new watchlist.
- **Volunteer guide** — the "How to follow up with love" text, in Markdown.
  **Restore default** puts the built-in version back.

**Analytics** covers any date range: people on a watchlist, followed up,
returned home, the return rate, the median days to a first call, the journey
funnel, returned-home by month, breakdowns by spiritual status and
department, the volunteer leaderboard and every watchlist.

**Monthly PDF report** produces a two-page PDF with the KPIs, the funnel, the
returned-home trend, who came home (first names only) and the volunteer who
called them, the breakdowns, volunteer activity, the watchlist summary,
prayer points from the calls, and a short pastoral summary of the month.

## 11. For developers

- Module: `modules/assimilation/index.php` · APIs: `api/assimilation_api.php`
  (session-guarded) and `api/assimilation_public_api.php` (phone-guarded).
- Public page: `assimilation.php`. Shared code: `includes/assimilation_helpers.php`.
  PDF: `includes/assimilation_report_pdf.php`. Default guide:
  `includes/assimilation_volunteer_guide.md`.
- The attendance union lives in one function, `assim_attendance_union_sql()`.
  Use it for anything that asks "were they in the house?" — never query
  `checkins` or `attendance` directly from Assimilation code.
- Nightly job: `cron/assimilation_watchlists.php` (crontab line in the README).
- Any change under `modules/assimilation/`, `api/assimilation_*` or
  `assimilation.php` must update this file in the same PR — CI enforces it.

## Context pills on the volunteer page

Every person on a volunteer's call list carries two small pills so the
volunteer knows who they are calling before they dial:

- **Last in church** — the date of their most recent attendance (or "No
  attendance on record").
- **Reached out N×** — how many times anyone has contacted them across every
  follow-up round, when the last contact was, who made it and the outcome.
  If nobody has called before, the pill says **Nobody has reached out yet**.

People assigned to you show their **full name and phone number**, so you can
be sure who you are calling. People in the unassigned pool show only first
name and last initial until you claim them. Your profile photo appears next
to "Signed in as" when you have one on your profile.
