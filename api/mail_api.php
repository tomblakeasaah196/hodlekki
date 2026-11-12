<?php
// /api/mail_api.php

/**
 * Mail for everyone who works from a mailbox (docs/MAIL.md). JSON for the Mail page (modules/mail/index.php and
 * assets/ui/js/pages/mail.js). Lists and the reader come back as HTML pieces drawn by the same PHP as the first
 * screen (HOD\Mail\Read\Partial), already escaped; a sender's HTML is only ever shown inside a sandboxed frame
 * loaded from api/mail_view.php, which has its own CSP.
 *
 *   GET  nav                                           {unread}: the badge on Mail in both shells
 *   GET  list&box=&folder=&q=&show=&page=&open=         the conversation list
 *   GET  counts&box=&folder=                            folder and mailbox counts and a change stamp (polled)
 *   GET  older&box=&folder=&before=                     mail older than 12 months, read from the server
 *   GET  server_search&box=&folder=&q=                  the server's whole folder, any date
 *   POST refresh&box=                                   read the server now for that mailbox (any level)
 *   POST thread | message | trust | live | live_trust | act | undo | empty   (JSON body, X-CSRF-Token)
 *        Opening a conversation marks it read for everyone on a shared mailbox, so it is a POST.
 *
 * Every call checks the mailbox's level through mail_can() / MailAccess. Errors: {status: 'error', message} with
 * the MailError's status (403 / 404 / 413 / 422), or 502 when the mail server is the problem.
 */

require_once '../includes/db.php';
require_once '../includes/mail/bootstrap.php';

use HOD\Mail\Clock;
use HOD\Mail\Imap\ImapException;
use HOD\Mail\MailError;
use HOD\Mail\MailFetch;
use HOD\Mail\MailNav;
use HOD\Mail\Read\Actions;
use HOD\Mail\Read\ImageTrust;
use HOD\Mail\Read\Inbox;
use HOD\Mail\Read\Partial;
use HOD\Mail\Read\Reader;
use HOD\Mail\Read\Screen;
use HOD\Mail\Store;
use HOD\Mail\Sync\MailSync;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sign in again to continue.']);
    exit;
}
if (!mail_ready()) {
    echo json_encode(['status' => 'success', 'data' => ['unread' => 0, 'ready' => false]]);
    exit;
}

function mail_api_out(array $data, ?string $message = null): never
{
    echo json_encode(['status' => 'success', 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** The page's place from the request; the mailbox must exist and be readable */
function mail_api_inbox(int $userId, array $in): Inbox
{
    $inbox = Inbox::open($userId, [
        'box' => (int) ($in['box'] ?? 0),
        'folder' => (string) ($in['folder'] ?? ''),
        'thread' => (int) ($in['thread'] ?? 0),
    ]);
    if (!$inbox->account || ($inbox->account['id'] !== (int) ($in['box'] ?? 0) && !$inbox->thread)) {
        throw MailError::notFound('You no longer have access to that mailbox.');
    }

    return $inbox;
}

function mail_api_folder(Inbox $inbox): array
{
    return $inbox->folder ?? throw MailError::notFound('That folder is no longer on the server.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'POST') {
        $action = (string) ($_GET['action'] ?? 'list');
        if ($action === 'nav') {
            echo json_encode(['status' => 'success', 'data' => ['unread' => MailNav::unread($uid)]]);
            exit;
        }
        $inbox = mail_api_inbox($uid, $_GET);
        if (in_array($action, ['older', 'server_search'], true)) {
            // the mail server can take a few seconds: don't hold the person's other requests meanwhile
            session_write_close();
        }
        if ($action === 'list') {
            mail_api_out(Screen::list($inbox, (string) ($_GET['q'] ?? ''), (string) ($_GET['show'] ?? 'all'), max(1, (int) ($_GET['page'] ?? 1)), (int) ($_GET['open'] ?? 0)));
        }
        if ($action === 'counts') {
            mail_api_out($inbox->counts());
        }
        if ($action === 'older') {
            $folder = mail_api_folder($inbox);
            $before = (int) ($_GET['before'] ?? 0) ?: null;
            $page = MailFetch::make()->older($uid, $folder['id'], $before, 30);
            $items = array_map([Reader::class, 'liveRow'], $page['items']);
            mail_api_out([
                'html' => Partial::render('live_list', ['inbox' => $inbox, 'mode' => 'older', 'items' => $items, 'next' => $page['next'], 'count' => count($items)]),
                'append' => $before !== null,
            ]);
        }
        if ($action === 'server_search') {
            $folder = mail_api_folder($inbox);
            $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
            if ($q === '') {
                throw MailError::invalid('Type what to search for.');
            }
            $items = array_map([Reader::class, 'liveRow'], MailFetch::make()->searchServer($uid, $folder['id'], $q, 50));
            mail_api_out([
                'html' => Partial::render('live_list', ['inbox' => $inbox, 'mode' => 'server', 'items' => $items, 'next' => null, 'count' => count($items)]),
                'append' => false,
            ]);
        }
        throw MailError::notFound('Unknown request.');
    }

    // ---- POST -------------------------------------------------------------------------------------------------
    csrf_require();
    $in = $_POST;
    if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) {
            throw MailError::invalid('That request could not be read. Reload the page.');
        }
    }
    $action = (string) ($in['action'] ?? '');

    if ($action === 'refresh') {
        // A fresh read of the mailbox now (the cron does it every minute anyway); any level may ask
        $account = Store::account((int) ($in['box'] ?? 0)) ?? throw MailError::notFound();
        if (!mail_can('read', $account)) {
            throw MailError::denied();
        }
        $age = Clock::ageSeconds($account['last_synced_at']);
        // the session is released first: reading the server must not hold the person's other requests
        session_write_close();
        $result = ($age !== null && $age < 20) ? ['status' => 'fresh'] : MailSync::make()->account((int) $account['id'], 20.0);
        MailNav::forget();
        if ($result['status'] === 'error') {
            throw new MailError('The mail server did not answer. Try again in a minute.', null, 502);
        }
        $added = (int) ($result['added'] ?? 0);
        $message = match ($result['status']) {
            'busy' => 'Already checking. New mail arrives in a moment.',
            'skipped' => 'This mailbox needs a new password. Ask a mail admin.',
            default => $added > 0 ? $added . ' new message' . ($added === 1 ? '.' : 's.') : 'Up to date.',
        };
        $counts = [];
        if (!empty($in['folder'])) {
            $inbox = mail_api_inbox($uid, $in);
            $counts = ['counts' => $inbox->counts()];
        }
        mail_api_out(['sync' => $result['status'], 'added' => $added, 'skipped' => $result['status'] === 'skipped'] + $counts, $message);
    }

    $inbox = mail_api_inbox($uid, $in);
    $searching = trim((string) ($in['q'] ?? '')) !== '';
    $ints = fn ($v) => array_values(array_filter(array_map('intval', is_array($v) ? array_slice($v, 0, 500) : []), fn ($n) => $n > 0));

    switch ($action) {
        case 'thread':
            $thread = $inbox->thread ?? throw MailError::notFound('That conversation is no longer in this mailbox.');
            $open = array_key_exists('open', $in) && is_array($in['open']) ? $ints($in['open']) : null;
            // quiet: the page drawing again what is on screen (new mail arrived), without marking anything read
            $out = Screen::thread($inbox, $thread, $open, $ints($in['images'] ?? []), $searching, empty($in['quiet']), !empty($in['latest']));
            if ($out['changed']) {
                MailNav::forget();
            }
            mail_api_out($out + ($out['changed'] ? ['counts' => $inbox->counts()] : []));

        case 'message':
            $out = Screen::message($inbox, (int) ($in['message'] ?? 0), !empty($in['images']));
            if ($out['changed']) {
                MailNav::forget();
            }
            mail_api_out($out + ($out['changed'] ? ['counts' => $inbox->counts()] : []));

        case 'trust':
            $m = Store::message((int) ($in['message'] ?? 0));
            if (!$m || $m['account_id'] !== $inbox->account['id']) {
                throw MailError::notFound();
            }
            $saved = ImageTrust::trust($uid, (string) $m['from_email']);
            mail_api_out(Screen::message($inbox, $m['id'], true, false), $saved ? 'Pictures from ' . $m['from_email'] . ' will always show.' : null);

        case 'live':
        case 'live_trust':
            $folder = mail_api_folder($inbox);
            $liveUid = (int) ($in['uid'] ?? 0);
            session_write_close();
            $message = null;
            if ($action === 'live_trust') {
                $from = MailFetch::make()->message($uid, $folder['id'], $liveUid)['from_email'] ?? '';
                if ($from && ImageTrust::trust($uid, $from)) {
                    $message = 'Pictures from ' . $from . ' will always show.';
                }
            }
            try {
                $item = Reader::live($uid, $folder, $liveUid, !empty($in['images']) || $action === 'live_trust', Store::folderLabel($folder));
            } catch (ImapException $e) {
                error_log('Mail: live message: ' . $e->getMessage());
                $item = null;
            }
            mail_api_out(['html' => Partial::render('live', ['inbox' => $inbox, 'item' => $item, 'uid' => $liveUid])], $message);

        case 'act':
            $thread = $inbox->thread ?? throw MailError::notFound('That conversation is no longer in this mailbox.');
            $result = Actions::run($inbox, $thread, (string) ($in['do'] ?? ''), $searching);
            MailNav::forget();
            mail_api_out(['undo' => $result['undo'], 'left' => $result['left'], 'threadId' => $thread['id'], 'counts' => $inbox->counts()], $result['message']);

        case 'undo':
            $message = Actions::undo($inbox, $ints($in['ids'] ?? []), (int) ($in['to'] ?? 0));
            MailNav::forget();
            mail_api_out(['counts' => $inbox->counts()], $message);

        case 'empty':
            $message = Actions::emptyFolder($inbox, mail_api_folder($inbox));
            mail_api_out(['counts' => $inbox->counts()], $message);
    }
    throw MailError::notFound('Unknown request.');
} catch (Throwable $e) {
    mail_json_error($e);
}
