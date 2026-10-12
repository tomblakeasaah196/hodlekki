<?php
// /includes/push_crypto.php
/**
 * ============================================================================
 * WEB PUSH CRYPTOGRAPHY — VAPID (RFC 8292) + payload encryption (RFC 8291)
 * ----------------------------------------------------------------------------
 * Pure PHP on top of ext-openssl. Deliberately NOT a Composer package:
 *   - minishlink/web-push drags in ext-gmp / ext-bcmath and a dozen
 *     spomky-labs packages, none of which are guaranteed on this cPanel host;
 *   - vendor/ is regenerated on every deploy, so a dependency that fails to
 *     install there takes notifications down silently.
 * Everything here uses only openssl_*, hash_hmac() and random_bytes(), all of
 * which are already relied on by includes/sms_functions.php.
 *
 * The three jobs:
 *   1. push_vapid_authorization()  — the signed "who is sending this" header.
 *   2. push_encrypt_payload()      — aes128gcm body only the subscriber's
 *                                    browser can decrypt (the push service
 *                                    itself never sees the contents).
 *   3. push_ec_*_pem_from_raw()    — turn the raw base64url keys that the
 *                                    Push API hands JavaScript into PEM that
 *                                    OpenSSL will accept.
 *
 * Verified against the worked example in RFC 8291 §5 by
 * tests/pwa/push_crypto_test.php — run it after ANY edit to this file.
 * A silent break here means every member stops getting notifications and
 * nothing in the UI says why.
 * ============================================================================
 */

if (!defined('PUSH_CRYPTO_LOADED')) {
    define('PUSH_CRYPTO_LOADED', true);

    /** Record size advertised in the aes128gcm header. One record, always. */
    define('PUSH_RECORD_SIZE', 4096);

    /** Largest payload we will try to encrypt (push services cap around 4KB). */
    define('PUSH_MAX_PAYLOAD_BYTES', 3052);

    /* ---------------------------------------------------------------- *
     * base64url
     * ---------------------------------------------------------------- */

    function push_b64url_encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    function push_b64url_decode(string $text): string
    {
        $text = strtr(trim($text), '-_', '+/');
        $pad  = strlen($text) % 4;
        if ($pad > 0) {
            $text .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($text, true);
        return $out === false ? '' : $out;
    }

    /* ---------------------------------------------------------------- *
     * HKDF (RFC 5869) over SHA-256
     * ---------------------------------------------------------------- */

    /** Extract-then-expand. hash_hkdf() exists but takes salt/info in a
     *  different order than the push RFCs read, so this stays explicit. */
    function push_hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        $output   = '';
        $previous = '';
        for ($counter = 1; strlen($output) < $length; $counter++) {
            $previous = hash_hmac('sha256', $previous . $info . chr($counter), $prk, true);
            $output  .= $previous;
        }

        return substr($output, 0, $length);
    }

    /* ---------------------------------------------------------------- *
     * Minimal DER writer — just enough for P-256 key material
     * ---------------------------------------------------------------- */

    /** DER length prefix. Nothing here exceeds 255 bytes, but the two-byte
     *  form is written anyway so the helper is not quietly size-limited. */
    function push_der_len(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length <= 0xFF) {
            return "\x81" . chr($length);
        }
        return "\x82" . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    function push_der_tlv(int $tag, string $value): string
    {
        return chr($tag) . push_der_len(strlen($value)) . $value;
    }

    function push_pem_wrap(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END {$label}-----\n";
    }

    /**
     * SubjectPublicKeyInfo PEM for a raw uncompressed P-256 point
     * (the 65 bytes a browser hands over as subscription.keys.p256dh).
     */
    function push_ec_public_pem_from_raw(string $point): string
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new InvalidArgumentException('Expected a 65-byte uncompressed P-256 point.');
        }

        $algorithm = push_der_tlv(0x30,
            push_der_tlv(0x06, hex2bin('2a8648ce3d0201'))      // id-ecPublicKey
            . push_der_tlv(0x06, hex2bin('2a8648ce3d030107'))  // prime256v1
        );
        $spki = push_der_tlv(0x30, $algorithm . push_der_tlv(0x03, "\x00" . $point));

        return push_pem_wrap($spki, 'PUBLIC KEY');
    }

    /**
     * SEC1 (RFC 5915) EC private key PEM from the raw 32-byte scalar.
     * $point may be omitted; OpenSSL recomputes nothing, so supply it
     * whenever it is known — some builds refuse a key with no public part.
     */
    function push_ec_private_pem_from_raw(string $scalar, string $point = ''): string
    {
        if (strlen($scalar) !== 32) {
            throw new InvalidArgumentException('Expected a 32-byte P-256 private scalar.');
        }
        if ($point === '') {
            $point = push_ec_point_from_scalar($scalar);
        }

        $der = push_der_tlv(0x02, "\x01")                                     // version 1
            . push_der_tlv(0x04, $scalar)                                     // privateKey
            . push_der_tlv(0xA0, push_der_tlv(0x06, hex2bin('2a8648ce3d030107')))
            . push_der_tlv(0xA1, push_der_tlv(0x03, "\x00" . $point));

        return push_pem_wrap(push_der_tlv(0x30, $der), 'EC PRIVATE KEY');
    }

    /**
     * Derive the public point for a private scalar. OpenSSL will not do this
     * from a key with no public part, so the scalar is wrapped in a key whose
     * public component is a placeholder and then re-read — which fails on
     * some builds. Keys this module generates always carry their point, so
     * this is only a fallback for a hand-written .env that omitted it.
     */
    function push_ec_point_from_scalar(string $scalar): string
    {
        // ECPrivateKey without the optional [1] publicKey. OpenSSL 1.1+/3.x
        // fills in the point itself when the key is parsed.
        $der = push_der_tlv(0x02, "\x01")
            . push_der_tlv(0x04, $scalar)
            . push_der_tlv(0xA0, push_der_tlv(0x06, hex2bin('2a8648ce3d030107')));

        $key = openssl_pkey_get_private(push_pem_wrap(push_der_tlv(0x30, $der), 'EC PRIVATE KEY'));
        if ($key === false) {
            throw new RuntimeException('OpenSSL could not read the VAPID private key. Store the public key alongside it.');
        }

        $details = openssl_pkey_get_details($key);
        if (empty($details['ec']['x']) || empty($details['ec']['y'])) {
            throw new RuntimeException('OpenSSL returned no public point for the VAPID private key.');
        }

        return "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    /* ---------------------------------------------------------------- *
     * Key generation
     * ---------------------------------------------------------------- */

    /**
     * A fresh P-256 keypair.
     * @return array{public:string,private:string,public_raw:string,private_raw:string,pem:string}
     *         public/private are base64url (what .env and the browser want);
     *         *_raw are the bytes; pem is the private key for OpenSSL.
     */
    function push_generate_keypair(): array
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new() failed — is ext-openssl built with EC support?');
        }

        $details = openssl_pkey_get_details($key);
        if (empty($details['ec']['d'])) {
            throw new RuntimeException('OpenSSL returned no private scalar for the generated key.');
        }

        $scalar = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
        $point  = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return [
            'public'      => push_b64url_encode($point),
            'private'     => push_b64url_encode($scalar),
            'public_raw'  => $point,
            'private_raw' => $scalar,
            'pem'         => push_ec_private_pem_from_raw($scalar, $point),
        ];
    }

    /* ---------------------------------------------------------------- *
     * VAPID — RFC 8292
     * ---------------------------------------------------------------- */

    /**
     * Read one DER tag-length-value at $offset, advancing it past the value.
     * Returns the VALUE only — descend into a SEQUENCE by re-reading its
     * value with a fresh offset.
     */
    function push_der_read(string $der, int &$offset, int $expectedTag): string
    {
        if (!isset($der[$offset]) || ord($der[$offset]) !== $expectedTag) {
            throw new RuntimeException('Malformed DER: expected tag 0x' . dechex($expectedTag) . '.');
        }
        $offset++;

        if (!isset($der[$offset])) {
            throw new RuntimeException('Malformed DER: truncated length.');
        }
        $length = ord($der[$offset]);
        $offset++;
        if ($length & 0x80) {
            $bytes  = $length & 0x7F;
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                if (!isset($der[$offset + $i])) {
                    throw new RuntimeException('Malformed DER: truncated long-form length.');
                }
                $length = ($length << 8) | ord($der[$offset + $i]);
            }
            $offset += $bytes;
        }

        $value = substr($der, $offset, $length);
        if (strlen($value) !== $length) {
            throw new RuntimeException('Malformed DER: value shorter than its length.');
        }
        $offset += $length;

        return $value;
    }

    /** DER ECDSA-Sig-Value (SEQUENCE { INTEGER r, INTEGER s }) -> raw r||s. */
    function push_der_signature_to_raw(string $der): string
    {
        $outer = 0;
        $body  = push_der_read($der, $outer, 0x30);

        $inner = 0;
        // DER INTEGERs are signed, so a value whose top bit is set carries a
        // leading 0x00 guard byte that is not part of the number.
        $r = ltrim(push_der_read($body, $inner, 0x02), "\x00");
        $s = ltrim(push_der_read($body, $inner, 0x02), "\x00");

        if (strlen($r) > 32 || strlen($s) > 32) {
            throw new RuntimeException('ECDSA signature components are too large for P-256.');
        }

        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * The `Authorization: vapid t=…,k=…` header for one push service origin.
     *
     * @param string $audience  Scheme + host of the endpoint, e.g. https://fcm.googleapis.com
     * @param string $subject   mailto: or https: contact for the sender
     * @param string $publicRaw 65-byte application server public key
     * @param string $pem       Application server private key, PEM
     * @param int    $ttl       JWT lifetime in seconds (RFC 8292 caps it at 24h)
     */
    function push_vapid_authorization(
        string $audience,
        string $subject,
        string $publicRaw,
        string $pem,
        int $ttl = 43200
    ): string {
        $header = push_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = push_b64url_encode(json_encode([
            'aud' => $audience,
            'exp' => time() + max(60, min($ttl, 86400 - 60)),
            'sub' => $subject,
        ]));

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('OpenSSL rejected the VAPID private key.');
        }

        $der = '';
        if (!openssl_sign($header . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing the VAPID token failed.');
        }

        $jwt = $header . '.' . $claims . '.' . push_b64url_encode(push_der_signature_to_raw($der));

        return 'vapid t=' . $jwt . ',k=' . push_b64url_encode($publicRaw);
    }

    /** Scheme + host of an endpoint — the only part a VAPID `aud` may carry. */
    function push_endpoint_audience(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Push endpoint is not an absolute URL.');
        }

        $audience = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $audience .= ':' . $parts['port'];
        }
        return $audience;
    }

    /* ---------------------------------------------------------------- *
     * Payload encryption — RFC 8291 (aes128gcm, RFC 8188)
     * ---------------------------------------------------------------- */

    /**
     * Encrypt one payload for one subscription.
     *
     * $salt and $senderKeys exist only so the RFC 8291 §5 test vector can be
     * reproduced exactly; production always passes null and gets fresh
     * randomness, which is what makes each message indistinguishable.
     *
     * @param string      $payload    UTF-8 body (JSON, in this module)
     * @param string      $uaPublic   65-byte subscription public key (p256dh)
     * @param string      $authSecret 16-byte subscription auth secret
     * @return string                 The complete aes128gcm request body
     */
    function push_encrypt_payload(
        string $payload,
        string $uaPublic,
        string $authSecret,
        ?string $salt = null,
        ?array $senderKeys = null
    ): string {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new InvalidArgumentException('Subscription p256dh is not a 65-byte uncompressed point.');
        }
        if (strlen($authSecret) !== 16) {
            throw new InvalidArgumentException('Subscription auth secret must be 16 bytes.');
        }
        if (strlen($payload) > PUSH_MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Payload is larger than a push service will accept.');
        }

        $salt   = $salt ?? random_bytes(16);
        $sender = $senderKeys ?? push_generate_keypair();

        // 1. ECDH between the application server and the subscriber.
        $peer   = openssl_pkey_get_public(push_ec_public_pem_from_raw($uaPublic));
        $mine   = openssl_pkey_get_private($sender['pem']);
        if ($peer === false || $mine === false) {
            throw new RuntimeException('OpenSSL could not load the keys for the ECDH exchange.');
        }
        $shared = openssl_pkey_derive($peer, $mine, 32);
        if ($shared === false) {
            throw new RuntimeException('openssl_pkey_derive() failed — ext-openssl is too old for Web Push (PHP 7.3+ required).');
        }

        // 2. Mix in the subscription's auth secret (RFC 8291 §3.3). The
        //    "WebPush: info" string binds the key material to both parties,
        //    so a shared secret alone is not enough to forge a message.
        $ikm = push_hkdf(
            $authSecret,
            $shared,
            "WebPush: info\x00" . $uaPublic . $sender['public_raw'],
            32
        );

        // 3. Content encryption key and nonce (RFC 8188 §2.2).
        $cek   = push_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = push_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        // 4. One record, so the padding delimiter is 0x02 ("last record").
        $tag        = '';
        $ciphertext = openssl_encrypt(
            $payload . "\x02",
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($ciphertext === false) {
            throw new RuntimeException('AES-128-GCM encryption failed.');
        }

        // 5. salt | rs | idlen | application server public key | ciphertext
        return $salt
            . pack('N', PUSH_RECORD_SIZE)
            . chr(strlen($sender['public_raw']))
            . $sender['public_raw']
            . $ciphertext . $tag;
    }
}
