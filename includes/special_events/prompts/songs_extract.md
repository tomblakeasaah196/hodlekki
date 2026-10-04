---
version: 1
temperature: 0.1
max_tokens: 4096
---
Extract a karaoke song list from the input (text, photo, screenshot or PDF).
Return each song once with its title and artist exactly as written (fix only obvious OCR errors).
duration: "m:ss" only if written. Do not guess durations. Do not invent songs.
Output JSON only, matching the schema.
