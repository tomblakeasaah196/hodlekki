<?php

namespace HOD\Integrations\Google;

use RuntimeException;
use Throwable;

/**
 * Google could not do what was asked. $reason says why; getMessage() is short and fit for a toast.
 *
 *   not_connected  no connection yet            revoked    access was withdrawn: Connect again
 *   no_client      no client ID/secret in .env  no_key     INTEGRATIONS_VAULT_KEY missing in .env
 *   client         client ID or secret wrong    scopes     a permission was not granted: Connect again
 *   quota          Google's rate limit          not_found  the event or record is gone
 *   failed         anything else (network, a Google error)
 */
final class GoogleError extends RuntimeException
{
    public const MESSAGES = [
        'not_connected' => 'Google is not connected yet.',
        'revoked' => 'Google access was withdrawn. Connect again.',
        'no_client' => 'The Google client is not set in .env yet.',
        'no_key' => 'INTEGRATIONS_VAULT_KEY is missing in .env.',
        'client' => 'Google refused the client ID or secret.',
        'scopes' => 'A Google permission is missing. Connect again and allow every box.',
        'quota' => 'Google is busy. Try again in a minute.',
        'not_found' => 'Google no longer has it.',
        'failed' => 'Google did not answer. Try again.',
    ];

    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES['failed'], 0, $previous);
    }
}
