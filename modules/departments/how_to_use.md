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
* **Add somebody** — **Add Member** → search for the name → choose
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
* **adding them back later is just Add Member again** — the same record is
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
  back as a Primary or Secondary member: **Add Member** does it in seconds.

Saving *Edit Settings* does the same thing: selecting a different person for a
seat replaces the sitting leader. A seat is never left half-changed, and the
transition is reported in the confirmation message.

## 4. Ministry years and the rollover worksheet

A ministry year has a label and a period (e.g. `2026`, 1 Jan 2026 – 31 Dec
2026). Rosters, downloads and reports are all tied to one.

When a new year begins, use **Start Ministry Year** (Super Admin or Resident
Pastor). It opens a **worksheet**, not a button:

1. Set the **label** and the **period** for the new year.
2. **Carry everyone over**, **Members only**, or **Start empty** — the presets
   fill the worksheet; then reshuffle by hand.
3. For each department (and every sub-unit) you can
   * appoint the three leadership seats,
   * add people with the searchable *+ Add a person* box,
   * flip a member between Primary and Secondary with the chip,
   * take somebody out with the × on their chip,
   * or **Clear** the whole department.
4. **Create year & go live.** See the running summary at the bottom for how many
   members are placed and how many seats are filled.

Going live:

* the new year becomes the **live** year;
* the previous year is **archived** — visible in the switcher, downloadable,
  but read-only;
* leadership seats are *not* carried over by the *Members only* preset: the new
  year starts with the seats empty so they are appointed deliberately;
* carrying everyone over keeps the seats as they are.

Past years are never edited in place, so last year's sheet always downloads
exactly as it stood.

## 5. Downloads

**Download** offers three things, always for the ministry year currently on
screen:

| Format | What you get |
| ------ | ------------ |
| **A4 image (JPEG)** | One A4 page at 300 dpi: church logo, **Ministry Year** heading at the top, then every department with its Pastor in Charge / Director / HOD and its Primary and Secondary members. |
| **Excel workbook (.xlsx)** | A *Summary* sheet (every department with both counts and leadership), one sheet per department (leaders, then Primary, then Secondary, with phone and email), and an **All Workers** sheet. |
| **CSV** | One flat table of every worker in every department. |

The A4 image always fits on a single page. It is drawn with the browser's
canvas at A4 proportions, and the fitter picks the largest type size that still
fits; for a very large roster the sheet switches to a denser arrangement rather
than spilling onto a second page. The download tells you which layout was used.

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
