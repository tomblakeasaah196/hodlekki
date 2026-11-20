# Departments — How to Use

The Departments module keeps one roster per ministry year, and shows every
person in two lists: **Primary members** (the core team) and **Secondary
members** (people who serve occasionally). It is where leadership seats —
Pastor in Charge, Director, HOD — are appointed and changed.

Everything on screen belongs to **one ministry year**. The switcher in the
top-right of the module chooses which one; the year marked *live* is the one
that can be edited.

---

## 1. The two member lists

| List | Who belongs there |
| ---- | ----------------- |
| **Primary members** | The core commitment of the department — the people who are counted on every service. |
| **Secondary members** | The bench: people who serve occasionally, or who are being eased in. |

* **Move somebody between the lists** — press the **Secondary / Primary** chip on
  their row. One click, applied immediately.
* **Add somebody** — **Add member** → search for the name → choose
  *Primary* or *Secondary* → **Add to Department**.
  People already on this year's roster are hidden from the search, so the list
  never gets muddled.
* **Sub-Unit Head** is a role, not a list: a sub-unit head sits in whichever
  list they belong to and is marked with a red bullet on the downloads.

## 2. Removing somebody (and adding them back)

**Remove** takes the person off the roster straight away:

* they disappear from the department screen, the counters, and every download;
* the record itself is kept in the database (audit trail, end date stamped);
* they are notified through the notification bell;
* **adding them back later is just Add member again** — the same record is
  brought back to life, nothing is duplicated.

Nobody who has been removed ever appears on the UI again. The same is true the
moment a leader is replaced.

## 3. Changing an HOD, Director or Pastor in Charge mid-term

Open a department and press **Change** on the seat you want to move.

* Pick the new person (or tick *Relieve the current leader and leave the seat
  vacant*).
* The outgoing leader is relieved immediately: the seat changes, they are taken
  off the department roster, they are notified, and — if they held a Director /
  HOD seat — their access to this module is removed on their next page load.
* If you want the outgoing leader to stay in the department, simply add them
  back as a Primary or Secondary member: **Add member** does it in seconds.

Saving *Edit settings* does the same thing: selecting a different person for a
seat replaces the sitting leader. A seat is never left half-changed, and the
transition is reported in the confirmation message.

## 4. Ministry years and the rollover worksheet

A ministry year has a label and a period (e.g. `2026`, 1 Jan 2026 – 31 Dec
2026). Rosters, downloads and reports are all tied to one.

When a new year begins, use **Start new year** (Super Admin or Resident
Pastor). It opens a **worksheet**, not a button:

1. Set the **label** and the **period** for the new year.
2. **Carry everyone over**, **Members only**, or **Start empty** — the presets
   fill the worksheet; then reshuffle by hand.
3. For each department (and every sub-unit) you can
   * appoint the three leadership seats,
   * add people with the searchable person box and **Add**,
   * flip a member between Primary and Secondary with the chip,
   * take somebody out with the × on their chip,
   * or **Clear** the whole department.
4. **Go live.** See the running summary at the bottom for how many
   members are placed and how many seats are filled.

Going live:

* the new year becomes the **live** year;
* the previous year is **archived** — visible in the switcher, downloadable,
  but read-only;
* leadership seats are *not* carried over by the *Members only* preset: the new
  year starts with the seats empty so they are appointed deliberately;
* carrying everyone over keeps the seats as they are — every Worker, Sub-Unit
  Head and Deputy HOD keeps their seat, and each person gets one message
  naming the seats they hold in the new year.

Past years are never edited in place, so last year's sheet always downloads
exactly as it stood.

## 5. Downloads

**Download** offers three things, always for the ministry year currently on
screen:

| Format | What you get |
| ------ | ------------ |
| **A4 image (JPEG)** | One A4 page at 300 dpi: church logo, **Ministry Year** heading (e.g. `Ministry Year 2026`, without the date range), then every department with its Pastor in Charge / Director / HOD and its Primary and Secondary members. |
| **Excel workbook (.xlsx)** | A *Summary* sheet (every department with both counts and leadership), one sheet per department (leaders, then Primary, then Secondary, with phone and email), and an **All Workers** sheet. |
| **CSV** | One flat table of every worker in every department. |

The downloads identify the roster by its Ministry Year label only; the date
range is not printed. The A4 image always fits on a single page. It is drawn
with the browser's canvas at A4 proportions, and the fitter picks the largest
type size that still fits; for a very large roster the sheet switches to a
denser arrangement rather than spilling onto a second page. The download tells
you which layout was used.

---

## For administrators and developers

* **Migration.** `db/migrations/20261111090000_department_ministry_years.sql`
  adds the `ministry_years` table and the `membership_type`, `ministry_year_id`
  and `roster_status` columns on `user_departments`, backfills everything into a
  bootstrap year named after the current calendar year, and drops the old
  `UNIQUE(user_id, department_id)` key. Run `php db/migrate.php` on the server
  (the deploy does this automatically). Until it has run, the module shows a
  clear "one migration is outstanding" notice instead of failing.
* **`is_active` keeps its meaning** for the rest of the platform: *serving now,
  in the live year*. Archiving a year clears it on that year's rows, which is
  exactly what keeps navigation clearance elsewhere honest.
* **`includes/department_helpers.php`** is the single data layer — the JSON
  endpoint (`api/department_api.php`), the Excel/CSV export
  (`api/department_export_excel.php`) and the A4 renderer
  (`assets/js/department_export.js`, fed by `fetch_export_dataset`) all read
  from it, so the picture and the spreadsheet can never disagree.
* **Access.** Rank roles see the module. Anybody else needs a *live* leadership
  seat (`Director`, `HOD`, `Sub-Unit_Head`) in the current year — which is the
  flag the module clears when somebody is relieved of duty.
* **A seat is changed, never vacated by accident.** `set_leader` (and the
  leadership block of `save_department`) is the only way a seat moves, and old
  and new are written in one transaction.

## Who can change what (since November 2026)

Access follows Roles & permissions: pastors manage every department; a Pastor in charge,
Director or HOD manages their own department and its sub-units; ministry years and the
rollover need **Departments: Manage**. Putting someone in a seat follows the rank rule:
you may only appoint, change or remove seats ranked **below** your own in that department
(Super Admins and holders of Roles & permissions: Manage excepted). An HOD therefore no
longer appoints the Director. A Deputy HOD (up to two per department) appoints nobody.
Appointing a leader no longer gives them a church-wide role: their access comes from the
seat, and ends with it.

## Seats end with the year (since December 2026)

Every seat runs until its ministry year ends, unless an earlier end date is set.
Fourteen days before, the holder and their pastor are told. The pastor's message
opens **Renew seats**: tick the seats that continue and press **Renew seats** — each
runs to the end of the next ministry year (or twelve months on if that year is not
set up yet), and the holder is told. The Resident Pastor and Super Admins get one
summary 14 and 3 days before the year ends, linking to the rollover. A seat that is
not renewed or rolled over stops giving access the day after it ends.

The seats you can give with *Add person* (Worker, Member, Sub-Unit Head, Deputy HOD …)
and their names come from **Roles & permissions → Seats**. A department may use fewer
seat types or allow fewer holders there; *Add person* refuses a seat that is full or
not used ("This department already has its 2 Deputy HOD seats").

## Attendance: who is here, who is absent (since November 2026)

The **Attendance** tab takes a roll for **one event** — a workers' meeting — and
shows every worker on the live ministry year's rosters as present or absent, by
department.

1. Choose the **event**. It opens on today's workers' meeting when there is one. Create it in Events with the category **Workers' Meeting**: that makes it workers-only.
2. Read the four numbers (Workers, Present, Absent, Turnout) and switch the view:
   **By department** (a card per department, absent first), **Absent** (one list
   of everyone still missing, with their departments) or **Present** (with the
   time they came in).
3. **Search** a name or phone number and press **Clock in**; if only one absent
   worker matches, **Enter** clocks them in. **Move back** undoes a clock-in made
   by mistake (it asks first).
4. Leave **Live** on and the page refreshes by itself every few seconds, so
   people who scan in at the door, or are clocked in on another phone, move
   from Absent to Present without anyone pressing anything. **Refresh** does it now.
5. **Export absent** downloads the absent list as a spreadsheet (CSV), department
   by department, with phone numbers — for calls and follow-up. It needs
   **Departments: Export**.

Rules worth knowing:

* **Who is expected** is everybody on an Active roster row in the live year —
  Primary or Secondary, leaders included. Removed people, people marked
  *Relocated* and last year's rosters do not count. Someone on two rosters shows
  under both but is counted once.
* **It is the same record as Events.** Clocking in writes the same attendance
  row the Events screen writes, so Events, its analytics and the church-wide
  attendance numbers all agree. Someone who checked in at the door QR is shown
  as present (marked *Door*); that check-in is theirs and cannot be removed here.
* **Only workers.** Someone who is not on a department roster cannot be clocked
  in here, and an event with the category **Workers' Meeting** refuses non-workers on
  the Events Attendance tab too, where its roster is the same list (people serving on
  a department roster, not a profile label). The door QR is not restricted. Anyone present who is not on a roster is counted ("+3 not on a
  roster") but never listed as a worker.
* **Who may clock in.** Anyone with **Events & Attendance** access clocks in any
  worker. A head of department without it clocks in only the people on their own
  departments' rosters. A closed event cannot be changed.

## Messages: text, email and app alert to workers (since November 2026)

The **Messages** tab sends one message to workers by **text, email and app
alert at once**. Only people with **SMS Studio** access see it: nobody new can
spend the SMS wallet.

1. **Who gets it**: *All workers* (everyone on an Active roster this ministry
   year), *Departments* (tick one or more; *Include sub-units* adds e.g. Audio
   under Choir), *Absent* (workers not yet present at an event you choose: the
   Attendance tab's list) or *Leaders* (Pastors in charge, Directors, HODs).
   People marked Relocated never get it.
2. **Subject**: the email subject and the app alert title. Texts do not show it.
3. **Message**: tap *First name* or *Full name* to put the person's name in
   (`{{first_name}}`, `{{full_name}}`; `{{last_name}}` works too, nothing else
   does). The counter shows how many texts each person costs. Every text
   starts with `HODLEKKI: ` and one text holds 160 letters; the naira sign and
   emoji make a text cost about three times more, so write `NGN`.
4. **It reaches** counts, before you send: workers, texts (and the cost), emails
   (and why the rest cannot get one), app alerts.
5. **Send test** sends it to you only (your phone, your email).
6. **When**: *Now*, or *Later* at a time 5 minutes to 30 days ahead. A scheduled
   message works out who gets it **at that time** (an *Absent* message sent at
   9:50 PM reaches whoever is still absent then). *Cancel* stops it until it goes.
7. **Templates**: *Save template* keeps the message under the subject's name; it
   is the same list as SMS Studio's templates.

What happens to each part:

* **Texts** go through SMS Studio's own queue: the same wallet check, block
  list, duplicate guard and sender name. One text per phone number. They show
  in SMS Studio's *Campaigns* (audience *departments*) and *History*.
  A **Send now** whose texts cannot go (wallet too low, SMS not set up) sends
  **nothing** and says why. A **scheduled** one still sends the emails and alerts
  and tells you (in your bell) why the texts did not go.
* **Email** goes to each worker's personal address, looked for in this order:
  the confirmed personal email, else the *Personal email* on their profile,
  else the email column (where many Gmail, Yahoo and Outlook addresses ended up
  after the migration). A `@hodlc.com` sign-in name is **never** used, nor a
  church `@hodlekki.org` address, an address that bounced, or one another
  person has confirmed as theirs. Workers who unsubscribed from church emails
  get none. One email per address. Emails leave only between 7 AM and 8 PM and
  carry the unsubscribe link; one that cannot leave by the end of its day is
  dropped, never sent late. So send a reminder **before 8 PM**.
* **App alert**: free. It lands in the worker's bell and as a push on phones
  with the app installed.
* **Sent and scheduled** lists every message with its progress: texts sent of
  total, emails sent, alerts. *Use again* copies a message into the form.

