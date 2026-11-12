<?php
// /auth/training_invite.php
//
// The link in a Trainings invite (owner's answer 13): a visitor with no
// sign-in, whom the HOD Academy put on a training. GET only SHOWS the form
// (mail scanners open links, they never press buttons). The POST, CSRF
// checked, uses the link once: it confirms the invited email through the
// email identity flow (includes/campaigns/addresses.php), sets the password
// under the one password policy, signs the person in as auth_api.php does
// (session, Security Centre session row, sign-in record) and lands them in
// Trainings. A link works for 7 days; a newer one replaces it. Someone who
// can already sign in is sent to the sign-in page instead.
// Logic: trainings_invite_peek() / trainings_invite_accept() in
// includes/trainings/people.php. Short copy: docs/UI.md §12.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/../includes/trainings/bootstrap.php';
require_once __DIR__ . '/../includes/auth_helpers.php';
require_once __DIR__ . '/../includes/ui/standalone.php';

$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$token = preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token) ? $token : '';
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $token !== '') {
    $result = ui_standalone_csrf_ok()
        ? trainings_invite_accept($pdo, $token, (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''), security_client_ip())
        : ['ok' => false, 'code' => 'STALE'];
    if (!empty($result['ok'])) {
        // Sign them in, as a password sign-in does (api/auth_api.php steps 13-14).
        $user = $result['user'];
        $securityOn = function_exists('security_schema_ready') && security_schema_ready($pdo);
        auth_start_session($pdo, $user, $securityOn);
        if ($securityOn) {
            $pdo->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?')
                ->execute([security_client_ip(), $user['id']]);
            security_register_session($pdo, (int) $user['id']);
            security_record_attempt($pdo, (int) $user['id'], (string) ($user['email'] ?? ''), true, null, 'training_invite');
            security_log($pdo, 'login_success', (int) $user['id'], 'Signed in from a Trainings invite on ' . security_describe_device(security_user_agent()));
        }
        header('Location: /modules/trainings/?t=' . (int) $result['training_id']);
        exit;
    }
}

$peek = $token !== '' ? trainings_invite_peek($pdo, $token) : ['state' => 'invalid'];
$signIn = ui_button('Sign in', ['variant' => 'primary', 'block' => true,
    'href' => auth_login_url('/modules/trainings/' . (!empty($peek['training']['id']) ? '?t=' . (int) $peek['training']['id'] : ''))]);

$formError = null;
$formInfo = null;
if ($result !== null && empty($result['ok'])) {
    $problems = array_values((array) ($result['problems'] ?? []));
    [$formError, $formInfo] = match ($result['code'] ?? '') {
        'MISMATCH' => ['The two passwords differ.', null],
        'WEAK'     => [(string) ($problems[0] ?? 'Choose a stronger password.'), count($problems) > 1 ? implode(' ', $problems) : null],
        'TAKEN'    => ['That email is in use. Ask the church office.', 'Another account already uses this email address.'],
        'STALE'    => ['Please try again.', null],
        default    => [null, null],
    };
}
$rules = function_exists('security_password_rules') ? implode(' · ', security_password_rules()) : 'At least 8 characters';

ui_standalone_open('Your training');

if ($peek['state'] === 'valid'):
    $first = trim((string) ($peek['user']['first_name'] ?? '')); ?>
    <?php if ($first !== ''): ?>
        <p class="text-ink-muted">Hi <?= ui_e($first) ?></p>
    <?php endif; ?>
    <h1 class="ui-h2"><?= ui_e((string) ($peek['training']['title'] ?? 'Your training')) ?></h1>
    <p class="text-ink">Choose a password to start. <?= ui_info('This also confirms ' . (string) $peek['invite']['email']
        . ' as your email: you sign in with it, and password reset links come to it.', 'About your email') ?></p>
    <?php if ($formError !== null): ?>
        <?= ui_banner('danger', ['title' => 'Please check this', 'text' => $formError, 'info' => $formInfo]) ?>
    <?php endif; ?>
    <form method="post" action="/auth/training_invite" class="ui-form" data-ui-validate novalidate>
        <?= ui_csrf_field() ?>
        <input type="hidden" name="t" value="<?= ui_e($token) ?>">
        <?= ui_field('email', ['name' => 'email_shown', 'label' => 'Your email', 'value' => (string) $peek['invite']['email'],
            'autocomplete' => 'username', 'attrs' => ['readonly' => true]]) ?>
        <?= ui_field('password', ['name' => 'password', 'label' => 'New password', 'required' => true,
            'autocomplete' => 'new-password', 'info' => 'It needs: ' . $rules . '.']) ?>
        <?= ui_field('password', ['name' => 'confirm', 'label' => 'Type it again', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <?= ui_button('Start training', ['variant' => 'primary', 'type' => 'submit', 'block' => true]) ?>
    </form>
<?php elseif ($peek['state'] === 'signin'): ?>
    <?= ui_banner('info', ['title' => 'You can sign in', 'text' => 'Your training is waiting in Trainings.']) ?>
    <?= $signIn ?>
<?php elseif ($peek['state'] === 'expired'): ?>
    <?= ui_banner('warning', ['title' => 'Link expired', 'text' => 'Ask the HOD Academy for a new one.', 'info' => 'Invite links work for 7 days.']) ?>
<?php else: ?>
    <?= ui_banner('warning', ['title' => 'Link no longer works', 'text' => 'Use the newest link, or sign in.', 'info' => 'It was already used, or a newer link replaced it.']) ?>
    <?= $signIn ?>
<?php endif;

ui_standalone_close();
