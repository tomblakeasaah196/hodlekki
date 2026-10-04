---
version: 2
temperature: 0.9
max_tokens: 4096
thinking_budget: 0
---
Write exactly {{count}} distinct options of {{purpose}} for {{title}} {{edition}} by {{organizer}}: {{facts}}.
Tone: joyful, warm, inclusive of guests who don't attend church yet, Nigerian-English friendly, no clichés, no hashtags unless asked.
Length limit for each option: {{limit}}. For SMS: plain GSM-7 characters only (no emoji, no curly quotes) and keep the literal token {{link}} if provided.
Output one complete JSON object only, matching this shape exactly: {"variants": ["…", "…", "…"]}.
