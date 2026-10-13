# The Bible — how to use

**Who can open it:** every signed-in member. It is in the sidebar under
Command Center, and `Ctrl K` → "bible" (or "notes", "reading plan") finds it.

Everything you write here — highlights, notes, journal entries, plans — is
**private to you**. Nobody else can see it, including pastors and
administrators.

---

## 1. Reading

- **Choose a passage:** tap the book button at the top left (it shows where
  you are, e.g. *John 3*). Pick a book and a chapter, or type a reference
  into the box — `Rom 8:28`, `1 jn 4`, `Psalm 23`.
- **Turn the page:** swipe left or right on a phone, use the arrows at the
  sides on a computer, or press ← and →. The *Previous* and *Next* buttons
  at the end of each chapter do the same.
- **Where you stopped:** the Bible opens at the chapter and verse you were
  last reading, on any device you sign in on.
- **Translation:** tap the small `KJV` button. The **King James Version** is
  always there. **NKJV, NIV and Amplified** appear once the church office has
  switched them on (see section 7).
- **Text & display (`Aa`):** Paper, White or Night page; text size; three
  typefaces; line spacing; paragraphs or one verse per line; and switches
  for the words of Jesus in red, verse numbers and section headings. Your
  choice is remembered.

On a phone the top bar slides away while you read down and comes back as soon
as you scroll up.

---

## 2. Highlighting exact words

1. Select the words — drag on a computer; on a phone, tap a verse to select
   all of it, then drag the handles to trim it to the words you want (or
   press and hold on a word).
2. A bar appears. Tap a colour: yellow, green, blue, pink or purple.

- Highlight over an existing highlight in another colour and the old one is
  trimmed around the new one.
- To remove a highlight, select it and tap the **eraser**.
- Highlights belong to the translation you made them in (the words differ
  between translations).

The same bar also lets you **copy** the words with their reference, **share**
them as an image, and **listen** from that verse.

---

## 3. Notes and the study journal

- **Note on a verse or phrase:** select the words and tap the **pencil**.
  Write your note, add tags (e.g. `faith`, `prayer`) and Save. A small pencil
  marker appears after the verse, and the words are underlined. Tap the
  marker to read or edit the note. A note belongs to the passage, so its
  marker shows in every translation.
- **Journal entry:** in **Notes**, tap **Journal entry**. Give it a title
  (e.g. *Sunday: Living by faith*), write freely, and list the passages it is
  about, separated by `;` — `Romans 8:28; Hebrews 11:1-6`. A book marker then
  appears on those verses while you read.
- **Finding things:** the Notes tab lists everything newest first. Search
  your own words, filter by tag or by book, or switch to *Verse notes*,
  *Journal* or *Highlights*.
- **PDF:** the **PDF** button downloads what you are looking at (respecting
  the tag and book filters) as a clean document to print or keep. A single
  note has its own PDF button in the editor.

---

## 4. Search

Open **Search** and type words — `love one another` — or put words in quotes
for an exact phrase: `"peace be still"`. Typing a reference such as
`John 3:16` gives you a **Go to** card that jumps straight there.

- Choose *Whole Bible*, *Old Testament*, *New Testament* or the book you are
  reading. After a search, the chips under the box show how many verses each
  book has; tap one to search only that book.
- Searching the KJV is instant and unlimited. Searching NKJV, NIV or AMP uses
  the church's API.Bible allowance, so each member can do up to 100 such
  searches a day.

---

## 5. Listening (voice reader)

Tap the **headphones**. The Bible reads aloud from the verse at the top of the
screen, the verse being read glows, and the page follows along.

- **Player:** play/pause, previous/next verse, speed (tap `1.0×` to change),
  and stop.
- **Sleep timer:** tap the moon — off, end of chapter, 15, 30 or 60 minutes.
- **Carry on:** by default it moves into the next chapter on its own; switch
  that off under the gear icon.
- **Repeat to memorise:** gear icon → choose the verses, how many times, and
  the pause between. Great for memory verses.
- **Voices:** the reader uses the voices already on your phone or computer —
  it is free and uses no data. Choose one under the gear icon and tap *Try*.
  Phones can download more voices in their text-to-speech settings.

Keep the screen on while listening: most phones stop reading aloud when the
screen locks. The Bible asks the browser to keep the screen awake while it is
reading.

---

## 6. Reading plans and streaks

Open **Plans** and start one:

| Plan | Length |
| ---- | ------ |
| The Bible in a Year | 365 days, Genesis to Revelation |
| Old & New Together | 365 days, an Old Testament and a New Testament/Psalms reading each day |
| New Testament in 90 Days | 90 days |
| The Gospels in 30 Days | 30 days |
| Psalms & Proverbs in a Month | 31 days |

Days are balanced by length, so no day is suddenly three times longer than
the last. **A day ticks itself off** when you finish all its chapters — just
read normally. You can also tick a day by hand (*Mark day done*), see every
day under *All days*, and stop a plan at any time.

**Finishing a chapter** — reaching the end after spending a fair time on it,
listening to it to the end, or tapping **Mark as read** — counts towards your
**streak**: the number of days in a row you have read. The flame at the top
shows it; the Plans tab shows your week, your best run and how many chapters
you have read.

---

## 7. For the church office: switching on NKJV, NIV and AMP

The KJV is public domain and is built in. NKJV (Thomas Nelson), NIV (Biblica)
and AMP (The Lockman Foundation) are copyrighted, so they come through
**API.Bible**, the American Bible Society's licensed service.

1. Create an account at **api.bible**, create an app, and choose the free
   **Starter** plan (non-commercial; public-domain Bibles plus up to three
   licensed ones). Pick **NKJV, NIV and AMP** as the licensed Bibles.
2. Put the key in `.env` on the server: `BIBLE_API_KEY="…"`.
3. Open The Bible → `KJV` button → **API.Bible status (admins)** → **Refresh
   catalogue**. Each translation should say *ready*. One that says *not found
   on this key* is not enabled on the API.Bible account — fix it there, or pin
   its Bible id in `.env` (`BIBLE_API_ID_NIV`, `BIBLE_API_ID_NKJV`,
   `BIBLE_API_ID_AMP`).

What the module does to keep within the licence:

- Licensed chapters are kept on our server for **at most 29 days**
  (API.Bible requires a refresh at least every 30). An older copy is never
  shown; if it cannot be refreshed it is deleted and the member is offered
  the KJV.
- The **copyright line** API.Bible sends is printed under every chapter.
- Every time a licensed chapter is shown, the member's browser reports it to
  API.Bible's **Fair Use Management System (FUMS)**, as their terms require.
  It sends a random device number and session number — nothing that
  identifies the member.
- The words of licensed translations are **not stored** with anyone's
  highlights or notes (only the KJV's are), and a PDF export prints at most
  400 licensed verses, with the publisher's notice.
- **Monthly allowance:** the Starter plan allows about 5,000 calls a month.
  The status card shows how many have been used. At 4,500
  (`BIBLE_API_MONTHLY_CAP`) the module stops calling: chapters already cached
  keep working and members are pointed to the KJV until the next month.

If the church's site ever starts earning money directly (ads, paid content),
the Starter plan no longer applies — talk to API.Bible about the Pro plan.
