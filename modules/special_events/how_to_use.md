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
> crew, the brand kit, and the public page with its first screen. **Guests
> cannot register yet** — registration arrives in the next release, together
> with the full animated portal. Everything you set up now is waiting for it.

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

Everything about how people get a seat. Set it now; it starts working when
registration opens in the next release.

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

`hodlc.lpc.cm/e/your-link` shows the name, tagline, date, venue, your
description and a button that changes with the moment: *Registration opens
soon* before, *Check in* on the night, *Relive the night* afterwards.

It also has:

- **`/privacy`** — what you collect and why, in plain words.
- **`/calendar.ics`** — "Add to calendar" for any phone or laptop.
- **`/e/`** — a hub listing published public events. An event marked
  **Unlisted** works by link but never appears there.

**A draft is invisible.** Someone guessing the address gets "Nothing here" —
not "you are not allowed", which would tell them it exists. To show a draft to
someone outside the crew, copy the **Draft preview** link from Overview.

---

## 11. Settings and Health

**Settings** (administrators only) holds what applies to every event: the
default colours, the privacy notice, the **privacy contact email** people
write to about their data, how long guest details are kept, and the AI limits.

**Health** is worth a look after every deploy. It reports the module version,
whether AI is on, whether the token key is configured, and — most usefully —
**Schema**: whether every table the module needs actually exists. Green means
the database is up to date. Red names what is missing.

---

## 12. FAQ

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
In the next release. Everything you configure now is waiting for it.
