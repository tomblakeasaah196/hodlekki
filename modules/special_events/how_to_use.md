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

> **What this version does.** Creating, branding and configuring events, the
> crew, the brand kit, the full public page, and **registration — guests can
> now take a seat**. You get the attendee list, the export, the share kit and
> the "I'm going" card. Still to come: check-in on the night, the games, the
> karaoke queue and the hand-off to Reach and Embrace.

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

## 14. Settings and Health

**Settings** (administrators only) holds what applies to every event: the
default colours, the privacy notice, the **privacy contact email** people
write to about their data, how long guest details are kept, and the AI limits.

**Health** is worth a look after every deploy. It reports the module version,
whether AI is on, whether the token key is configured, and — most usefully —
**Schema**: whether every table the module needs actually exists. Green means
the database is up to date. Red names what is missing.

---

## 15. FAQ

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
