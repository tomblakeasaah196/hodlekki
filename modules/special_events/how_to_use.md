# How to use Special Events

## 1. Overview

Special Events is where Envision builds and runs a one-off night — Chara, a
karaoke and games evening, the next edition of anything. One screen holds the
whole life of an event: create it, give it a short link and your colours, set
how many seats it has, add the crew, and publish it to a public page at
**hodlc.lpc.cm/e/your-link**.

It is **separate from Sunday**. Nothing here touches the Sunday services,
`events` or the regular check-in — these events have their own records and
their own page, and the only data that ever leaves is what you deliberately
hand over to Reach and Embrace afterwards.

**Who can open it**

- **Super Admins**, the **Resident and Associate Pastors**, and any **Envision
  HOD or Director** — they manage every event.
- **Any active Envision member** — they can create events, and whoever creates
  one becomes its Producer.
- **Anyone on an event's crew**, for that event only.

Nobody else: the sidebar link is hidden, and every action is checked again on
the server. Hiding a button is never the lock.

> **What this version does.** Everything from event setup through registration,
> check-in, programme, karaoke and the full games night: quizzes, party games,
> scoreboards, awards and the finale. The after-event report and hand-off to
> Reach and Embrace arrive in the next release.

---

## 2. Making an event

**Special Events → New event.** Four things, then you are in:

| Field | What it is for |
| ----- | -------------- |
| **Name** | "Chara". Shown everywhere. |
| **Short link** | The `chara` in `hodlc.lpc.cm/e/chara`. This goes on the poster, so keep it short and easy to say out loud. |
| **Starts** | The date and time. Doors and check-in are filled in for you and you can change them later. |
| **Seats via the link** | How many people may register online. Chara used 120. Leave it empty for no limit. |

**Series** groups the editions of a recurring event, so next year's Chara can
be cloned from this one and the two can be compared afterwards.

Every new event starts as a **draft**: only you and the crew can see it.

### The short link

Lowercase letters, numbers and hyphens, up to 40 characters. The Studio checks
it as you type and tells you at once whether it is free.

- A word the site already uses (`admin`, `play`, `stage`, `me`…) is refused,
  with an alternative offered.
- Typing something with spaces or punctuation gets you a one-click suggestion
  ("Chara Night!" → `chara-night`).
- **Changing the link later is safe.** The old one keeps working and redirects
  to the new one, so a printed QR code never dies.
- If an **archived** event holds the link you want — last year's Chara owns
  `chara` — the Studio offers **Reclaim it**. The older event moves to a dated
  link such as `/e/chara-2026`, and the poster everyone already has now opens
  this year's event. That is exactly what you want for an annual night.

---

## 3. Cloning last year's

**Special Events → Clone**, pick the event, choose what to copy:

| Copied | Never copied |
| ------ | ------------ |
| Description, venue and organiser | Who registered, who checked in |
| Colours, fonts and brand-kit images | Scores, answers, karaoke claims |
| Capacity rules and the form | Display keys (new ones are made) |
| Your own questions | The old link |
| Team colours (empty, ready to fill) | Team names and captains |
| Welcome verses, still approved | How often each verse was used |
| The crew (off by default) | |

The dates shift to the new start date, keeping the same shape — a Saturday
evening stays a Saturday evening. The clone is a draft, and the song list is
never left published.

---

## 4. Details

Name, edition ("2026"), tagline, venue and the days.

**The description** takes Markdown: `#` for a heading, `**bold**`, `*italic*`,
`- ` for a list, `[text](https://link)` for a link. Anything else is shown as
plain text — you cannot break the page, and nobody can paste anything harmful
into it.

**Days.** One row per calendar day. Each has four times:

- **Doors open** — when check-in starts. Defaults to an hour before.
- **Starts** and **Ends** — the event itself.
- **Check-in closes** — defaults to the end.

A multi-day event just gets more rows.

---

## 5. Brand

Paste your two hex codes and everything else is worked out for you.

- **Primary** — the dominant colour. Buttons, glow, the background tint.
- **Secondary** — the contrast colour. The edition number, the focus ring.
- **Accent** — optional. Leave it empty and a complementary one is derived.

**You cannot make an unreadable page here.** Every other colour — background,
text, muted text, the text on each button — is computed from your two, and if
a pairing would be hard to read it is lightened until it is not. The
**Preview** panel shows the real hero and a contrast report: eight pairs, each
with its ratio and whether it meets WCAG 2.2 AA. Aim for all eight green.

**Suggest palettes ✨** asks AI for four ideas. It **never changes your two
colours** — it only proposes an accent and some team colours, and the contrast
figures you see were recomputed on our side, not by the model. Pick one and
press **Apply accent**. If the button is missing, no AI key is configured;
an administrator can add one.

**Fonts** are two Google Fonts — one for headlines, one for text. Choosing a
display font picks its matching body font automatically.

**Images** are chosen from what you uploaded on the Assets tab.

---

## 6. Registration

Everything about how people get a seat.

**Seats**

- **Seats via the link** — the online limit, or tick **No limit**.
- **Close registration automatically at capacity** — on by default. You can
  always reopen it with the override below.
- **Let people cancel their own seat** — a freed seat goes to the first person
  on the waitlist.

**Waitlist.** Once the seats are gone, keep a list. When a seat opens, the
next person is confirmed automatically (or the crew chooses), and they get one
text message.

**Walk-ins.** People who turn up without registering. Chara planned for about
30. A **hard cap** means the desk cannot go past the number; otherwise it is a
guide and the desk can use its judgement.

**Seats left counter** — never, once it is filling up, or always.

**The form.** The phone number always comes first and is always required: it
is how we recognise members and welcome people at the door. Gender, email and
"how did you hear about us?" can each be required, optional or not asked.
Gender is required by default because the teams are balanced by it.

**Consent.** This is the wording a guest agrees to, and **leadership must
approve it before registration opens**. Two modes:

- **A required tick** (the default) — they cannot register without agreeing we
  may contact them afterwards.
- **Notice plus an optional opt-in** — they always see the notice, and
  follow-up is a separate, optional tick. People who do not tick it are left
  out of the hand-off.

Exactly what was shown is recorded with each registration, so there is never
any doubt about what someone agreed to.

**Your own questions.** Up to 20. Each can be text, a longer answer, a choice,
a number or a tick box, and can be asked of everyone, only guests or only
members. They appear in the export. Removing a question hides it but keeps the
answers people already gave.

**Override** (Producers). **Force open** or **Pause registration**, whatever
the numbers say — useful when the Pastor says "let them all in" or when
something has gone wrong. Every change is recorded with your reason.

### Opening registration

Three things decide whether the button on the public page actually takes a
registration, and they are checked in this order:

1. **Is the event published?** A draft takes nobody. Publish it (§9).
2. **Is it inside the window?** **Opens** and **Closes** on this tab. Leave
   **Opens** empty and it is open from the moment you publish; leave
   **Closes** empty and it closes when the event starts.
3. **Are there seats?** If the online seats are gone, it is the waitlist (if
   you turned it on) or "Full".

An **override** beats all three: **Force open** takes registrations even when
the event is full or the deadline has passed, and **Pause registration** stops
them even when everything else says yes.

What a guest sees in each case:

| State | The button says |
| ----- | --------------- |
| Before the window opens | *Registration opens soon*, with the date |
| Open | *Register*, with the seats-left counter if you turned it on |
| Seats gone, waitlist on | *Join the waitlist* |
| Seats gone, no waitlist | *Fully booked* |
| After the deadline, or paused | *Registration is closed* — and, if walk-ins are on, "come to the desk on the day" |
| On the day | *Check in* |
| Afterwards | *Relive the night* |

**To open registration right now:** Registration → set **Opens** to today (or
clear it) → **Save** → Overview → **Publish**. Open `/e/your-link` on your own
phone and register yourself once, as a test, before you send the link out. You
can delete your test registration from Attendees afterwards.

---

## 7. Assets

The brand kit: hero image, logo, flyer, link card, and later the card
templates your designers make.

Pick what the file is for, choose it, and give it **alt text** — a short
description of what is in the picture. Images will not save without it,
because someone using a screen reader, or on a connection too slow to load
pictures, should still know what they are missing.

What happens to an upload:

- The real file type is worked out **from the file itself**, never from its
  name, so a `.jpg` that is really something else is refused.
- Photographs are **re-saved by the server**, which removes the camera and
  **location data** hidden inside them. A photo taken at home does not tell
  the internet where you live.
- Smaller versions are made automatically, so a phone on 4G does not download
  a 3000-pixel image.
- SVG files are cleaned of anything that could run code.

---

## 8. Crew

Who can do what, for this event only.

| Role | What they can do |
| ---- | ---------------- |
| **Producer** | Everything for this event. |
| **Host / MC** | Drives the show: scenes, programme, announcements, games. |
| **Game master** | Launches rounds, judges answers, fixes scores. |
| **Desk volunteer** | Checks people in and moves people between teams. |
| **Karaoke DJ** | Runs the karaoke queue. |
| **Media** | Uploads assets and renders the formats. |
| **Follow-up liaison** | Sees attendee details, exports, runs the hand-off. |
| **Viewer** | The numbers, nothing else. |

Search by name or email and pick a role. They get a notification with a link.

**The last Producer cannot be removed** — an event always needs someone who
can edit it. Add the replacement first.

---

## 9. Publishing

**Overview** shows a readiness ring and a checklist. Red items block
publishing; amber ones are advice.

**Required**

- Name, link and at least one day with sensible times
- Venue name
- Brand colours (the HOD defaults count)
- Registration settings looked at — a number, or "No limit" chosen on purpose
- Consent text, and a privacy contact email (an administrator sets that once,
  in Settings)
- At least one Producer

**Advice**

- A hero image with alt text
- A rendered link card, so a shared link looks good in WhatsApp

Press **Publish** and `/e/your-link` is live.

- **Back to draft** works only while nobody has registered. After that, use
  **Pause registration** instead — taking a page away from people who already
  have a seat is never the right move.
- **Cancel** needs a reason, which guests see on the page.
- **Archive** is for afterwards, and frees the link for next year.

---

## 10. The public page

`hodlc.lpc.cm/e/your-link` is the page you send people to. It opens on your
hero image and your colours, counts down to the night, then walks down the
page: **what it is**, **the night chapter by chapter**, **where**, and
**good to know**. A button follows the guest down the screen and always says
the right thing for the moment (the table in §6 lists them all).

Everything on it comes from what you filled in. There is no separate "website"
to maintain: change the tagline in Details and the page changes.

It also has:

- **`/privacy`** — what you collect and why, in plain words.
- **`/calendar.ics`** — "Add to calendar" for any phone or laptop.
- **`/e/`** — a hub listing published public events. An event marked
  **Unlisted** works by link but never appears there.

**A draft is invisible.** Someone guessing the address gets "Nothing here" —
not "you are not allowed", which would tell them it exists. To show a draft to
someone outside the crew, copy the **Draft preview** link from Overview.

**When you paste the link in WhatsApp** the preview card appears: your link
card if you rendered one in Assets, your hero image otherwise. Render the link
card — it is the difference between a shared link people tap and one they
scroll past.

### What registering is like

A guest taps the button and a sheet slides up. It asks for a **phone number
first**, and that one number does all the work:

- a **member** is recognised — "Welcome back, Ada" — and only has to confirm;
- someone who **came last time** gets their details filled in already;
- a **new guest** types their name, and whatever else you marked required.

Then the consent wording you approved, their tick, and they are in. It is
three taps and a phone number for most people, under half a minute on a cheap
Android.

**"I'm registering for someone else"** is a tick on the sheet — for the
husband signing up his wife, or the leader signing up a member without a
phone. The seat belongs to the person named, not the phone that booked it.

### After they register

A success screen with a bit of confetti, their **ticket**, and four things:

| | |
| --- | --- |
| **Save my link** | A private link to their seat. It is also texted to them. |
| **Add to calendar** | For any phone. |
| **Share "I'm going"** | A card with the event art, their name and a QR — see §13. |
| **Invite a friend** | Their own link, so you can see who brought whom. |

If the seats were gone they are told plainly that they are **on the waitlist**
and where they are in the queue. When a seat opens they are promoted in order
and get one text message — nobody has to watch the page.

### Their private link

The link they saved is `hodlc.lpc.cm/me/<their code>`. It shows their seat,
and lets them:

- see whether they are **confirmed** or **on the waitlist**;
- **release their seat** (if you allowed self-cancelling) — which immediately
  promotes the next person on the waitlist;
- share the "I'm going" card again;
- **stop all messages** about this and future events.

It works on any device, so sending it to themselves and opening it on a laptop
is fine. **Lost it?** The page has **Text me my link**. For safety, that never
says whether a number is registered or not — it always answers "if that number
is registered, we have texted the link", so nobody can use it to find out who
is coming.

---

## 11. Attendees

The tab that appears once registration is on: everyone who has a seat, who is
waiting for one, and who let theirs go.

Along the top: **Confirmed**, **Waitlist**, **Cancelled**, **Walk-ins**,
**Checked in**. Below that a search box (name, phone or email), a status
filter, and the list.

Tap anyone to open them. A 🎤 beside a name means they said they would like
to sing; a 💛 means they would like a Sunday visit.

**What you can do with a person**

| Action | What it does | Who |
| ------ | ------------ | --- |
| **Correct details** | Fix a name, gender or email. Also fixes what pre-fills for them next time. | Producer, Liaison |
| **Release seat** | They are out, and the first person on the waitlist is confirmed. | Producer, Desk |
| **Put back** | Undo a release, if there is still room. | Producer |
| **Promote** | Pull one person off the waitlist now, out of turn. | Producer |
| **Remove** | For a duplicate or a bad entry. Asks you why, and the crew log keeps it. | Producer |
| **Reset links** | Kills every link that person holds and issues a new one. Use it when a link was forwarded to the wrong group. | Producer |
| **Erase** | Deletes the personal details for good, keeping only the anonymous count. For "please delete my data". | Producer |

**Add someone by hand** is at the top of the tab, for the desk and for the
phone call: a phone number and a name is enough, and it works even when
online registration is closed.

**Worth a look** quietly flags two things and changes nothing on its own:
people who look like **duplicates** (the same person registered twice on two
numbers), and guests who look like they are **already members**.

### Export

**Export to Excel** gives you a spreadsheet with two sheets: every attendee
with their answers to your own questions, and a summary of the numbers. Use it
for the door list, for the caterer's count, and for the follow-up meeting.

- Only a **Producer** or a **Follow-up liaison** can export, and every export
  is recorded — who, when, how many rows.
- People who **opted out** are in the count but their details are withheld.
- The file holds real phone numbers. Treat it like the offering: do not leave
  it in a WhatsApp group, and delete your copy when the follow-up is done.

---

## 12. Share kit and copy help

Both live on **Overview**.

**Share kit** gives you the same link once per place you are going to post it
— WhatsApp, Instagram, the flyer, the Sunday announcement, and the rest — each
with its own **QR code**. Copy the link, or download the QR as a PNG for the
designer.

Use the right one each time and the view count beside each code tells you
afterwards **which place actually worked**, so next year's push goes where the
people are. The counts are numbers only; nobody is tracked.

Registrations are attributed the same way, so Attendees can tell you that the
flyer brought 40 people and Instagram brought 4.

**✨ Suggest three** is the writing helper. Pick what you need — a tagline, the
page description, a blurb, an FAQ answer, an SMS, a card headline — add a line
of brief if you want ("mention that it ends by 9pm"), and you get three
options that respect the length limit. Nothing is ever applied for you: read
them, pick one, edit it. If the button is missing, no AI key is configured on
the server, and you simply write it yourself.

---

## 13. The "I'm going" card

On the success screen and on their private link, a guest can make a card that
says they are coming: your event art and colours, their name, the date, and a
QR that brings whoever scans it straight to the page.

They can **add a photo**, which drops into a circle they can drag and pinch to
position. **The photo never leaves the phone** — the card is drawn on the
device, so there is no copy of anyone's selfie on our server. A card without a
photo looks just as good; it is genuinely optional.

Then **Share** hands it to WhatsApp, Instagram or anywhere else, and
**Download** saves it. Both a tall story-shaped card and a square one are
made, so it fits wherever they post it.

This is the cheapest invitation you have: it goes out in your guests' own
voice, to people no flyer reaches.


---

## 14. Checking people in

Check-in is the one part of the night where a queue is unforgivable, so there
are four ways in and they all work at once.

### The window

Check-in opens when **doors open** and closes at the day's **check-in closes**
time. Before it opens, the page shows a countdown — "Check-in opens in 42
minutes" — rather than a dead end. Both times are set per day in **Details**.

### On their own phone

The QR on the posters goes to `/e/<your-link>/in`. They type their phone
number, see their name, and tap **Yes, that's me**.

What happens next, in about a second:

- a **verse** appears, picked from the ones you approved, with their name in
  the prayer line;
- their **team** sweeps in in its colour, with their **player number**;
- the lobby screen says "Welcome Ada O. → Team Black".

If they have already checked in tonight **on that phone**, they just see their
card again. If they are already checked in but this is a **different** phone —
a borrowed one, or a new handset — the page opens **read-only**: they can see
their team and number but cannot play from it. To move the night to the new
phone, ask the desk for a **transfer code**.

If we do not know whether they are male or female, we ask once. It is the only
thing teams are balanced on besides size, so it is not optional — but it is one
tap and it is explained on screen.

### Walk-ins

Somebody who never registered taps **I'm not registered**, gives a name and a
number, and is in. Walk-ins come out of the walk-in allowance you set in
**Registration**, so they can run out while online seats remain — that is the
point of having two pools.

### At the desk

**Desk mode** is at `/e/<your-link>/desk` and needs a crew sign-in. Search by
name, by the last four digits of a phone number or by player number, and tap
the person. It is built for one hand and a bad signal:

- **Nothing waits for the network.** Every check-in is saved on the device
  first and sent afterwards. If the Wi-Fi drops, carry on — the counter says
  "3 waiting to sync" and they go up by themselves when the signal returns.
- **Walk-in** adds somebody who is not on the list.
- **Undo** reverses the last check-in. The player number, the team and any
  karaoke place stay — they were said out loud and taking them back would
  renumber the room.
- **Get a code** issues a six-digit transfer code, good for ten minutes and one
  use, that moves a guest's night to a different phone.

### Posters

**Check-in → Posters** makes the printable QR, in A4 for doors and corridors
and A3 for the foyer. Press the button, then use **Print PDF** — it is sized
to the paper exactly, so "Fit to page" cannot shrink it. The address is also
printed in words underneath, for the camera that refuses to focus.

Print them early. A poster is the only part of check-in that does not need
anything to be working.

---

## 15. Welcome verses

**Check-in → Welcome verses** is the list guests are greeted with. Each person
gets one, spread evenly across the list.

Add a reference — `Psalms 16:11` — and the King James text is fetched for you.
The optional prayer line must contain `{name}`, which becomes their first name:
"May this be your season, Ada."

**Suggest verses** asks the AI for references on a theme you type. It never
sees a guest. It proposes references; the text still comes from the KJV lookup,
and every suggestion lands as **needs review**. Nothing is shown to anybody
until you press **Approve**. If no verse is approved, guests simply do not get
one — nothing breaks.

---

## 16. Teams

**Teams** is colours first, because that is how the room talks: "Team Red", not
"Team 3".

Paste hex codes into the box, one per line, and the readable name — Red, Royal
Blue, Lime — is worked out for you. Two to eight teams. Duplicates are dropped,
because two teams called Red is nobody's idea of a good night.

Two warnings can appear, and neither blocks you:

- **two colours look alike** on a projector, which is a different question from
  whether they look alike on your laptop;
- **a colour is low contrast** against the stage background, in which case the
  screens draw it with a white ring so it still reads from the back row.

### How people are put on teams

Automatically, at check-in, in this order: the **smallest** team first; then
the team with the **fewest of that person's gender**; then the fewest **members
or guests** to match; then simple round-robin. The result is that team sizes
never differ by more than one, and the gender split stays even — without
anybody queueing to pick sides.

### On the night

- **Names**: crew types them in, here or on the host console. The stage plays a
  cue and reveals the name.
- **Captains**: set from the host console or here, from that team's roster.
- **Moves**: a producer can move somebody, with a reason, which goes in the log.
  Points already scored stay with the team that earned them.

**The team count locks** the moment the first person is assigned. Colours,
names and captains can still change — the number of teams cannot, because the
room has already been told.

---

## 17. The screens

Two screens, both unattended, both opened with a link that carries a key.
Find them on **Live → Screen links**, and **Rotate the links** if one has been
shared too widely.

### Lobby

A TV by the door. Left: the big QR and a countdown to the start. Right:
arrivals flying up as people check in, newest biggest. Underneath: a bar per
team so the room can see the balance. After a quiet minute it shows a tip
instead.

If you would rather not show names downstairs, turn off **lobby names** in
Settings and the cards read "New arrival → Team Black".

### Stage

The projector in the hall. Open the link, press **Start** once — that unlocks
the sound, goes full screen and stops the laptop sleeping — and leave it alone
for the rest of the night.

It never asks our server for content: it reads a published file, so a slow
moment on the website cannot blank the screen. A dot in the corner goes amber
and then red if it stops hearing anything, and the last scene stays up.

Scenes in this release: standby, welcome, teams, announcement, break, blank,
the programme, karaoke, every game, leaderboard and finale. The Feud board,
Who Am I? clues and charades timer all update from the same live state as the
host console.

---

## 18. The host console

`/e/<your-link>/host`, crew sign-in. One person runs the night from here.

- **Scene** — what the stage is showing. Tap a scene; it changes in about a
  second.
- **Announcement** — up to 160 characters on every screen and every phone, for
  as many seconds as you choose. "The bus leaves at 9:30."
- **Sound board** — the cues: fanfare, applause, drumroll, ding.
- **Run of show** — the programme, with the next few items and their times.
  **Start** when a thing really begins and **Finish** when it really ends;
  everything after it moves, here and on everyone's phone. **Skip** drops an
  item, **Undo** takes back the last press.
- **Karaoke** — who is on stage and who is next, with one button to send the
  next singer up. The full queue lives on the DJ screen.
- **Teams** — rename, set captains.
- **Blackout** — the big red one. Also the `B` key.

Keyboard, when you are on a laptop: `W` welcome · `T` teams · `S` leaderboard ·
`K` karaoke · `B` blackout.

Along the top, four dots tell you whether the night is actually working:
**Screens** (how old the published state is — under three seconds is healthy),
**Cron**, **SMS worker** and **Door** (is check-in open).

**If two people are driving at once**, the second one to tap gets "Someone else
just changed the show — check and retry" instead of silently undoing the first.
That is deliberate. Decide between you who is driving.

---

## 18b. The programme

**Programme** tab in the Studio.

A run of show is a list of things with lengths. Add them with **Add an item**,
order them with the arrows, and type a length in minutes. Most items simply
follow the one before, so you do not type times at all — the grey time under
each title is worked out for you. **Pin a time** only where it matters
("doors at 5:00", "the message at 7:15"); the items around it move, the pinned
one does not.

Per item, under **More**: who is leading it, a line for the guests, a crew
note nobody else sees, whether it appears on the public page at all, and
whether it is **featured** (bigger on the programme and on the stage screen).

**What guests see** decides how times are printed: *approximate* ("~7:15 PM",
the kind default), *exact*, or *order only* — no times, just the order.

**Importing one.** Most programmes arrive as a screenshot in a group chat.
Paste the text into **Import a programme** — or upload the picture or PDF on
the Assets tab and choose it here — and press **Read it**. You get a table of
what we read, which you can correct, and nothing is saved until you press
**Apply**. Choose **Add to the programme** or **Replace this day**.

On the night, the host presses Start and Finish on the console and the times
everywhere follow. If the night is running late, the portal says so.

---

## 18c. Karaoke

**Karaoke** tab in the Studio.

The song library is shared across every event: typing "Way Maker" tonight and
"way maker " next year gives you one song, not two. Adding songs to tonight's
list is what this tab does.

**Adding songs.** Paste a list into **Add songs** — one per line, "Title —
Artist", with a length on the end if you have one — and press **Read it**. A
CSV with `title,artist,duration` columns works too; press **It is a CSV**. You
see what we read, with each row marked *new*, *already in the library*,
*already on tonight's list* or *might be a duplicate*. Untick anything you do
not want, then add them. For a photographed song book, upload the picture on
the Assets tab and choose it here.

**Publishing.** Nobody can pick until you press **Publish the list**. Do it
when the list is final; unpublishing hides it again.

**How it runs** (the settings at the bottom):

- *Let people pre-pick* — guests choose on their phone before the night. A
  pick made before they arrive is a **hold**, not a place in the queue; it
  becomes a queue number when they check in, in arrival order. That is the
  fair way round, and the phone says so.
- *One person per song* — on by default. Two people tapping the same song in
  the same second is normal; exactly one gets it and the other is told
  straight away, with the list refreshed.
- *Release a hold after* — if somebody pre-picks and never turns up, their
  song goes back on the list this many minutes after the doors open.
- *Songs per person*, *most singers tonight*, and *minutes per song* (used for
  the "about 40 minutes left" line on the DJ screen).

**The DJ screen** is `/e/<your-link>/dj`, crew sign-in. On stage and Up next
are pinned to the top with big buttons: **Call them up**, **On stage**,
**Done**, **Skip**, **No show**, and **Back in the queue** for somebody who
stepped out. The waiting list is below, with arrows to reorder — reordering
changes the running order, never the numbers people were given at the door.
**Add someone** puts a walk-up into the queue with their player number.

A singer's phone buzzes and says **You are up next** when the DJ calls them.

---

## 18d. Messages

**Messages** tab in the Studio. Everything here costs money, so the tab shows
you the bill before you commit to it.

Each message shows exactly what will be sent, how it is encoded, how many
segments it is per person, how many people will get it, and the estimated
units. Edit the words and the numbers update. **Test send** texts it to one
number so you can read it on a real phone first.

- **The evening before** (*reminder 1*) goes out at a time you choose on the
  day before. On a two-day event it is sent once, before the first day.
- **Before the doors** (*reminder 2*) goes out a number of minutes before each
  day starts — so a two-day event gets one per day.

Each send is created once and once only: if the cron job stalls and catches
up later, nobody gets the same text twice. A send that is more than
**90 minutes late** is skipped rather than delivered at the wrong moment; the
run log says so.

**Send something now** is for what nobody planned — a venue change, a delay, a
thank you. Choose who gets it, write it, press **Check it first**, read how
many people and how many units, then send. There is no undo.

The **run log** lists every send with its status and a link into SMS Studio,
where delivery reports live. **Run what is due now** is the recovery button
for a night when the cron job is not running; it cannot double-send.

**The cron job.** Add this once in cPanel → Cron Jobs:

```
*/5 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/special_events.php >/dev/null 2>&1
```

It creates the reminders that are due, releases abandoned karaoke holds and
clears out expired tokens and old AI source files. The **Cron** dot on the
host console turns amber if it has not run for ten minutes.

---

## 19. Rehearsing without leaving a trace

**Live → Rehearsal**, producers only.

Turn **test mode** on and walk the whole night through: register, check in, see
the teams form, drive the scenes. While it is on:

- every screen carries a red **TEST MODE** ribbon, so nobody mistakes a
  rehearsal for the real thing;
- the crew can check in **outside** the check-in window;
- registrations and check-ins made now are marked as tests and do not count
  towards capacity or any number you report.

**Reset rehearsal** deletes every test registration, check-in and karaoke
entry, and the contacts that only existed because of them. Real data is never
touched.

Test mode **switches itself off fifteen minutes before doors open**, because
the one way to ruin a night is to leave it on.

---

## 20. Settings and Health

**Settings** (administrators only) holds what applies to every event: the
default colours, the privacy notice, the **privacy contact email** people
write to about their data, how long guest details are kept, and the AI limits.

**Health** is worth a look after every deploy. It reports the module version,
whether AI is on, whether the token key is configured, and — most usefully —
**Schema**: whether every table the module needs actually exists. Green means
the database is up to date. Red names what is missing.

---

## 21. FAQ

**Can I change the link after the posters are printed?**
Yes. The old link redirects to the new one, forever.

**Someone else saved while I was editing.**
You are told, and asked whether to reload their version or save over it.
Nothing is lost silently.

**Why can I not publish?**
Overview lists exactly what is missing, in red, each with where to fix it.

**Why is "Suggest palettes" missing?**
No AI key is configured. Everything else works; you just choose the accent
yourself.

**Can I delete an event?**
Only a draft, and only an administrator. Once anyone has registered it is
history, so cancel or archive it instead.

**Does this touch Sunday attendance?**
No. These events keep their own records entirely.

**Two people picked the same song.**
They cannot. The second tap is refused in the database itself, not by a
check that can be raced, and the person who lost is shown the list again
immediately.

**Someone pre-picked and never came.**
Their hold is released automatically, so many minutes after the doors open
(Karaoke tab). Their song goes back on the list for everybody else.

**Can I resend a reminder?**
Not the same one — that is the point of the run log. Use **Send something
now** if the room genuinely needs to hear it again.

**Who sees a guest's phone number?**
Only a Producer or a Follow-up liaison. Screens during the night show "Ada O."
and never a phone number.

**When can people register?**
As soon as the event is published and the registration window is open. See
"Opening registration" in §6 for the three things that have to line up.

**Someone registered twice on two numbers.**
Attendees → **Worth a look** flags it. Open the one you want to keep, then
**Remove** the other with a reason.

**Someone wants their data deleted.**
Attendees → open them → **Erase**. The personal details go for good and only
the anonymous count remains. Record why when it asks.

**A guest lost their link.**
On the public page, **Text me my link**. They can also just come to the desk —
their phone number is enough.

**Can I take a registration over the phone?**
Yes. Attendees → **Add someone by hand**. It works even when online
registration is closed.

**Does the "I'm going" photo get uploaded?**
No. The card is drawn on the guest's own phone and the photo never leaves it.

**Someone checked in on a friend's phone by mistake.**
The desk issues a **transfer code**. They type it on their own phone and the
night moves across; the friend's phone drops to read-only.

**The desk tablet lost signal halfway through the queue.**
Keep going. Desk mode saves every check-in on the device first and shows how
many are waiting. They send themselves when the signal returns.

**The stage screen has frozen.**
Look at the dot in the corner. Red means it has stopped hearing from us — the
last scene stays up on purpose, so the room sees something rather than nothing.
Reload the page; it picks up wherever the night has got to.

**Can I change the number of teams after people have arrived?**
No. The moment the first person is assigned, the count locks — they have
already been told their colour. Colours, names and captains can still change.

**Why is this person not on a team?**
Teams are assigned at check-in, not at registration. Somebody who has not
checked in has no team and no player number yet.

**We rehearsed with real-looking data. Is it in the numbers?**
Not if test mode was on. **Live → Reset rehearsal** removes every test row.
Check the red ribbon was showing during the rehearsal — if it was not, the
rows are real and have to be removed one by one from Attendees.

## 13. Games: decks, rounds and scoring (PR5)

The **Games** tab is where the Producer prepares the night. A deck is a
reusable library of reviewed questions; event decks are private to one event.
Choose `mcq`, open answer, emoji, verse, charade, clues or survey content.
Every item is saved as **Draft** until a crew reviewer approves it. AI can
suggest Bible content, but it never supplies Bible text: references are
looked up from KJV in the Studio, and a person reviews the result before it
can be used. If AI or the Bible service is unavailable, enter and review an
item by hand.

Add games in the order they will run and attach approved deck items. The three
PR5 game families are:

- **Live Quiz** — every checked-in player answers on their phone. It uses a
  shared countdown and scheduled reveal; the earlier a correct answer arrives,
  the more points it earns.
- **Bible Trivia** — each team captain submits for the team while teammates
  send suggestions. Suggestions are private to that team. If a captain does
  not answer, the configured team vote can be used.
- **Buzzer** — Bible Buzzer, Finish the Verse and Emoji Bible use the same
  buzzer round. A team can buzz once per attempt; effective times are clamped
  to the server clock, and the host judges the winner.

The `/e/<slug>/play` page is available only to a checked-in device. Guests tap
**Join the games**, then wait for the host. The stage and phones use the same
server `opens_at` clock, so polling delay does not change the scoring window.
The question, choices and public reveal never contain a charades phrase or
private answer before the host reveals it.

### Running and rehearsing

In the Host console, use **Next round**, **Arm**, **Lock**, **Reveal** and
**Score**. A round is never scored twice: its ledger rows have idempotency
keys. To correct a result, void the round or an individual ledger row with a
reason, then award a new entry; points are never edited in place. Awards and
penalties are visible in score history and in the team and MVP leaderboards.

Turn on **Test mode** before rehearsal and use **Studio → Games → Rehearse**.
Game rows marked `TEST` are removed by Reset rehearsal; real scores and
registrations are left alone. Before the event, run the quiz load profile from
`tests/special_events/load/quiz.k6.js` and check the static snapshot and answer
thresholds in the engineering guide.


## 22. Party games, awards and the finale (PR6)

### Who Am I?

Use a **clues** deck. Each item needs three to five clues ordered hardest to
easier and one short answer. The host arms the round, reads the visible clue,
and presses **Next clue** after a wrong answer or no buzz. A correct answer is
worth 500 on clue one, then 400, 300, 200 and 100. A wrong team is locked out
until the next clue. Judge the winning buzz with ✓ or ✕ in the Host console.

### Bible Charades

Use a **charade** deck. In the Host console choose the acting team, then type a
player number or leave it blank for a random checked-in player with an active
phone. Press **Pick presenter**, then **Start timer**. The phrase appears only
on that presenter's phone and the private host console — never in a public,
room or team snapshot. The presenter can hide it immediately if somebody
looks over their shoulder.

Press **Got it** for +200 and the next phrase, **Pass** (normally twice per
turn), or **End turn**. The stage shows the presenter, team colour, timer and
number guessed, but not the phrase. If a selected player has no recently seen
phone, pick another or use the host tablet fallback.

### Bible Family Feud

Add a **survey** deck and attach it to a Feud game before sharing the event.
Registered guests see **Play ahead** on their game/manage experience. Answers
are one short line, can be changed until the Feud starts, and are anonymous
when sent to AI.

In **Studio → Games → Family Feud board**, enter the survey item ID and press
**Build from survey answers**. With enough answers, AI groups spelling and
synonym variants. With fewer answers, exact normalised groups are shown and
the crew can make the board manually. In both cases, read every label and
count, merge or rename as needed, then **Approve board**. AI output is never
played without this approval.

On the Host console choose Team A and Team B and start the face-off. Give the
winning team control, reveal a matching slot when they guess it, or add a
strike. Three strikes opens one steal. Mark whether the steal worked and
press **Bank**; the revealed survey counts are multiplied for that round and
written once to the score ledger.

### Leaderboards, MVP, awards and finale

**Leaderboard** shows team totals in score order and the individual MVP list.
Manual awards and penalties require a reason and remain in score history; undo
voids the ledger row instead of editing points. The MVP is the checked-in
player with the greatest individual total (ties follow the games rules).

At the end, press **Run finale**. The stage reveals the champion and MVP with
the event colours and fanfare cue. The same champion, MVP, named awards and
team totals are retained as recap data for the after-event release.

### Chara starters and rehearsal

**Add Chara starters** is idempotent: it adds Appendix G decks for Who Am I?,
Bible Charades and five Family Feud survey questions, already reviewed. Read
them anyway and tailor them to the room.

One week before the event:

1. Live → turn **Test mode** on. Confirm the red ribbon on stage and console.
2. Put the lobby on its TV and stage on the projector. Start sound once.
3. Use at least 20 phones on venue Wi-Fi. Register five new people; check in
   20 using self, desk and walk-in routes.
4. Run one round of every game, three karaoke singers, leaderboard and finale.
   Confirm snapshots stay under three seconds old and charades phrases appear
   only on the selected phone and host console.
5. Test a score correction, award and undo. Confirm the MVP and champion by
   adding the ledger totals independently.
6. Press **Reset rehearsal**. Confirm test registrations, rounds, answers,
   buzzes, survey answers and karaoke entries are gone, and TEST scores are
   voided. Real rows must remain.
7. Write the fix list, repeat the failed step, and turn test mode off.
