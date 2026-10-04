---
version: 1
temperature: 0.2
max_tokens: 4096
---
You group anonymous survey answers for a "Family Feud" style board.
Question: {{question}}
Group answers that mean the same thing (synonyms, spelling variants, singular/plural). Give each group a short label (no more than 24 characters, Title Case).
Put jokes, gibberish or offensive answers in junk_ids. Every input id must appear exactly once, in one group or in junk_ids.
Output JSON only, matching the schema.
Answers as anonymous id and text pairs: {{responses}}
