# Alerts Centre — how to use

**Who can open it:** Super Admins and the Resident Pastor. The link is in the
sidebar under Security & Stewardship, and `Ctrl K` → "alerts" finds it.

It does two things: it tells you whether push notifications are actually
reaching people, and it lets you send one message to the whole congregation.

---

## 1. Is it working?

The four tiles across the top answer that at a glance.

| Tile | What it means |
| ---- | ------------- |
| **Devices** | Phones and computers that will buzz. One member with a phone and a laptop counts as two. |
| **Members reached** | How many *people* that is. This is the number that matters. |
| **Delivered today** | Notifications that actually landed on a device today. |
| **Waiting** | Queued but not yet sent. Normally 0 — this only climbs if a push service is refusing us. |

If push is not working at all, an amber banner says so in plain words and what
to do about it. The most common one on a fresh install is *"The database
tables are not there yet"*, which fixes itself on the next deploy.

**Delivery** on the right has the detail: where the signing keys live, whether
instant delivery is on, how far behind the fan-out is, a breakdown by platform
(iPhone, Android, Windows), and any failures from the last seven days grouped
by reason. A wall of `HTTP 401` means the VAPID keys were changed after people
subscribed — see `docs/pwa_push.md`.

---

## 2. Switches

Two, and both are written to the security audit log, same as suspending an
account.

- **Push notifications.** Off means nobody is sent anything at all. The bell at
  the top of every screen keeps working as it always has. Use this if something
  is badly wrong and you want it to stop now.
- **Instant delivery.** On, a notification is sent the instant it is written.
  Off, the once-a-minute cron job sends it instead. Leave this on unless you
  are chasing a performance problem.

---

## 3. Sending a broadcast

Fill in a title, a message, and who it goes to.

- **Title** is the bold line on the lock screen. Keep it under about 40
  characters or phones cut it off.
- **Message** shows as roughly two lines. The whole thing is always readable in
  the bell.
- **Opens when tapped** must be a path on this site, such as
  `/modules/events/index.php`. Anything else quietly becomes the dashboard —
  that is on purpose, so a notification can never send somebody off to another
  website wearing our name.

**Who** can be everyone, everyone who has alerts switched on, a department, a
role, or a region.

### It always asks twice

The button first says **Check who this reaches**. Press it and you get the real
number: *"412 people in Everyone. 168 will get it on a phone; the rest see it
in their bell."* Only then does the button become **Send it**.

Change anything after that — a word in the message, the audience, the link —
and it goes back to asking. A broadcast cannot be unsent, so there is no path
through this screen that sends to an audience you were not shown.

### What actually happens

A broadcast is written as an ordinary notification, one per person. That means:

- everyone sees it in their bell, whether or not they have ever installed the
  app or turned on alerts;
- people who have alerts on also get it on their phone, within seconds;
- quiet hours and anyone who has opted out are honoured automatically, because
  this goes through the same path as every other notification on the platform.

Every broadcast is recorded in the security audit log with who sent it, the
audience, and how many people it reached.

---

## 4. Things people ask

**"I sent it but my phone didn't buzz."** Your own phone only buzzes if *you*
have turned alerts on for it. Bell → the gear icon → *Alerts on this device*,
then **Send me a test alert**.

**"Someone says they never get anything."** They are either not installed (on
iPhone, alerts only work once the app is on the home screen), have alerts off,
or are inside quiet hours. The gear icon on their own bell shows all three.

**"Can I take it back?"** No. Nothing on this screen can unsend a
notification — which is why it asks twice.

**"Will this text people?"** No. This is push notifications only. SMS is SMS
Studio, and it costs money per message; this does not.
