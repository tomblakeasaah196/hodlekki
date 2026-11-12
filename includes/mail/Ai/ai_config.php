<?php
// /includes/mail/Ai/ai_config.php

/** AI in Mail (PR 4), read through HOD\Mail\Ai\Ai::config(). The key is GEMINI_API_KEY in .env, never here. */

return [
    'model'           => 'gemini-2.5-flash',
    'endpoint'        => 'https://generativelanguage.googleapis.com/v1beta/models/',
    'timeout'         => 25,
    'connect_timeout' => 5,
    // Per person per hour
    'limits' => [
        'write'   => 30,
        'summary' => 30,
    ],
    // What the model reads: the last messages of a conversation, quotes stripped, each cut to this length
    'context_messages'  => 30,
    'chars_per_message' => 4000,
    // Bodies not yet in the ERP's copy are read from the server, at most this many per request
    'live_bodies'       => 5,
    'max_instruction'   => 2000,
    'max_draft'         => 8000,
];
