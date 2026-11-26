<?php
// tests/passkeys_test.php — includes/passkeys.php against a software
// authenticator (keys made here with openssl), on SQLite. No browser, no network.
//   php tests/passkeys_test.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
if (!extension_loaded('pdo_sqlite') || !extension_loaded('openssl')) {
    echo "SKIP: pdo_sqlite and openssl are needed.\n";
    exit(0);
}

$_ENV['SITE_URL'] = 'https://hodlekki.org';
$_SERVER['HTTP_HOST'] = 'hodlekki.org';
require_once __DIR__ . '/../includes/site_url.php';
require_once __DIR__ . '/../includes/passkeys.php';

$pass = 0;
$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}
function eq(string $label, $want, $got): void
{
    ok($label, $want === $got, 'want ' . var_export($want, true) . ', got ' . var_export($got, true));
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE auth_passkeys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    credential_id TEXT NOT NULL UNIQUE, user_handle TEXT NOT NULL, public_key TEXT NOT NULL, algorithm INTEGER NOT NULL,
    sign_count INTEGER NOT NULL DEFAULT 0, transports TEXT NULL, backed_up INTEGER NOT NULL DEFAULT 0, label TEXT NOT NULL,
    created_at TEXT NOT NULL, created_ip TEXT NULL, last_used_at TEXT NULL)');
$_SESSION = [];

/** A software authenticator: one key pair, one credential id, a counter. */
final class FakeAuthenticator
{
    public $key;
    public string $credId;
    public int $counter = 0;
    public string $handle = '';

    public function __construct(public int $alg = -7, public bool $synced = false)
    {
        $cfg = $alg === -7
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        // Windows PHP finds no openssl.cnf by itself; it ships one in extras/ssl.
        $cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (getenv('OPENSSL_CONF') === false && is_file($cnf)) {
            $cfg['config'] = $cnf;
        }
        $this->key = openssl_pkey_new($cfg);
        $this->credId = random_bytes(32);
    }

    public function spki(): string
    {
        $pem = openssl_pkey_get_details($this->key)['key'];
        return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    }

    public function authData(string $rpId, bool $uv = true, bool $attested = false, int $counter = 0): string
    {
        $flags = 0x01 | ($uv ? 0x04 : 0) | ($this->synced ? 0x18 : 0) | ($attested ? 0x40 : 0);
        $data = hash('sha256', $rpId, true) . chr($flags) . pack('N', $counter);
        if ($attested) {
            $data .= str_repeat("\0", 16) . pack('n', strlen($this->credId)) . $this->credId . "\xa5\x01\x02"; // + a stub COSE key
        }
        return $data;
    }

    public static function client(string $type, string $challenge, string $origin = 'https://hodlekki.org'): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
    }

    /** What the browser sends to register_finish. */
    public function create(array $options, array $o = []): array
    {
        $this->handle = $options['user']['id'];
        $client = self::client('webauthn.create', $o['challenge'] ?? $options['challenge'], $o['origin'] ?? 'https://hodlekki.org');
        return [
            'id' => passkey_b64u_encode($this->credId),
            'clientDataJSON' => passkey_b64u_encode($client),
            'authenticatorData' => passkey_b64u_encode($this->authData($o['rp'] ?? 'hodlekki.org', $o['uv'] ?? true, true)),
            'publicKey' => passkey_b64u_encode($this->spki()),
            'publicKeyAlgorithm' => $this->alg,
            'transports' => ['internal', 'hybrid'],
        ];
    }

    /** What the browser sends to login_finish. */
    public function get(array $options, array $o = []): array
    {
        $this->counter += $o['step'] ?? 1;
        $counter = $this->synced ? 0 : ($o['counter'] ?? $this->counter);
        $client = self::client('webauthn.get', $o['challenge'] ?? $options['challenge'], $o['origin'] ?? 'https://hodlekki.org');
        $auth = $this->authData($o['rp'] ?? 'hodlekki.org', $o['uv'] ?? true, false, $counter);
        openssl_sign($auth . hash('sha256', $client, true), $sig, $this->key, OPENSSL_ALGO_SHA256);
        if (!empty($o['tamper'])) {
            $sig[10] = chr(ord($sig[10]) ^ 0xff);
        }
        return [
            'id' => passkey_b64u_encode($this->credId),
            'clientDataJSON' => passkey_b64u_encode($client),
            'authenticatorData' => passkey_b64u_encode($auth),
            'signature' => passkey_b64u_encode($sig),
            'userHandle' => $o['handle'] ?? $this->handle,
        ];
    }
}

$ada = ['id' => 7, 'first_name' => 'Ada', 'last_name' => 'Obi', 'real_email' => 'ada@gmail.com'];

echo "relying party and origins\n";
eq('the RP id is the site', 'hodlekki.org', passkey_rp_id('hodlekki.org'));
eq('…also from a subdomain', 'hodlekki.org', passkey_rp_id('connect.hodlekki.org'));
eq('a local copy uses its own host', 'localhost', passkey_rp_id('localhost:8080'));
ok('https on the site is ours', passkey_origin_ok('https://hodlekki.org', 'hodlekki.org'));
ok('…and on a subdomain', passkey_origin_ok('https://connect.hodlekki.org', 'hodlekki.org'));
ok('another site is not', !passkey_origin_ok('https://hodlekki.org.evil.com', 'hodlekki.org'));
ok('plain http is not (except localhost)', !passkey_origin_ok('http://hodlekki.org', 'hodlekki.org'));
ok('localhost over http is, for development', passkey_origin_ok('http://localhost:8080', 'localhost'));
eq('base64url round trip', "\x00\xffhi\xfb", passkey_b64u_decode(passkey_b64u_encode("\x00\xffhi\xfb")));
eq('junk does not decode', null, passkey_b64u_decode('ab+c/'));

echo "registration\n";
$phone = new FakeAuthenticator(-7);
$opt = passkey_register_options($pdo, $ada);
eq('asks for this phone\'s own lock, a discoverable passkey, and a verified user',
    ['platform', 'required', 'required'],
    [$opt['authenticatorSelection']['authenticatorAttachment'], $opt['authenticatorSelection']['residentKey'], $opt['authenticatorSelection']['userVerification']]);
eq('ES256 and RS256', [-7, -257], array_column($opt['pubKeyCredParams'], 'alg'));
eq('the account email names it on the phone', 'ada@gmail.com', $opt['user']['name']);
$res = passkey_register_finish($pdo, $ada, $phone->create($opt), 'Mozilla/5.0 (Linux; Android 14)', '1.2.3.4');
ok('a good passkey is saved', $res['ok'] === true, json_encode($res));
eq('one row, with its public key', 1, (int) $pdo->query('SELECT COUNT(*) FROM auth_passkeys WHERE user_id = 7')->fetchColumn());
$again = passkey_register_finish($pdo, $ada, $phone->create($opt), '', '');
eq('the same challenge cannot be used twice', 'NOT_ACCEPTED', $again['code'] ?? null);

$opt2 = passkey_register_options($pdo, $ada);
eq('the next options leave out the passkey already made', [passkey_b64u_encode($phone->credId)], array_column($opt2['excludeCredentials'], 'id'));
eq('…and keep the same user handle', $opt['user']['id'], $opt2['user']['id']);
$other = new FakeAuthenticator(-7);
eq('another site\'s page cannot make one', 'NOT_ACCEPTED', passkey_register_finish($pdo, $ada, $other->create($opt2, ['origin' => 'https://evil.com']), '', '')['code'] ?? null);
$opt3 = passkey_register_options($pdo, $ada);
eq('without the fingerprint/face/PIN, no', 'NOT_ACCEPTED', passkey_register_finish($pdo, $ada, $other->create($opt3, ['uv' => false]), '', '')['code'] ?? null);
$opt4 = passkey_register_options($pdo, $ada);
eq('made for another site (RP id), no', 'NOT_ACCEPTED', passkey_register_finish($pdo, $ada, $other->create($opt4, ['rp' => 'evil.com']), '', '')['code'] ?? null);
$opt5 = passkey_register_options($pdo, $ada);
eq('a challenge issued for someone else, no', 'NOT_ACCEPTED',
    passkey_register_finish($pdo, ['id' => 8] + $ada, $other->create($opt5), '', '')['code'] ?? null);
$opt6 = passkey_register_options($pdo, $ada);
$bad = $other->create($opt6);
$bad['publicKeyAlgorithm'] = -8;
eq('an algorithm we do not take, no', 'UNSUPPORTED', passkey_register_finish($pdo, $ada, $bad, '', '')['code'] ?? null);
$laptop = new FakeAuthenticator(-257, true);
$opt7 = passkey_register_options($pdo, $ada);
ok('an RS256 passkey (Windows Hello) is saved too', passkey_register_finish($pdo, $ada, $laptop->create($opt7), '', '')['ok'] === true);

echo "sign-in\n";
$lo = passkey_login_options();
eq('no credential list: the phone offers its own', false, isset($lo['allowCredentials']));
$in = passkey_login_finish($pdo, $phone->get($lo));
ok('the phone signs Ada in', $in['ok'] === true && $in['user_id'] === 7, json_encode($in));
eq('…once per challenge', 'NOT_ACCEPTED', passkey_login_finish($pdo, $phone->get($lo))['code'] ?? null);
$lo = passkey_login_options();
eq('a forged signature, no', 'NOT_ACCEPTED', passkey_login_finish($pdo, $phone->get($lo, ['tamper' => true]))['code'] ?? null);
$lo = passkey_login_options();
eq('signed for another site, no', 'NOT_ACCEPTED', passkey_login_finish($pdo, $phone->get($lo, ['origin' => 'https://evil.com']))['code'] ?? null);
$lo = passkey_login_options();
eq('without the fingerprint, no', 'NOT_ACCEPTED', passkey_login_finish($pdo, $phone->get($lo, ['uv' => false]))['code'] ?? null);
$lo = passkey_login_options();
eq('a counter that goes backwards (a copied key), no', 'NOT_ACCEPTED', passkey_login_finish($pdo, $phone->get($lo, ['counter' => 1]))['code'] ?? null);
$lo = passkey_login_options();
eq('someone else\'s user handle, no', 'NOT_ACCEPTED', passkey_login_finish($pdo, $phone->get($lo, ['handle' => passkey_b64u_encode('x')]))['code'] ?? null);
$lo = passkey_login_options();
ok('the counter moves on: signed in again', passkey_login_finish($pdo, $phone->get($lo, ['step' => 5]))['ok'] === true);
$lo = passkey_login_options();
ok('a synced passkey (counter always 0) signs in', passkey_login_finish($pdo, $laptop->get($lo))['ok'] === true);
$lo = passkey_login_options();
ok('…and again', passkey_login_finish($pdo, $laptop->get($lo))['ok'] === true);
$stranger = new FakeAuthenticator(-7);
$lo = passkey_login_options();
eq('a passkey we never saved: UNKNOWN (the device forgets it)', 'UNKNOWN', passkey_login_finish($pdo, $stranger->get($lo))['code'] ?? null);
eq('…and its challenge is spent', false, passkey_challenge_take('login', $lo['challenge']));
$expired = passkey_login_options();
$_SESSION['passkey_challenges']['login'][$expired['challenge']]['at'] -= PASSKEY_CHALLENGE_TTL + 1;
eq('a challenge older than 10 minutes, no', 'NOT_ACCEPTED', passkey_login_finish($pdo, $phone->get($expired))['code'] ?? null);

echo "the person's list\n";
$list = passkey_list($pdo, 7);
eq('two passkeys', 2, count($list));
eq('the synced one says so', [true, false], array_column($list, 'synced'));
ok('both have been used', $list[0]['last_used_at'] !== null && $list[1]['last_used_at'] !== null);
eq('nobody else can remove Ada\'s', false, passkey_delete($pdo, 8, $list[0]['id']));
eq('Ada can', true, passkey_delete($pdo, 7, $list[0]['id']));
$lo = passkey_login_options();
eq('a removed passkey no longer signs in', 'UNKNOWN', passkey_login_finish($pdo, $laptop->get($lo))['code'] ?? null);
$third = new FakeAuthenticator(-7);
ok('another one added', passkey_register_finish($pdo, $ada, $third->create(passkey_register_options($pdo, $ada)), '', '')['ok']);
eq('an administrator\'s reset removes all of them', 2, passkey_delete_all($pdo, 7));
$lo = passkey_login_options();
eq('…so the lost phone no longer opens the account', 'UNKNOWN', passkey_login_finish($pdo, $phone->get($lo))['code'] ?? null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
