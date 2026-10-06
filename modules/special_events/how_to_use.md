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
> check-in, programme, karaoke and the full games night — quizzes, party games,
> scoreboards, awards and the finale — and afterwards the insights report and
> the hand-off to Reach and Embrace.

**Finding your way around an event.** The sections sit down the left, grouped
in the order you meet them: **Set up** (Overview, Details, Brand,
Registration, Programme, Chapters, Music, Teams), **The night** (Check-in,
Karaoke, Games, Live, Crew, Assets), **People** (Attendees, Messages),
**Afterwards** (Insights, Hand-off) and **Settings**. On a phone they are in
the **Section** list at the top.

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

### The public page

Three small decisions about `/e/your-link` that belong nowhere else:

- **Opening line** — one sentence under the countdown. Leave it empty and the
  page uses your tagline on its own.
- **Count down to the first day** — off hides the clock, which is what you
  want for an event with no fixed start time.
- **Play the hero video when there is one** — off always shows the hero image,
  even if a video is sitting in Assets.

### Good to know — the questions guests ask

The list at the bottom of the public page, the one that opens when a guest taps
a question. A new event starts with six **starter questions** (is it free, what
to wear, do I have to sing, can I bring a friend, what time to arrive, will
there be food) and you change all of them here.

- **Add a question** — a new empty row at the bottom. Up to 20.
- **↑ / ↓** — the order here is the order on the page, so put the one everybody
  asks first.
- **Remove** — takes it off the page.
- **Restore the starter questions** — puts the original six back, replacing
  whatever is there now. You are asked to confirm first.
- **✨ Help me write this answer** — three suggestions from the AI helper for
  that one answer. Nothing is used until you pick it, and you can edit it
  afterwards. (Hidden when no AI key is configured.)

Answers are plain words — no Markdown, no links. A question needs an answer and
an answer needs a question: save a half-filled row and that row is highlighted
rather than quietly showing a blank panel to a guest.

**Remove every question and the whole section disappears from the page.** That
is a real choice, not a mistake, so nothing is put back for you.

Press **Save the public page** when you are done — this box saves separately
from the name, venue and dates above it, and neither one disturbs the other.

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

**Background music is not uploaded here.** It has its own tab (§18b-iii),
because a track cannot be saved without someone confirming its rights, and
that tick belongs on the form where the file is chosen.

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
hero image across the full first screen, with the animated event colours and
spotlights layered over it in a calm, readable order. The event name is printed
large and expressively: a short two-word name such as **Chara Night** is set
deliberately on two lines — *Chara* above, *Night* below — at full size instead
of being shrunk to squeeze onto one line. Longer names keep their natural
wrapping. Either way it is still one heading for screen readers.

Under the name, the clock counts down like a premiere: tall editorial serif
numerals on smoked glass, each digit rolling over like an odometer, with one
light sweep across the row when the page arrives. In the **night, chapter by
chapter** list, *The Teams* shows one glowing orb per team you set up in the
Teams tab, in that team's real colour, drifting together into a single light
and separating again — four teams, one night — with the colour names printed
underneath. The **ENVISION presents** credit in the top bar now arrives like a
title card: the name snaps in letter by letter, a hairline draws, and
*presents* fades up beneath it. All of it plays once and then holds still —
with one exception: the **event name itself keeps announcing itself**, its
letters flickering back in roughly five seconds after each pass settles, so a
guest who arrives mid-scroll or leaves the page open still catches it. Between
passes the name sits fully lit and readable, never blank, and it stops
entirely while the first screen is scrolled out of view or the tab is in the
background, so it costs nothing on a phone. A guest who has asked their phone
for reduced motion simply gets the finished frame. It counts down to the night, then walks down the page: **what it is**, **the night
chapter by chapter**, **where**, and **good to know** (the questions you wrote
in Details — see §4). The Household of David
logo has its own clear lockup in the top bar and appears again in the footer. A
button follows the guest down the screen and always says the right thing for
the moment (the table in §6 lists them all).

Every colour on that page comes from the **Brand** tab: the background, the
glass panels, the glow behind the name, the countdown, the credit in the top
bar, even the plate the church logo sits on are all derived from your primary,
secondary and accent. The team orbs come from the **Teams** tab and are shown
in each team's own colour, lit by lightening that same colour. Nothing on the
public page is a fixed colour, so changing the brand changes the whole night.

Everything on it comes from what you filled in. There is no separate "website"
to maintain: change the tagline in Details and the page changes.

If you have uploaded anything on the **Music** tab (§18b-iii), the page also
has a song behind it — starting quietly the moment the guest taps or scrolls,
with a small speaker and volume control in the bottom-left corner that they
can mute at any time.

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

**If they come back and type their number again**, they get the same ticket and
the same four cards, headed **"You're already in, Ada O. 🎉"** — they are never
asked to fill the form twice. The one difference is the first card: the private
link is only given to the phone that actually made the registration, so on any
other device it reads **Text me my link** instead. That is deliberate — the
alternative would let anyone who knows a phone number take over that seat. On
the phone that holds the registration, the link card is there as normal.

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
is fine. On a phone that already holds their ticket — the one they registered
or checked in on, or one the desk moved them to — `/e/<your-link>/me` opens
the same page without the link, and the **You're registered** button on the
event page goes there. **Lost it?** The page has **Text me my link**. For safety, that never
says whether a number is registered or not — it always answers "if that number
is registered, we have texted the link", so nobody can use it to find out who
is coming.

---

## 11. Attendees

The tab that appears once registration is on: everyone who has a seat, who is
waiting for one, and who let theirs go.

Along the top: **Confirmed**, **Waitlist**, **Cancelled**, **Walk-ins**,
**Checked in**. Below that a search box (name, phone or email), a status
filter, and the list. The status filter has a **Deleted** option of its own —
deleted people keep the status they left with (Cancelled, or Removed if you
also kept them out), so that is how you find them again.

Tap anyone to open them. A 🎤 beside a name means they said they would like
to sing; a 💛 means they would like a Sunday visit.

**What you can do with a person**

| Action | What it does | Who |
| ------ | ------------ | --- |
| **Correct details** | Fix a name, gender or email. Also fixes what pre-fills for them next time. | Producer, Liaison |
| **Release seat** | They are out, and the first person on the waitlist is confirmed. | Producer, Desk |
| **Put back** | Undo a release, if there is still room. | Producer |
| **Promote** | Pull one person off the waitlist now, out of turn. | Producer |
| **Delete** | Takes them off the list and **frees their number, so they can register again**. Opens both grades — see below. | Producer |
| **Reset links** | Kills every link that person holds and issues a new one. Use it when a link was forwarded to the wrong group. | Producer |
| **Erase their data** | Deletes the personal details for good, keeping only the anonymous count. For "please delete my data". | Anyone in Envision |

### Delete — the two grades

**Delete** is one button with two answers, because "take this off my list" and
"pretend this never happened" are different things:

| | **Delete** | **Delete permanently** |
| --- | --- | --- |
| **What it is for** | A duplicate, a mistake, someone who asked to come off | A test account, a bad entry, a number that must be free |
| **Their seat** | Released, and the next person on the waitlist is confirmed | Released, and the next person is confirmed |
| **Their details** | Kept — you can still see who they were and export them | Gone: registration, contact, check-ins, karaoke entries, game answers, feedback and links |
| **The night's numbers** | Unchanged | **They drop** — that seat stops being counted |
| **Can they register again?** | **Yes.** Their links stop working, but the number is free | **Yes.** There is nothing left to stop them |
| **Undo** | Put back on the list | **None.** Not by us, not by you |

Both ask you **why** first, and the crew log keeps the reason. **Delete
permanently** is available to an **Administrator** only; plain **Delete** is
available to anyone who can see this list.

**Erase their data** is separate from both, and is open to **anyone in
Envision** who can open Special Events — it is a privacy obligation, so nobody
should have to wait for an administrator to be found when a guest asks. It
deletes the personal details for good but **keeps the night's numbers true**,
and the log records that an erasure happened and why.

> **Delete permanently or Erase — which one?** If the seat must stop counting
> (a test account, a duplicate), use **Delete permanently**. If a real guest
> has asked you to remove their data but they really did come that night, use
> **Erase their data**.

**"Also keep them out — the door turns them away"** is the tick inside the
Delete menu. Use it when the crew has decided someone should not come back: it
releases the seat *and* makes the door refuse them if they try the form again.
Without the tick, Delete leaves the door open — which is what you want for a
test account or a typo.

> **One thing Delete permanently cannot reach.** If you have already run the
> **Hand-off**, a Reach lead or a congregation member created from this event
> belongs to that module, and it stays. Deleting them here would be this event
> reaching outside its own records. Check the Hand-off tab before you delete
> someone who was handed over — see §12 of this guide.

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

It opens on the two you need most: the **Plain link** and the **Check-in
poster QR**. Press **View more** to see the rest (WhatsApp, Instagram,
Facebook, TikTok, X, flyer, poster, SMS, church announcement, generic QR,
email), and **Show fewer** to tuck them away again. Tap any QR to enlarge it
before showing it on a screen.

Use the right one each time and the view count beside each code tells you
afterwards **which place actually worked**, so next year's push goes where the
people are. The counts are numbers only; nobody is tracked.

Registrations are attributed the same way, so Attendees can tell you that the
flyer brought 40 people and Instagram brought 4.

**✨ Suggest three** is the writing helper. Pick what you need — a tagline, the
page description, a blurb, an FAQ answer, an SMS, a card headline — add a line
of brief if you want ("mention that it ends by 9pm"), and you get three
options that respect the length limit.

Each purpose is briefed separately, so the helper knows the difference between
a six-word card headline and the paragraph a first-time guest reads on the
event page. **Portal descriptions** come back as three full paragraphs of
roughly 70–110 words (never more than 120), and each one deliberately takes a
different angle:

1. a warm invitation written to a guest who has never been to this church,
2. an activity-led one that leads with what will actually happen,
3. one about the people in the room and belonging.

The helper is also told to stay off the usual filler — "something for
everyone", "good vibes", "you don't want to miss it" — and not to repeat your
tagline as a sentence, because the page already shows it right above the
description. If two options still come back almost identical, the near-copy is
dropped rather than shown twice; you may then see two options instead of
three. Press **✨ Suggest three** again for a fresh set.

Under each description option you get its **word count**, and it turns red if
it is over 120 words so you know to trim before saving.

If the AI cuts a JSON answer short, the system tries once more with clearer
instructions and more output room. Nothing is ever applied for you: read them,
pick one, edit it. The system does not put the raw prompt or AI output in the
server logs, and no guest's name, phone number or email is ever sent to the
AI. If the button is missing, no AI key is configured on the server, and you
simply write it yourself.

**Markdown in the description.** The Description box on **Details** accepts
headings (`#`, `##`, `###`), **bold**, *italic*, `inline code`, links,
bullet lists (`-`), numbered lists (`1.`), quotes (`>`), dividers (`---`) and
ordinary paragraphs separated by a blank line. Raw HTML and images are not
allowed and are shown as plain text. The AI options come back as plain
paragraphs; add any formatting yourself.

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

The QR on the posters goes to `/e/<your-link>/in`, and on the night the main
button on the event page says **Check in** (then **Join the games** once they
are in). They type their phone number, see their name, and tap **Yes, check me
in**. A number we do not know gets the walk-in questions instead (below).

What happens next, in about a second:

- a **verse** appears, picked from the ones you approved, with their name in
  the prayer line;
- their **team** sweeps in in its colour, with their **player number**;
- the lobby screen says "Welcome Ada O. → Team Black".

If they have already checked in tonight **on that phone**, they just see their
card again. If they are already checked in but this is a **different** phone —
a borrowed one, or a new handset — the page opens **read-only**: they can see
their team and number but cannot play from it. To move the night to the new
phone, ask the desk for a **transfer code** and type it under **I have a code
from the desk** (on the check-in page, and on the games page of a read-only
phone).

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

**Check-in → Posters** makes the check-in QR in three sizes: **A4** for doors
and corridors, **A3** for the foyer, and **Screen** — a 16:9 image for a
lobby TV or a projector, for whenever there is no door to tape a poster to.
Press the button for A4 or A3, then use **Print PDF** — it is sized to the
paper exactly, so "Fit to page" cannot shrink it. Screen has no PDF, only the
PNG: put it up full-screen on the display itself. The address is also
printed in words underneath, for the camera that refuses to focus.

Print the paper sizes early. A poster is the only part of check-in that does
not need anything to be working.

---

## 15. Welcome verses

**Check-in → Welcome verses** is the list guests are greeted with. Each person
gets one, spread evenly across the list.

Add a reference — `Psalms 16:11` — and the King James text is fetched for you.
The optional prayer line must contain `{name}`, which becomes their first name:
"May this be your season, Ada."

**Suggest verses** asks the AI for references on a theme you type. It never
sees a guest. A picker opens with each suggestion's KJV text (fetched by the
server, never written by the AI), the reason it was picked, and its prayer
line. Tick the ones you want, fix a reference if the AI got one wrong — the
text re-fetches as you edit — adjust any prayer line, and press **Add**.
Verses you add are approved in the same moment and are marked
**suggested by AI** in the list; ones you leave ticked-off are dropped.
Nothing the AI offered is saved until you add it there.

Hand-added references still land as **needs review** and wait for the
**Approve** button. If no verse is approved, guests simply do not get one —
nothing breaks.

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

`/e/<your-link>/host`, crew sign-in. One person runs the night from here. It
is laid out in three columns, with the games in the widest one:

- **Left — Run of show, Karaoke, Announcement.** The programme with the next
  few items and their times: **Start** when a thing really begins and
  **Finish** when it really ends; everything after it moves, here and on
  everyone's phone. **Skip** drops an item, **Undo** takes back the last
  press. Karaoke shows who is on stage and who is next, with one button to
  send the next singer up (the full queue lives on the DJ screen). An
  announcement is up to 160 characters on every screen and phone for as many
  seconds as you choose — "The bus leaves at 9:30."
- **Middle — Games, and what is on the big screen.** See **Running the games**
  in section 22. Below it, the scenes (Standby, Welcome, Programme, Teams,
  Game, Leaderboard, Karaoke, Announcement, Break, Blank, Recap, Finale).
- **Right — Scores, Teams, Sound board, Screen links.** The team standings,
  **Give points or a penalty** (choose the team, the amount and what it is
  for), and **Recent points**, each with **Undo** (it asks why). Teams open to
  rename them or set a captain. The sound board plays a cue on the stage.
- **Blackout** — the red button at the top. Also the `B` key.

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

**Publishing it (the bar at the top).** A run of show is a working document:
items get cut, times move, somebody is still deciding who closes. So a
programme is **hidden from the event page until you publish it**. The bar at
the top of the tab always says which it is:

- **Amber, "Not published"** — guests see no programme at all on the page, and
  the page menu does not offer one. Press **Publish the programme**.
- **Green, "Published"** — the public items are on the page, in this order,
  with the times you chose. Press **Hide it again** at any point; it comes off
  the page immediately and nothing you typed is lost.

One exception, once only: an event whose programme was already on its page
before this update keeps it there, published, exactly as guests last saw it.
Nothing disappeared the day the switch arrived. Events created from now on
start hidden.

Publishing is only about the public page. **The stage screen, the host console
and the lobby always show the real run of show**, published or not, so the
crew is never left guessing. Items marked "not on the public page" under
**More** stay private either way.

**The programme poster.** Under the run of show, **Programme poster** turns
the list into one picture you can print or put on a screen. Pick the shape:

- **A4 poster** — 210 × 297 mm at 300 dpi, for the door, the noticeboard and
  the hand-outs.
- **16:9 screen** — 1920 × 1080, for the projector, the lobby TV or a slide.

Press **Download JPEG** (there is a **PNG** button beside it if you want the
sharper file for a printer). The poster is drawn in your browser, so nothing
is sent anywhere and it always matches what the page is showing.

What it puts on the poster, without being asked: the event's **hero image** as
the background (set it in **Brand**; without one you get the event's colours),
the title, date, doors and venue, every public item with its time, the
featured ones highlighted, the "times are approximate" note when that is what
guests are being told, the page address and a **QR code** to it — switch the
code off with the tick box if you would rather not have one.

It also fits itself. A short programme gets big, roomy rows with the one-line
descriptions; a long one shrinks the rows, then splits into two columns. It
will never cut an item off the bottom — if it has to choose, the type gets
smaller. Multi-day events get one poster per day; choose the day on the right.

**Importing one.** Most programmes arrive as a screenshot in a group chat.
Open **Programme → Import a programme**, then either:

1. choose **Upload screenshot or PDF** and select a **PNG, JPEG, WebP or PDF**;
   describe an image when asked, then press **Upload and read**; or
2. paste the schedule into **Paste programme text** and press **Read pasted
   text**.

The upload uses the same protected event asset process as the Assets tab: the
server checks the real file bytes, size and allowed type before keeping it as a
private programme source. You do not need to upload it somewhere else first.

A review table appears before anything is written. Correct the **title, kind,
event day, start time and minutes** in every row. A written range such as
`3:30 PM–4:30 PM Photo Booth and games` is read as 60 minutes, but it remains
editable. Choose **Add to programme** to append the reviewed rows, or
**Replace this day** and select the day you mean. **Apply** is the only button
that writes the rows; discarding the review leaves the programme unchanged.

If image/PDF reading is temporarily unavailable, the tab says so plainly, and
now also shows the reason it was given. Keep or paste the text and try again
later, or use **Add a row manually** — the night can always be built without
AI. Three reasons are worth knowing apart:

- *"The AI allowance for this hour is used up"* — nothing is broken. Wait, or
  an administrator raises the limits in Settings.
- Anything naming a **model** — a configuration fault, not a bad photo. Send
  an administrator to **Settings → Health** and read them the two AI model
  lines (§20).
- Anything about a **schema**, a **constraint** or **too many states** — the
  AI service refused the *shape* we asked for, before it ever looked at your
  screenshot. The system now notices that, asks again without the strict
  shape and checks the answer itself, so the review table still appears. If
  you ever see the message anyway, it means the second attempt failed too:
  tell an administrator, who has a one-line check to run (§20).

Reading a picture and reading pasted text use the same instructions, so when
one of them is genuinely broken the other is too. If **Read pasted text**
works and the upload does not, the problem really is the file: try a PNG or
JPEG screenshot rather than a photo of a screen, or a PDF under 15 MB.

On the night, the host presses Start and Finish on the console and the times
everywhere follow. If the night is running late, the portal says so.

---

## 18b-ii. Chapters — "The night, chapter by chapter"

**Chapters** tab in the Studio (under *Set up*).

The list of numbered blocks on the public page — **01 The Mic**, **02 The
Teams**, **03 The Games**, **04 The Night** — is content now, not something
only a developer can change. Every event starts with those four, already
written, already in that order.

**What you can do**

- **Rewrite one.** Press **Edit** and change the title and the blurb. Keep the
  blurb to 25 words or fewer; the editor counts as you type and tells you when
  you have gone past it, because a longer one starts to crowd the card on a
  phone.
- **Add one.** **+ Add a chapter** puts a new block at the end of the list —
  "The Food", "The Awards", "The Testimonies". Up to twelve in total.
- **Hide one** without losing the words: the **Show this chapter on the
  portal** switch. Hidden chapters stay here for next time and the numbering
  closes up on the public page.
- **Remove one** for good with **Remove**. Any image it used stays in Assets.
- **Reorder** with the **↑ / ↓** buttons on the left of each row. The order
  here is exactly the order guests scroll through, and the 01, 02, 03 numbers
  are recalculated for you — so moving *The Teams* above *The Games* is two
  clicks.
- **Start again** with **Restore defaults**, which puts the original four
  back and throws away everything else in the list.

**Icons, and what they do.** Every chapter carries one icon from a catalogue
of fourteen — microphone, card fan, team orbs, timeline, clock, music, trophy,
gift, camera, people, flame, cross, plate, sparks. They are not flat pictures:
each one brings its own animation that plays when the card scrolls into view
and then keeps going quietly. The picker shows the real artwork and says what
the motion does, so a chapter you invent an hour before doors moves like the
ones that shipped. Three are worth calling out:

- **The microphone** — the dashed halo ignites and turns slowly, a warm glow
  breathes behind the capsule, and the equaliser bars either side sing, each
  on its own clock so it never reads as a loop.
- **The team orbs** — four separate circles fly in from four different
  directions, converge until they overlap into one light, and then settle into
  an orbit that keeps breathing in and out. Different people, different
  places, one body. They are painted in your real team colours from the Teams
  tab.
- **The card fan** — the cards deal themselves out, the buzzer pings with a
  ring that travels outward, the score line draws itself, and the top card
  lifts as if somebody is about to play it.

**A picture behind the card.** Under **Edit** you can either upload an image
or reuse one already in the event's **Assets** kit. Uploading here is the same
pipeline as the Assets tab: the server checks what the file really is, strips
location data by re-encoding it, and makes smaller versions for phones.
Landscape, 1200 px wide or more, looks best.

A dark overlay is always laid over the picture, and the icon always sits above
it. That is deliberate and there is no switch to turn it off — it is what
keeps the icon, the animation and the words readable over a photograph, on a
phone in daylight and on a projector in a bright room. The **How the card will
look** preview beside the picker shows exactly what guests will get.

**Chapters and features are two different decisions.** At the bottom of the
tab there are switches for **Karaoke**, **Games** and **Teams**. Those say
whether the night actually runs that thing — they turn it on and off
everywhere in the Studio. Whether the matching chapter appears on the public
page is the chapter's own **Show this chapter on the portal** switch. So you
can advertise the games before the deck is built, or run karaoke quietly
without putting it on the page.

**Cloning last year's event** brings the chapters with it — the words, the
icons and the order — as part of the event's detail. The background pictures
do not come across, because an uploaded file belongs to the event it was
uploaded to; choose them again on the new event.

**A guest who has asked their phone for reduced motion** gets every chapter
as a still, finished frame — the words, the icon and the picture, with nothing
moving. Nothing in this tab can produce a page that only works with the
animation.

## 18b-iii. Music — the song behind the page

**Music** tab in the Studio.

Up to **five tracks** that play softly while people read the event page and
fill in the registration form. They play in the order you set, and when the
last one finishes the first starts again, so the page is never silent while
someone is on it.

### Three things to know before you start

These are the questions everybody asks, so they are answered first.

**1. The music does not start by itself, and it cannot.** Every browser —
Chrome, Safari, Firefox, the one inside WhatsApp — refuses to let a website
make a sound until the visitor has touched the page. There is no setting,
no trick and no paid service that changes this. What actually happens is
that the page loads silent, and the moment the guest taps or starts
scrolling, the music fades in over about a second and a half. In practice
almost everyone scrolls immediately, so almost everyone hears it — but the
very first instant of the page is always quiet.

**2. We cannot tell whether someone's phone is on silent.** No website can.
Browsers deliberately do not expose the ringer switch, the system volume, or
whether anything is actually audible — it would be a way to fingerprint and
track people. So a guest whose phone is on silent, or whose laptop is muted,
simply hears nothing, and we have no way to know or to warn them. That is
why the little speaker button is always on screen: it is how they find out
there was music at all.

**3. There is always a way to turn it off.** Two small buttons sit in the
bottom-left corner of the page — a speaker and a dial. No words, nothing
covering the content. The speaker mutes and unmutes; the dial slides out a
volume rail. **Whatever a guest chooses is remembered on their device**, so
someone who mutes it stays muted on their next visit and on every other page
of the event. This is not optional politeness: an accessibility rule
(WCAG 2.1) requires that any sound lasting more than a few seconds can be
stopped.

### Adding a track

Choose the audio file — **MP3 or M4A, up to 10 MB** — give it a title and an
artist, and tick the rights box. The upload button stays greyed out until
you tick it.

> **I have checked this track and it is clear for us to use publicly.**

That tick is recorded: **your name and the date are saved against the
track**, shown on the tab afterwards, and written to the event's audit log.
It is not a formality — it is the record that says a named person at the
church checked, which is exactly what we would need if anyone ever asked. A
track that has not been confirmed **will not play on the public page**, even
if it is sitting in the list.

Keep the file small. Around **96 kbps** is plenty for background music and
makes a three-minute track about 2 MB instead of 7 — which matters, because
your guests are paying for that data.

**BPM** is optional. Leave it blank unless you know it. It is only a
fallback for the little animation on the speaker button; on nearly every
phone the button pulses to the actual sound instead.

### Making it sound right

Press **Preview** on any track and it plays here at the exact volume the
portal will use. Do that before you publish. A track that sounds lovely in
headphones at full volume is often far too busy at 30% behind a form.

Under **Playback** you can:

- turn the music off for this event entirely — nothing is downloaded and no
  buttons appear;
- set the **starting volume**, which defaults to **30%**. That is the house
  setting and it is low on purpose: this is a bed for someone filling in a
  form, often in a room with other people. A guest who moves the slider
  keeps their own level on their own phone;
- **shuffle** the order instead of playing top to bottom.

### What else the page does on its own

- **Nothing is downloaded on a slow or metered connection.** On 2G, or when
  a phone has Data Saver switched on, the music is skipped completely — the
  same rule the hero video already follows.
- **A hidden tab goes quiet.** If a guest switches to another tab the music
  stops, and picks up again when they come back.
- **It gets out of the way of other sound.** If anything else on the page
  starts playing, the music drops right down and comes back afterwards.
- **It is only on the main event page** — never on check-in, the games page,
  or the private "my night" link, where a phone making noise would be a
  nuisance in a room that already has a PA.

### Removing a track

**Remove** takes it out of the playlist **and deletes the audio file**. Use
**Take out of rotation** instead if you only want to rest a track and keep
it for later.

Music does not appear on the **Assets** tab, and you cannot upload it from
there — it lives here, where the rights box is.

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
the Assets tab and choose it here. Long lists are fine: we read up to 400
songs from one list, and anything past that is left out of the preview rather
than guessed at.

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

**AI reads as three lines, not one.** *AI* only says a Gemini key is present.
**AI model (text)** and **AI model (image/PDF)** say which model each job
actually calls — the second is the one that reads a programme screenshot or a
PDF. Both show `gemini-2.5-flash` unless an administrator has deliberately
named another in `.env`; `MISSING` there explains an "unavailable" message on
the Programme tab even while *AI* says *ready*.

### When AI fails, for an administrator

Every AI call is recorded, successes and failures alike, with the status the
service gave us:

```sql
SELECT created_at, task, model, http_status, ok, error_code
FROM se_ai_requests ORDER BY id DESC LIMIT 10;
```

`http_status` `200` is a working call. A real number such as `400`, `403` or
`429` means the service answered and refused; `0` means the request never got
there at all (no network, DNS, a firewall). The server's error log holds one
line per failed attempt — task, model, status, attempt number and the
service's own words. It never contains the prompt, anybody's details or the
key.

To test the connection by hand, from the repository (never from a browser —
the folder is closed to the web):

```bash
php bin/se_ai_probe.php              # the text call and the image call
php bin/se_ai_probe.php --text-only
php bin/se_ai_probe.php --file=/path/to/the-screenshot.png
```

It prints the raw HTTP status, the curl error number and Google's own
message, plus the length of the API key — never the key itself. It sends a
fixed sample programme, never real event data.

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

**Can I change the questions at the bottom of the public page?**
Yes — Details → **Good to know**. The six an event starts with are only a
starting point: rewrite them, reorder them, add your own, or delete the lot and
the section disappears.

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

## 22. Games

Six kinds of game, all prepared in **Studio → Games** and run from the host
console:

| Game | How it plays | Questions it uses |
| ---- | ------------ | ----------------- |
| **Live Quiz** | Everyone answers on their own phone; right and fast scores most. | Multiple choice, Emoji puzzle, Finish the verse |
| **Bible Trivia** | One answer per team, locked in by the captain while teammates suggest. | Multiple choice, Finish the verse |
| **Buzzer** | First team to buzz answers out loud; you judge it. | Question and answer, Multiple choice, Emoji puzzle, Finish the verse |
| **Who Am I?** | Clues one at a time; the earlier the right answer, the more it scores. | Who am I? clues |
| **Bible Charades** | One player acts out phrases only their phone shows. | Charades phrase |
| **Family Feud** | Teams guess the most popular answers from the guests' survey. | Feud survey question |

### Setting up (Studio → Games)

**Lineup.** The games of the night in running order. The quickest start is
**Add the Chara starter pack**: six ready games with questions you can edit
or replace. Or **Build my own** / **+ Add a game** and choose a type. Move a
game earlier or later with the arrows on its card. **Edit** opens it:

- **Name on screen** and **Weight in the championship** (2 doubles that game's
  team points);
- the settings in plain units — answer time and countdown in seconds, points,
  passes — with sensible defaults already filled in;
- its questions: **In this game** on the left (reorder with ↑ ↓, remove with
  ✕) and **Available** on the right (tap to add, or **Add all**). Only
  **approved** questions the game can play are offered, so a multiple-choice
  game never picks up an open question.

**Question banks.** Questions live in banks, one kind per bank (create one
with **New bank**). **Add a question** shows the right form for the kind —
four answers with a tick for the right one, five clues, a phrase, a survey
question. For **Finish the verse**, type the reference and press **Fetch the
KJV text**: the text comes from the KJV, never from AI, and the slider chooses
where the room takes over. Questions you type are approved straight away;
drafts (from AI) need **Approve**, or **Approve all** for the lot. With AI
switched on, **✨ Draft with AI** suggests up to fifteen; tick the ones to keep
and they arrive as drafts for you to read and approve. A question that is in
a game cannot be deleted until you take it out of the game.

**Family Feud boards.** Guests answer the survey questions on their phones
before the night (**Play ahead**). When the answers are in, **Edit board** →
**Build from N answers**: with enough answers AI groups spellings and synonyms
("Lions", "the lion"); with few, the exact groups are offered. Tidy the
labels and points, then **Save and approve**. The host cannot open a board
that is not approved.

### Running the games (host console)

The middle column lists the lineup. **Start** a game (starting another pauses
the one before; **Resume** carries on). While a game is live, the panel shows
one big next step at a time, and the answer the host must not read out is in
amber, marked **Only you see this**.

- **Live Quiz / Bible Trivia.** **Next question ▶** → **Show the question ▶**
  (phones get a short countdown, then the answer time) → watch the answers
  come in and how they are spreading → **Reveal the answer** (or **Stop
  answers** first). Points go on as it is revealed, unless you switched that
  off in the game's settings, in which case press **Give out the points**.
- **Buzzer / Who Am I?** **Open the buzzers ▶** (or **Show the first clue ▶**)
  → the first buzz appears with the player's name and team → **✓ Right** or
  **✕ Wrong**. A wrong team is out for that question (or clue); the buzzer
  reopens for the others, or press **Next clue**. If nobody gets it, **Reveal
  the answer**.
- **Bible Charades.** **Next turn ▶** → choose the acting team and type the
  presenter's player number (leave it empty for someone on that team whose
  phone is active) → **Choose the presenter** → **Start the 60-second timer ▶**
  (or whatever turn length the game has) → **✓ Got it** for each phrase, **Pass** (twice a turn by default), **End the turn**.
  The phrase shows only on the presenter's phone, and on the console only if
  you tick **Show the phrases on this console too**.
- **Family Feud.** **Next board ▶** → choose the two face-off teams (and
  player numbers, or leave them random) → **Start the face-off ▶** → tap each
  answer as it is said → **<Team> plays** → **✕ Strike** for a wrong guess;
  three strikes give the other team one steal (**✓ Stolen** / **✕ Not on the
  board**) → **Bank** → **Turn over the rest of the board**.

When the last question is played the panel says so and offers **Finish**.
**Void this round** (it asks why) removes every point the round gave; the next
round then replays the same question, which is how a mistake is corrected.
Points are never edited in place — awards, penalties and undos are all rows
in the score history. **Leaderboard on screen** and **Run the finale 🏆** sit
at the bottom of the panel.

### On the phones

Guests open `/e/<your-link>/play` (**Join the games** on the event page once
they have checked in). The page follows the stage by itself: a countdown,
then the answer tiles; **Locked in** after they answer; ✓ or ✕ and their
points at the reveal. In Trivia the captain's tiles show how many teammates
suggested each answer, and teammates see what the captain locked in. Only
the two face-off players get a buzzer in the Feud. The charades phrase shows
only on the presenter's phone, with **Hide the phrase** for nosy neighbours.
Between games the page shows the team scores and the **Play ahead** survey.

The phones and the stage read the same published state, so a slow website
never changes a scoring window, and nothing public ever carries an answer
before the reveal, a name, or a charades phrase.

### On the stage

**Game** scene: the question with the four answer tiles and a countdown; at
the reveal, the right answer lights up with how the room answered; the buzz
winner's name in their team colour; the Who Am I? clues; the charades actor
and timer; the Feud board with its strikes and bank. **Leaderboard** shows
the teams and the MVP; **Finale** counts up from last place to the champions,
then the MVP.

### Rehearse it

**Studio → Games → Turn test mode on** (or **Live → Rehearsal**). Everything
scored while it is on is marked TEST; **Reset rehearsal** clears the test
rounds, answers, buzzes, survey answers and TEST points, and leaves real rows
alone. One week before the event:

1. Turn **Test mode** on. Confirm the red ribbon on the stage and the console.
2. Put the lobby on its TV and the stage on the projector. Press **Start** on
   the stage once.
3. Use at least 20 phones on the venue Wi-Fi. Register five new people; check
   in twenty using the phone, the desk and a walk-in; move one guest to a new
   phone with a desk code.
4. Run one round of every game, three karaoke singers, the leaderboard and the
   finale. Watch the **Screens** dot stay green, and check the charades phrase
   appears only on the presenter's phone.
5. Give an award, undo it, and void a round. Add the points up by hand and
   compare with the leaderboard.
6. Press **Reset rehearsal** and check the test rows are gone and real ones
   remain. Write the fix list, repeat what failed, and turn test mode off (it
   also switches itself off fifteen minutes before doors open).

Before a big night, the quiz load profile is in
`tests/special_events/load/quiz.k6.js`.

## 23. After the event (PR7)

The next morning, check **Messages** for the `thank_you` run. It goes once to
everyone who checked in and opens their private manage link at **My Night**.
The card contains their team result, points, MVP badge when applicable and
performed karaoke song. **Feedback** asks for NPS (0–10), a favourite moment,
one word, a comment, visit interest and future-event consent.

Open **Insights** for the totals: registered and checked in (with the
show-up rate), walk-ins, church members there, how many played on their
phones, karaoke songs, the feedback score (NPS) and the champions; then the
journey from page view to the door, how people heard, gender, check-ins by
team, the hand-off, and how many handed-off guests were back at a service
within 30, 60 and 90 days. Every chart is a labelled list of numbers, so it
reads the same on a phone and to a screen reader. Nobody is named on this
page. Events in a series get a table comparing the editions. **PDF report** is aggregate and contains no phone numbers or email
addresses; its AI narrative uses aggregate statistics only and falls back to
a fixed narrative when AI is off. **Excel** is operational data: only users
with PII permission receive unmasked contact fields, and every export is
audited.

Within 72 hours, open **Hand-off** with the Follow-up liaison. The top shows
how many are ready (and how many go to each team) and how many the rules hold
back, and why: already a member, did not agree to follow-up, opted out, did
not come, phone number not usable, already handed off. By default,
checked-in consenting guests who asked for a visit go to **Embrace**
(first-timer care) and the rest go to **Reach** (follow-up calls). In
**Review**, any ready person can be sent to the other team or **held back**;
the rules themselves cannot be overridden — the server refuses to hand off
anyone who did not consent, even if asked to. Only tick **Also send people
who registered but did not come** deliberately. Press **Push** once the list
is right. **Earlier runs** lists each push with its counts, and pushing again
safely picks up late opt-ins without handing anyone off twice.

A manager may archive at least 24 hours after the end. Archived events are
frozen except for exports, insights and hand-off. A manager, or a producer in
the same series, can reclaim the short slug for the next edition; the old
edition receives an edition/year slug. Cron removes expired devices and
tokens and anonymises non-consenting guest details after the configured
retention period. A direct erasure request remains available in Attendees.

After-event checklist: recap scene; attendee export; confirm thank-you run;
review feedback after 48 hours; download the PDF; run hand-off with Follow-up;
notify leadership; archive after at least seven days.
