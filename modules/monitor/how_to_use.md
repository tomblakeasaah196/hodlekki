# System Health: how to use it

System Health (menu → System → System Health) shows what is going wrong on
hodlekki.org, whether everything the site depends on is working, and how fast
the site is for members on their own phones. It is for Super Admins. The
technical guide is `docs/monitor.md`.

## Every morning

The **morning summary** email (08:00 unless changed in Settings) says whether
anything needs a look. If it says "All good", there is nothing to do.

## When something is red

- **Overview** shows the red items first, with a button to the details.
- **Health** lists every check. A red one says what is wrong and, under it,
  **What to do**. Most fixes are in cPanel (cron jobs, email accounts, disk) or
  in Cloudflare.
- Green again? You get a "Recovered" email; nothing else to do.

## Fixing an error with Claude

1. Open **Errors** and click the problem.
2. Read the box **What it usually means**.
3. Press **Copy for AI** and paste into Claude (or **Download brief** and attach
   the file). The brief has the message, where it happened, how often, which
   roles met it, the code around it and the deploys just before it. It never
   contains a member's name, phone number or email.
4. When the fix is live, press **Mark fixed** and say what changed. If the
   problem happens again, it reopens by itself and you are told.

**Being looked at** tells the team someone is on it. **Ignore** hides a
problem you have decided not to fix (a browser extension, a bot); it keeps
being counted.

Errors marked "A browser error" came from a visitor's browser. Anyone can send
one, so their text is shown exactly as received and never trusted.

## Speed

**Speed** shows how fast pages load for members on phones and on computers,
measured in their own browsers. Green means good; amber needs work; red is
poor. The table shows which pages are slowest.

## Uptime

**Uptime** shows whether hodlekki.org and connect.hodlekki.org answered when
checked from outside (about every hour), the certificate, and whether the
server refuses visitors who go around Cloudflare.

## Settings

- **Alert emails**: add an address; it receives a confirmation link and gets
  nothing until it is clicked. Remove an address with the bin icon. Every alert
  email also has a stop link. Super Admins always get the bell and phone
  notifications.
- **Morning summary**: choose the hour.
- **Live wall**: a full-screen view for a TV. Copy the link and open it on the
  screen; it needs no sign-in, so share it only with the team. **Make a new
  link** stops the old one working.
- **Send a test alert** proves that email and the bell reach you.

## Buttons at the top

- **Scan logs now** reads the server's error logs at once (the monitor does it
  every minute anyway).
- **Export open errors** downloads every open problem as one file for Claude.
- **Open live wall** opens the TV view in a new tab.
