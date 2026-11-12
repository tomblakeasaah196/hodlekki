# Roles & permissions — How to Use

Who may open what in the ERP, and until when. Open to Super Admins (who can
change everything here) and the Resident Pastor (who can look and **view as**
anyone, but not change).

Two things give access:

* **Roles**, given to a person here (Resident Pastor, Assoc Pastor, or a role
  you create). A role can have a start and an end date.
* **Seats**, from the Departments roster (Pastor in charge, Director, HOD,
  Deputy HOD, Sub-Unit Head, Worker, Member). A seat gives access only while
  it is live, in the departments that own a module.

Everyone signed in also has what the **Everyone** column gives (their own
portal, profile, notes…).

## 1. Matrix

One row per module, one column per role, then per seat type, then Everyone.
Each cell reads like `E am · All`:

* the level — **V** view, **C** create, **E** edit, **D** delete (each includes
  the ones before it);
* the extras the module has — **a** approve, **x** export, **m** manage;
* after the dot, how far it reaches — **Own** records, **Dept** (the
  departments they serve in; for a seat, its department) or **All** (the whole
  church).

Click a cell to change it. The dialog says how many people it changes right
now. **Views** lets someone see further than they may change (view the whole
church, edit their department).

Click a column name for **Column actions**: copy another column, make it
read-only church-wide (money, security and private notes stay closed), or
clear it. Pick one column in the toolbar to read the matrix on a phone.

The Super Admin column says **All**: a Super Admin passes every check, and
every time that matters it is written to the Security Centre's log.

## 2. Roles

**New role** starts from a preset (Read-only church-wide, Department leader,
Pastoral, Finance officer, Blank) or as a copy of another role; then adjust it
in the matrix. **Edit** renames or describes it — renaming changes nobody's
access. **Archive** works once nobody holds the role. A role may not be named
after a member status (Worker, Member, Pastor…).

## 3. People

Search a person to see:

* **Roles** they hold, with dates. **Give role** (they need a complete profile
  and to be a Worker or Pastor), **Dates**, **Renew** (a dated role moves on by
  a year) and **Remove**. They are told each time.
* **Seats** from the Departments roster, with their end date and **Renew**.
* **Access**: every module they can open, the level, how far it reaches and
  **because of** what — the role or seat that gives it.

**View as** shows the ERP as that person sees it, read-only and never more
than you hold yourself. A banner says so on every page; press **Stop viewing**
to come back. Roles have **View as** too.

## 4. Seats

**End dates off?** They are switched on only when that ends nobody's access. If some
seats or roles are already past their end date, a banner says how many and this tab
lists them: **Renew** or **End seat** each one, then press **Enforce end dates**
(Super Admins; it refuses while any remain).

* **Seat types**: name, rank, how many per department, whether it covers
  sub-units and whether it may appoint. You can only appoint seats ranked below
  your own (Pastor in charge 60, Director 50, HOD 40, Deputy HOD 35…).
* **Seats per department**: untick a seat type a department does not use.
* **Module owners**: which departments' seats count for a module (IDI and
  Charis for Charis, and so on). The owners' leaders are also who is told
  about the module's work.
* Near the year end a banner counts the seats that end and links to **Roll over
  year** in Departments.

## 5. History

Every change — cells, roles, people, seats, renewals, view as — with who made
it, when, and what it was before and after. Filter by kind of change, module
or dates.

## Nobody gives more than they hold

A change that would give (or take away) more than you hold yourself is
refused. Super Admins hold everything.

## For administrators and developers

* Engine: `includes/permissions.php` (`can()`), `includes/permissions/admin.php`
  (changes), `includes/permissions/expiry.php` (end dates). API:
  `api/roles_api.php`. Tests: `php tests/permissions/run.php`.
* Daily job, 00:10: `cron/permissions_daily.php` (`--dry-run` to preview).
  Access ends on the day without it; only the messages and the tidy-up wait.
