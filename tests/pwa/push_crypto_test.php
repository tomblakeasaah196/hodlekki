<?php
// /tests/pwa/push_crypto_test.php
/**
 * Web Push crypto, checked against the published vectors.
 *
 * The important one is RFC 8291 §5: a complete worked example of the
 * aes128gcm body, from the subscriber's keys down to the last byte of
 * ciphertext. If this passes, every browser on the planet can decrypt what
 * includes/push_crypto.php produces; if it fails, nothing can, and the only
 * symptom in production is notifications that never arrive.
 */

// Run me through tests/pwa/run.php, which supplies check()/ok()/suite().

suite('base64url');
check('round trip', "\x00\xff\x10abc", push_b64url_decode(push_b64url_encode("\x00\xff\x10abc")));
check('no padding', 'AQID', push_b64url_encode("\x01\x02\x03"));
check('accepts padded input', "\x01\x02", push_b64url_decode('AQI='));

suite('HKDF (RFC 5869 test case 1)');
check(
    'A.1 OKM',
    '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
    bin2hex(push_hkdf(
        hex2bin('000102030405060708090a0b0c'),
        hex2bin('0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b'),
        hex2bin('f0f1f2f3f4f5f6f7f8f9'),
        42
    ))
);

suite('RFC 8291 §5 — the worked Web Push example');

// Subscriber (user agent) side, as the RFC lists it.
$uaPublic   = push_b64url_decode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
$authSecret = push_b64url_decode('BTBZMqHH6r4Tts7J_aSIgg');

// Application server side — fixed so the output is reproducible.
$asPublic  = push_b64url_decode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$asPrivate = push_b64url_decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$salt      = push_b64url_decode('DGv6ra1nlYgDCS1FRnbzlw');

check('subscription key is 65 bytes', 65, strlen($uaPublic));
check('auth secret is 16 bytes', 16, strlen($authSecret));

// The point we rebuild from the private scalar alone must match the RFC's.
check('public point recovered from scalar', bin2hex($asPublic), bin2hex(push_ec_point_from_scalar($asPrivate)));

$body = push_encrypt_payload(
    'When I grow up, I want to be a watermelon',
    $uaPublic,
    $authSecret,
    $salt,
    [
        'public_raw' => $asPublic,
        'pem'        => push_ec_private_pem_from_raw($asPrivate, $asPublic),
    ]
);

check(
    'encrypted body matches the RFC byte for byte',
    'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
    push_b64url_encode($body)
);

suite('key generation');
$pair = push_generate_keypair();
check('private scalar is 32 bytes', 32, strlen($pair['private_raw']));
check('public point is 65 bytes', 65, strlen($pair['public_raw']));
check('point is uncompressed', "\x04", $pair['public_raw'][0]);
check('base64url public round trips', $pair['public_raw'], push_b64url_decode($pair['public']));
check('point recovered from the generated scalar', bin2hex($pair['public_raw']), bin2hex(push_ec_point_from_scalar($pair['private_raw'])));

suite('ECDH agreement (both sides reach the same secret)');
$alice = push_generate_keypair();
$bob   = push_generate_keypair();
$ab = openssl_pkey_derive(
    openssl_pkey_get_public(push_ec_public_pem_from_raw($bob['public_raw'])),
    openssl_pkey_get_private($alice['pem']),
    32
);
$ba = openssl_pkey_derive(
    openssl_pkey_get_public(push_ec_public_pem_from_raw($alice['public_raw'])),
    openssl_pkey_get_private($bob['pem']),
    32
);
check('shared secrets agree', bin2hex($ab), bin2hex($ba));

suite('VAPID authorization header');
$auth = push_vapid_authorization(
    'https://fcm.googleapis.com',
    'mailto:ops@example.org',
    $pair['public_raw'],
    $pair['pem']
);
check('starts with the vapid scheme', 'vapid t=', substr($auth, 0, 8));
check('carries the public key', true, str_contains($auth, ',k=' . $pair['public']));

[$token] = explode(',k=', substr($auth, 8), 2);
$segments = explode('.', $token);
check('JWT has three segments', 3, count($segments));
check('JWT header is ES256', ['typ' => 'JWT', 'alg' => 'ES256'], json_decode(push_b64url_decode($segments[0]), true));

$claims = json_decode(push_b64url_decode($segments[1]), true);
check('aud is the push service origin', 'https://fcm.googleapis.com', $claims['aud']);
check('sub is the contact', 'mailto:ops@example.org', $claims['sub']);
check('exp is in the future and under 24h', true, $claims['exp'] > time() && $claims['exp'] <= time() + 86400);
check('signature is raw r||s, 64 bytes', 64, strlen(push_b64url_decode($segments[2])));

// The whole point of a signature: the push service must be able to verify it.
$verified = openssl_verify(
    $segments[0] . '.' . $segments[1],
    // raw -> DER, the inverse of what the header writer did
    (function (string $raw): string {
        $int = function (string $n): string {
            $n = ltrim($n, "\x00");
            if ($n === '' || ord($n[0]) > 0x7F) { $n = "\x00" . $n; }
            return "\x02" . chr(strlen($n)) . $n;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
        return "\x30" . chr(strlen($body)) . $body;
    })(push_b64url_decode($segments[2])),
    openssl_pkey_get_public(push_ec_public_pem_from_raw($pair['public_raw'])),
    OPENSSL_ALGO_SHA256
);
check('a push service can verify the JWT', 1, $verified);

suite('endpoint audience');
check('https host only', 'https://fcm.googleapis.com', push_endpoint_audience('https://fcm.googleapis.com/fcm/send/abc:123'));
check('keeps an explicit port', 'https://push.example.org:8443', push_endpoint_audience('https://push.example.org:8443/x/y'));

suite('guard rails');
throws('a short p256dh is rejected', InvalidArgumentException::class,
    fn() => push_encrypt_payload('x', str_repeat("\x04", 10), str_repeat("\x00", 16)));
throws('a short auth secret is rejected', InvalidArgumentException::class,
    fn() => push_encrypt_payload('x', "\x04" . str_repeat("\x01", 64), 'tooshort'));
throws('an oversized payload is rejected', InvalidArgumentException::class,
    fn() => push_encrypt_payload(str_repeat('a', 5000), "\x04" . str_repeat("\x01", 64), str_repeat("\x00", 16)));
throws('a relative endpoint is rejected', InvalidArgumentException::class,
    fn() => push_endpoint_audience('/not/absolute'));
throws('a truncated DER signature is rejected', RuntimeException::class,
    fn() => push_der_signature_to_raw("\x30\x06\x02\x01\x01"));
throws('a non-sequence DER signature is rejected', RuntimeException::class,
    fn() => push_der_signature_to_raw("\x02\x01\x01"));
