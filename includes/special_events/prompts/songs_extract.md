---
version: 2
temperature: 0.1
max_tokens: 4096
---
Extract a karaoke song list from the input (text, photo, screenshot or PDF).
Return at most 400 songs. Return each song once with its title and artist exactly as written (fix only obvious OCR errors).
Every song must contain title, artist and duration. Use null for artist when it is not written. Use duration in "m:ss" form only when written; otherwise use null. Do not guess durations. Do not invent songs.
Output JSON only, matching the schema.
