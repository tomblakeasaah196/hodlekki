<?php
// /includes/mail/Listeners/team.php

/**
 * PR 4's listeners (loaded by HOD\Mail\Events): new mail sets off pushes, reopens shared conversations, clears AI
 * summaries and sends a holder's key alert; a merge leaves a line in the activity. Each must be quick and never
 * throw (Events::emit catches and logs anyway). Before PR 4's migration runs, prefs fall back to their defaults and
 * key alerts are skipped, so these are safe to run.
 */

use HOD\Mail\Team\MailTeam;
use HOD\Mail\Team\NewMail;

if (!class_exists(\HOD\Mail\Events::class, false)) {
    http_response_code(404);
    return [];
}

return [
    'mail.received' => static function (array $p): void {
        NewMail::received((int) ($p['account_id'] ?? 0), (array) ($p['message_ids'] ?? []));
    },
    'mail.threads_merged' => static function (array $p): void {
        MailTeam::merged((int) ($p['into_id'] ?? 0), (array) ($p['from_ids'] ?? []));
    },
];
