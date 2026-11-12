<?php
// /includes/mail/Compose/compose_config.php

/**
 * Writing (PR 3): the composer's and the outbox's settings, read through HOD\Mail\Compose\ComposeConfig::get().
 * PR 1's includes/mail/mail_config.php stays PR 1's. Nothing secret here.
 */

return [
    // "Undo": a message waits this long before it goes
    'undo_seconds' => 10,
    // One message goes to at most this many people (To, Cc and Bcc together)
    'max_recipients' => 50,
    // Files on one message, all together (bytes). Base64 adds a third on the way, and many mail servers (Gmail among
    // them) refuse messages over 25 MB, so 18 MB of files is the most that arrives everywhere.
    'max_attachment_bytes' => 18 * 1024 * 1024,
    // Uploaded files are kept in the database in pieces of this size (mail_compose_files)
    'chunk_bytes' => 512 * 1024,
    // A temporary failure (the server busy, the hour's sending budget used) is tried again after these many minutes,
    // then the message is "not sent"
    'retry_minutes' => [1, 2, 5, 15, 30, 60],
    // A row left in "sending" this long (a run that stopped) is marked not sent; never sent again by itself
    'stuck_minutes' => 15,
    // Rows sent per run of cron/mail_minute.php
    'batch' => 25,
    // While a person types, the copy in the server's Drafts is refreshed at most this often (seconds)
    'server_draft_seconds' => 60,
    // Drafts opened and never written in are removed after this many hours
    'untouched_hours' => 24,
    // Address suggestions shown while typing
    'suggestions' => 8,
    // The signature's logo as recipients' mail apps load it: an absolute address on the church's own site (mail apps
    // never show SVG, so a PNG; 228 px for a 76 px picture). Mail admins may set another https address.
    'logo_path' => '/assets/images/mail/hod-signature-logo.png',
];
