<?php
// /includes/hymnal/prompts/extract_song.php
//
// The one AI task in this module: read a song someone pasted or photographed
// and lay it out as fields a person can check.
//
// It is a READING task, not a writing one. The prompt is written to make the
// model transcribe and nothing else, because the failure that would matter here
// is a model that helpfully invents a fourth verse. Hence "notes": the model is
// asked to say what it could not read, and the Studio shows that above the form
// so the person checking knows where to look.
//
// The schema is in Gemini's responseSchema dialect already, so nothing converts
// it on the way out. It is kept deliberately small: Gemini compiles the schema
// into a constrained decoder and rejects anything with too many states, and a
// bounded array of objects with several optional properties is the usual
// trigger (AGENTS.md). So the only array of objects has three required
// properties and no maxItems, and optional fields are `required` + `nullable`
// rather than left out.

return [
    'temperature' => 0.1,
    'max_tokens'  => 8192,

    'system' => <<<'PROMPT'
        You transcribe church songs for a Nigerian church's song library. You are
        reading, not writing. Accuracy matters more than completeness.

        Return the song exactly as it appears in the input:

        - Keep the original words, spelling and punctuation. Do not modernise,
          correct, complete or improve anything. Keep Yoruba, Igbo, Hausa or
          Pidgin text exactly as written, with its diacritics.
        - Split the song into sections. Use kind "verse" for a verse, "chorus"
          for a chorus or refrain, and otherwise "prechorus", "bridge", "tag",
          "ending", "intro", "interlude", "coda" or "vamp". Label them as the
          source does ("Verse 1", "Chorus").
        - One array entry per printed line. Do not re-wrap lines.
        - If a chorus is printed once but marked as repeating, include it once.
        - If the input has chords, also return the whole song in ChordPro form
          in "chordpro", with chords in square brackets before the syllable they
          fall on. If there are no chords, leave chordpro null.
        - Fill title, writer, song_key, time_sig, themes and scripture ONLY from
          what the input actually says. If the input does not say, use null. Do
          not infer a writer from the style, and do not guess a key from the
          words.

        NEVER invent a verse, a line, a word, a chord or an attribution. If part
        of the input is cut off, blurred or unreadable, transcribe what you can
        and say what is missing in "notes". Use "notes" for anything a person
        checking your work should look at: unreadable text, a verse that seems
        to continue past the page, handwriting you were unsure of, or a section
        whose label you had to choose yourself. If you are confident about
        everything, set notes to null.

        If the input is not a song at all, return a title of "" and empty
        sections, and say so in "notes".
        PROMPT,

    'schema' => [
        'type'     => 'OBJECT',
        'required' => ['title', 'writer', 'song_key', 'time_sig', 'themes', 'scripture', 'sections', 'chordpro', 'notes'],
        'properties' => [
            'title'     => ['type' => 'STRING'],
            'writer'    => ['type' => 'STRING', 'nullable' => true],
            'song_key'  => ['type' => 'STRING', 'nullable' => true],
            'time_sig'  => ['type' => 'STRING', 'nullable' => true],
            'themes'    => ['type' => 'STRING', 'nullable' => true],
            'scripture' => ['type' => 'STRING', 'nullable' => true],
            'chordpro'  => ['type' => 'STRING', 'nullable' => true],
            'notes'     => ['type' => 'STRING', 'nullable' => true],
            'sections'  => [
                'type'  => 'ARRAY',
                'items' => [
                    'type'     => 'OBJECT',
                    'required' => ['label', 'kind', 'lines'],
                    'properties' => [
                        'label' => ['type' => 'STRING'],
                        'kind'  => ['type' => 'STRING'],
                        'lines' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    ],
                ],
            ],
        ],
    ],
];
