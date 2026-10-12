<?php
// /bin/generate_vapid_keys.php
/**
 * Print a fresh VAPID keypair for .env.
 *
 *     php bin/generate_vapid_keys.php
 *
 * Push works without this: includes/push_helpers.php generates a keypair on
 * first use and stores it in `push_config`. Putting the keys in .env instead
 * is better practice — they survive a database restore taken from before the
 * feature existed, and the private half stays out of nightly SQL dumps.
 *
 * ROTATING THESE KEYS INVALIDATES EVERY EXISTING SUBSCRIPTION. Push services
 * tie a subscription to the public key it was created with, so after a
 * rotation every member has to turn alerts on again. Generate once, keep.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

require_once __DIR__ . '/../includes/push_crypto.php';

try {
    $pair = push_generate_keypair();
} catch (Throwable $e) {
    fwrite(STDERR, "Could not generate a keypair: " . $e->getMessage() . "\n");
    fwrite(STDERR, "ext-openssl must be built with elliptic-curve support (prime256v1).\n");
    exit(1);
}

$subject = $argv[1] ?? 'mailto:asah.tomf@gmail.com';

echo "Add these three lines to .env (and nowhere else — never commit them):\n\n";
echo 'VAPID_PUBLIC_KEY="'  . $pair['public']  . "\"\n";
echo 'VAPID_PRIVATE_KEY="' . $pair['private'] . "\"\n";
echo 'VAPID_SUBJECT="'     . $subject         . "\"\n\n";
echo "Then clear the generated pair the code may already have stored:\n";
echo "    DELETE FROM push_config WHERE config_key IN ('vapid_public','vapid_private');\n\n";
echo "Everyone who had alerts on will need to turn them on again.\n";
