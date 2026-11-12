<?php
// /api/mail_team_api.php

/**
 * Teamwork, alert choices and AI (docs/MAIL.md §7e): JSON for the team bar and the summary card on the Mail page
 * (assets/ui/js/pages/mail-team.js), the composer's AI panel and send guard (mail-compose.js), and My mail settings
 * (mail-settings.js).
 *
 *   GET  state&thread=            the team bar (status, assignee, who is here) without touching presence
 *   GET  summary&thread=          the AI summary card
 *   POST (JSON, X-CSRF-Token)
 *        presence {thread, state: viewing|replying|stop|leave}   renews the mark; answers the team bar
 *        assign {thread, to}        status {thread, status}       activity {thread}
 *        guard {thread, since}      a teammate's reply since the composer opened, or null
 *        summarise {thread}         draft {composer: {...}, instruction}   (AI, never sends)
 *        prefs_push {box, push}     prefs_general {summary?, personal?}
 *
 * Every call goes through MailAccess (Team\MailTeam, Ai\Ai). Errors: {status: 'error', message, field?}.
 */

require_once '../includes/db.php';
require_once '../includes/mail/bootstrap.php';

use HOD\Mail\Ai\Summariser;
use HOD\Mail\Ai\Writer;
use HOD\Mail\MailError;
use HOD\Mail\Store;
use HOD\Mail\Team\MailPresence;
use HOD\Mail\Team\MailTeam;
use HOD\Mail\Team\Prefs;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sign in again to continue.']);
    exit;
}
if (!mail_ready()) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Mail is being set up. Try again after the next update.']);
    exit;
}

function team_out(array $body): never
{
    echo json_encode(['status' => 'success'] + $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $thread = (int) ($_GET['thread'] ?? 0);
        session_write_close();
        match ((string) ($_GET['action'] ?? '')) {
            'state' => team_out(['data' => MailTeam::state($uid, $thread)]),
            'summary' => team_out(['data' => Summariser::state($uid, $thread)]),
            default => throw MailError::notFound('Unknown request.'),
        };
    }

    csrf_require();
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('That request could not be read. Reload the page.');
    }
    session_write_close();
    $thread = (int) ($in['thread'] ?? 0);

    switch ((string) ($in['action'] ?? '')) {
        case 'presence':
            $state = (string) ($in['state'] ?? 'viewing');
            match ($state) {
                'stop' => MailPresence::stopReplying($uid, $thread),
                'leave' => MailPresence::leave($uid, $thread),
                default => MailPresence::touch($uid, $thread, $state),
            };
            team_out($state === 'leave' ? [] : ['data' => MailTeam::state($uid, $thread)]);

        case 'assign':
            $changed = MailTeam::assign($uid, $thread, isset($in['to']) && (int) $in['to'] > 0 ? (int) $in['to'] : null);
            team_out(['changed' => $changed, 'data' => MailTeam::state($uid, $thread)]);

        case 'status':
            $changed = MailTeam::setStatus($uid, $thread, (string) ($in['status'] ?? ''));
            team_out(['changed' => $changed, 'data' => MailTeam::state($uid, $thread)]);

        case 'activity':
            team_out(['data' => MailTeam::activity($uid, $thread)]);

        case 'guard':
            $since = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($in['since'] ?? '')) ? (string) $in['since'] : null;
            team_out(['replied' => MailPresence::repliedSince($uid, $thread, $since), 'now' => \HOD\Mail\Clock::sql()]);

        case 'summarise':
            team_out(['data' => Summariser::summarise($uid, $thread)]);

        case 'draft':
            team_out(['data' => Writer::draft($uid, is_array($in['composer'] ?? null) ? $in['composer'] : [], (string) ($in['instruction'] ?? ''))]);

        case 'prefs_push':
            if (!mail_team_ready()) {
                throw MailError::invalid('Alert choices are being set up. Try again after the next update.');
            }
            $account = Store::account((int) ($in['box'] ?? 0));
            if (!$account || !mail_can('read', $account)) {
                throw MailError::notFound();
            }
            Prefs::setPush($uid, (int) $account['id'], (string) ($in['push'] ?? ''));
            team_out(['message' => 'Saved.']);

        case 'prefs_general':
            if (!mail_team_ready()) {
                throw MailError::invalid('Alert choices are being set up. Try again after the next update.');
            }
            $values = [];
            if (array_key_exists('summary', $in)) {
                $values['summary'] = (bool) $in['summary'];
            }
            if (is_array($in['personal'] ?? null)) {
                $values['personal'] = array_map('boolval', $in['personal']);
            }
            Prefs::setGeneral($uid, $values);
            team_out(['message' => 'Saved.', 'data' => Prefs::general($uid)]);
    }
    throw MailError::notFound('Unknown request.');
} catch (Throwable $e) {
    mail_json_error($e);
}
