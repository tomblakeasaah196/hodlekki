<?php
// /api/mail_compose_api.php

/**
 * Writing (docs/MAIL.md §7d): JSON for the composer (includes/mail/mounts/composer.php, assets/ui/js/pages/
 * mail-compose.js), the Outbox page and My signature.
 *
 *   GET  suggest&q=                                   address suggestions while typing
 *   POST upload (multipart: outboxId, file)           one file per request
 *   POST (JSON, X-CSRF-Token)
 *        open {mode: new|reply|reply_all|forward|draft, accountId?, messageId?, outboxId?, to?}
 *        save {outboxId, fields, serverCopy?}        as the person types
 *        send {outboxId, fields}                     queued for 10 s (Undo), then `deliver`
 *        schedule {outboxId, fields, quick|at}       tomorrow / next Monday 08:00, or a chosen time
 *        deliver {outboxId}                          send now if due (the composer, at the end of the 10 s)
 *        undo | open_again | close | discard | remove_attachment {outboxId, key?}
 *        sig_preview {phone, mobile, accountId?, church?}   the signature with these values, nothing saved
 *        sig_numbers {phone, mobile}                 a person's own numbers (the only part they edit)
 *        sig_church {name, tagline, address, phone, website, logo_url, show_logo}   mail admins
 *        sig_church_reset                            mail admins
 *
 * Every outbox action checks the row is the person's and that they may still send from its mailbox
 * (Outbox::own → MailAccess). Sending takes the domain's shared hourly budget (MailTransport). Errors:
 * {status: 'error', message, field?} with 403 / 404 / 413 / 422, or 502 when the mail server is the problem.
 */

require_once '../includes/db.php';
require_once '../includes/mail/bootstrap.php';

use HOD\Mail\Clock;
use HOD\Mail\Compose\ComposeConfig;
use HOD\Mail\Compose\MessageBuilder;
use HOD\Mail\Compose\Outbox;
use HOD\Mail\Compose\OutboxSender;
use HOD\Mail\Compose\Recipients;
use HOD\Mail\Compose\Signatures;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\People;
use HOD\Mail\Read\Format;
use HOD\Mail\Store;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sign in again to continue.']);
    exit;
}
if (!mail_ready() || !mail_compose_ready()) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Writing mail is being set up. Try again after the next update.']);
    exit;
}

function compose_out(array $body): never
{
    echo json_encode(['status' => 'success'] + $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** The draft as the composer shows it */
function compose_state(array $row, int $userId): array
{
    $p = $row['payload'];
    $account = Store::account($row['account_id']);
    $accounts = array_map(fn ($a) => [
        'id' => $a['id'], 'email' => $a['email'], 'name' => $a['display_name'], 'shared' => Store::isShared($a),
    ], MailAccess::accounts($userId, 'send'));

    return [
        'id' => $row['id'],
        'kind' => $row['kind'],
        'status' => $row['status'],
        'accountId' => $row['account_id'],
        'shared' => $account ? Store::isShared($account) : false,
        'ai' => $account !== null && class_exists(\HOD\Mail\Ai\Ai::class) && \HOD\Mail\Ai\Ai::available($userId, $account),
        'openedAt' => Clock::sql(),   // the send guard asks for teammates' replies after this
        'threadId' => $row['thread_id'],
        'messageId' => $row['reply_to_message_id'],
        'title' => ['reply' => 'Reply', 'reply_all' => 'Reply all', 'forward' => 'Forward'][$row['kind']] ?? 'New message',
        'to' => $p['to'] ?? [], 'cc' => $p['cc'] ?? [], 'bcc' => $p['bcc'] ?? [],
        'subject' => (string) ($p['subject'] ?? ''),
        'html' => (string) ($p['html'] ?? ''),          // cleaned by HtmlCleaner when saved
        'signature' => (string) ($p['signature'] ?? 'full'),
        'quote' => (bool) ($p['quote'] ?? false),
        'quoteHtml' => $p['quote_html'] ?? null,        // built by MessageBuilder::quote from escaped text
        'attachments' => compose_files($p),
        'savedAt' => !empty($p['touched']) ? compose_time($row['updated_at']) : null,
        'lastError' => $row['status'] === 'draft' ? null : $row['last_error'],
        'signatures' => $account ? ['full' => Signatures::html($userId, $account, 'full'), 'slim' => Signatures::html($userId, $account, 'slim')] : [],
        'accounts' => $accounts,
    ];
}

function compose_files(array $p): array
{
    return array_map(fn ($a) => [
        'key' => $a['key'], 'name' => $a['name'], 'size' => (int) $a['size'], 'sizeLabel' => Format::size((int) $a['size']),
        'source' => $a['source'], 'mime' => $a['mime'] ?? null,
    ], $p['attachments'] ?? []);
}

function compose_time(?string $sql): ?string
{
    $d = Clock::parse($sql);

    return $d ? $d->format('H:i') : null;
}

/** Save the request's fields, if any; a refused address never stops a save (it is reported) */
function compose_save(int $userId, string $id, array $in): array
{
    $given = is_array($in['fields'] ?? null) ? $in['fields'] : [];
    $fields = array_intersect_key($given, array_flip(['account_id', 'to', 'cc', 'bcc', 'subject', 'html', 'signature', 'quote']));
    if (!$fields) {
        return ['row' => Outbox::make()->own($userId, $id, ['draft']), 'invalid' => []];
    }

    return Outbox::make()->save($userId, $id, $fields);
}

/** "today at 08:00", "tomorrow at 08:00", "Mon 7 Dec 2026 at 08:00" */
function compose_when(DateTimeImmutable $at): string
{
    $days = (int) Clock::now()->setTime(0, 0)->diff($at->setTime(0, 0))->format('%r%a');

    return match (true) {
        $days === 0 => 'today at ' . $at->format('H:i'),
        $days === 1 => 'tomorrow at ' . $at->format('H:i'),
        default => $at->format('D') . ' ' . MessageBuilder::when($at),
    };
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$outbox = Outbox::make();

try {
    if ($method !== 'POST') {
        if (($_GET['action'] ?? '') === 'suggest') {
            compose_out(['data' => Recipients::suggest($uid, mb_substr((string) ($_GET['q'] ?? ''), 0, 100))]);
        }
        throw MailError::notFound('Unknown request.');
    }

    csrf_require();
    session_write_close();   // the mail server can take a moment: don't hold the person's other requests

    // A file (multipart: the composer sends one request per file)
    if (($_POST['action'] ?? '') === 'upload') {
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? MailError::tooLarge('That file is too large to attach. Share a link instead.')
                : MailError::field('attachments', 'That file did not arrive. Try again.');
        }
        $attachment = $outbox->addUpload($uid, (string) ($_POST['outboxId'] ?? ''), (string) $file['name'], (string) file_get_contents($file['tmp_name']));
        compose_out(['attachment' => compose_files(['attachments' => [$attachment]])[0]]);
    }

    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw MailError::invalid('That request could not be read. Reload the page.');
    }
    $action = (string) ($in['action'] ?? '');
    $id = (string) ($in['outboxId'] ?? '');

    switch ($action) {
        case 'open':
            $mode = (string) ($in['mode'] ?? 'new');
            $row = match ($mode) {
                'draft' => $id !== ''
                    ? $outbox->open($uid, $id)
                    : $outbox->fromServerDraft($uid, (int) ($in['messageId'] ?? 0)),
                'reply', 'reply_all', 'forward' => $outbox->start($uid, $mode, null, (int) ($in['messageId'] ?? 0)),
                default => $outbox->start($uid, 'new', (int) ($in['accountId'] ?? 0) ?: null, null, isset($in['to']) ? (string) $in['to'] : null),
            };
            compose_out(['draft' => compose_state($row, $uid)]);

        case 'save':
            $r = compose_save($uid, $id, $in);
            // The copy in the server's Drafts, at most once a minute while typing
            $last = Clock::parse($r['row']['payload']['server_draft_at'] ?? null);
            if (!empty($in['serverCopy']) || !$last || Clock::now()->getTimestamp() - $last->getTimestamp() >= (int) ComposeConfig::get('server_draft_seconds', 60)) {
                $outbox->saveServerDraft($uid, $id);
            }
            $changed = isset($in['fields']['account_id']);
            compose_out(['invalid' => $r['invalid'], 'savedAt' => compose_time(Clock::sql())] + ($changed ? ['draft' => compose_state(Outbox::row($id), $uid)] : []));

        case 'send':
            $r = compose_save($uid, $id, $in);
            $row = $outbox->queue($uid, $id);
            compose_out(['outboxId' => $id, 'accountId' => $row['account_id'], 'threadId' => $row['thread_id'],
                'seconds' => (int) ComposeConfig::get('undo_seconds', 10), 'invalid' => $r['invalid']]);

        case 'schedule':
            $at = match ((string) ($in['quick'] ?? '')) {
                'tomorrow' => Clock::now()->modify('+1 day')->setTime(8, 0),
                'monday' => Clock::now()->modify('next monday')->setTime(8, 0),
                default => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) ($in['at'] ?? ''))
                    ? (DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $in['at'], Clock::zone()) ?: null)
                    : null,
            };
            if (!$at) {
                throw MailError::field('send_at', 'Pick a day and a time.');
            }
            compose_save($uid, $id, $in);
            $row = $outbox->queue($uid, $id, $at);
            compose_out(['outboxId' => $id, 'accountId' => $row['account_id'], 'threadId' => $row['thread_id'],
                'message' => 'Scheduled for ' . compose_when($at) . '.']);

        case 'deliver':
            $row = Outbox::row($id);
            if (!$row || $row['user_id'] !== $uid) {
                throw MailError::notFound();
            }
            $row = OutboxSender::make()->send($id, 3);
            $state = match ($row['status']) {
                'sent' => ['state' => 'sent', 'message' => 'Message sent.'],
                'failed' => ['state' => 'failed', 'message' => 'Not sent: ' . $row['last_error']],
                'queued' => ['state' => 'retry', 'message' => 'Not gone yet: ' . ($row['last_error'] ?: 'the mail server is busy.') . ' The ERP tries again by itself.'],
                default => ['state' => 'going', 'message' => 'Sending…'],
            };
            compose_out(['outboxId' => $id, 'accountId' => $row['account_id'], 'threadId' => $row['thread_id']] + $state);

        case 'undo':
            if (!$outbox->undo($uid, $id)) {
                compose_out(['undone' => false, 'message' => 'Too late to undo: it has gone.']);
            }
            compose_out(['undone' => true, 'draft' => compose_state(Outbox::row($id), $uid)]);

        case 'open_again':
            compose_out(['draft' => compose_state($outbox->open($uid, $id), $uid)]);

        case 'close':
            $row = Outbox::row($id);
            if (!$row || $row['user_id'] !== $uid || $row['status'] !== 'draft') {
                compose_out(['kept' => false]);
            }
            $r = compose_save($uid, $id, $in);
            if (!$outbox->worthKeeping($r['row'])) {
                $outbox->discard($uid, $id);
                compose_out(['kept' => false]);
            }
            $outbox->saveServerDraft($uid, $id);
            compose_out(['kept' => true, 'message' => 'Draft saved.']);

        case 'discard':
            if (!$outbox->discard($uid, $id)) {
                compose_out(['discarded' => false, 'message' => 'Too late: it has gone.']);
            }
            compose_out(['discarded' => true, 'message' => 'Draft deleted.']);

        case 'remove_attachment':
            $outbox->removeAttachment($uid, $id, (string) ($in['key'] ?? ''));
            compose_out([]);

        // ---- My signature ------------------------------------------------------------------------------------
        case 'sig_preview':
            $account = null;
            foreach (MailAccess::accounts($uid, 'send') as $a) {
                if ((int) ($in['accountId'] ?? 0) === 0 || $a['id'] === (int) $in['accountId']) {
                    $account = Store::account((int) $a['id']);
                    break;
                }
            }
            $person = Signatures::person($uid, $account);
            $numbers = ['phone' => null, 'mobile' => null];
            try {
                $numbers = Signatures::checkNumbers(['phone' => (string) ($in['phone'] ?? ''), 'mobile' => (string) ($in['mobile'] ?? '')]);
            } catch (MailError) {
                // the preview shows what can be shown; saving says what is wrong
            }
            $person['phone'] = $numbers['phone'];
            $person['mobile'] = $numbers['mobile'];
            $church = Signatures::church();
            if (mail_can('admin') && is_array($in['church'] ?? null)) {
                try {
                    $church = array_merge($church, Signatures::checkChurch($in['church']));
                } catch (MailError) {
                    // as above
                }
            }
            $email = $account['email'] ?? (People::find($uid)['email'] ?? '');
            compose_out([
                'full' => Signatures::render($person, $church, $email, 'full'),
                'slim' => Signatures::render($person, $church, $email, 'slim'),
            ]);

        case 'sig_numbers':
            Signatures::setNumbers($uid, (string) ($in['phone'] ?? ''), (string) ($in['mobile'] ?? ''));
            compose_out(['message' => 'Numbers saved.']);

        case 'sig_church':
            if (!mail_can('admin')) {
                throw MailError::denied('Only mail admins change the church part.');
            }
            Signatures::setChurch($in, $uid);
            compose_out(['message' => 'Church part saved.']);

        case 'sig_church_reset':
            if (!mail_can('admin')) {
                throw MailError::denied('Only mail admins change the church part.');
            }
            Signatures::resetChurch();
            compose_out(['message' => 'Church part reset.']);
    }
    throw MailError::notFound('Unknown request.');
} catch (Throwable $e) {
    mail_json_error($e);
}
