<?php

namespace HOD\Integrations\Google;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HOD\Integrations\Settings;
use HOD\Integrations\Vault;
use RuntimeException;
use Throwable;

/**
 * The church's Google connection (docs/INTEGRATIONS.md), modelled on JBS Praxis's GoogleWorkspace without
 * transcripts: Calendar events with a Meet link for Trainings' live classes, and Meet's participant lists after the
 * class. One Google account at a time (first the JBS Praxis Gmail for testing, later HOD's own): switching is
 * Disconnect, then Connect as the other account. Nothing in the code names the account.
 *
 * The OAuth client (ID, secret) comes from .env. The refresh token and the cached access token are kept in
 * integration_settings, encrypted (HOD\Integrations\Vault). When Google answers invalid_grant the connection is
 * marked revoked: System Health turns red and every call throws GoogleError('revoked') until an admin reconnects.
 * Nothing here logs a token, a code or a participant. Tests replace the network with Google::fake().
 */
final class Google
{
    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/meetings.space.readonly',
    ];

    public const TIME_ZONE = 'Africa/Lagos';

    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const CALENDAR = 'https://www.googleapis.com/calendar/v3';
    private const MEET = 'https://meet.googleapis.com/v2';

    // integration_settings keys
    public const REFRESH = 'google.refresh_token';   // secret
    public const ACCESS = 'google.access_token';     // secret: {"t": token, "e": expires at}
    public const EMAIL = 'google.account_email';
    public const GRANTED = 'google.scopes';
    public const STATUS = 'google.status';           // connected | revoked
    public const LAST_OK = 'google.last_ok_at';
    public const LAST_ERROR = 'google.last_error';   // "<reason> <Y-m-d H:i:s>"

    /** @var callable|null fn (array $request): array{status: int, body: string} */
    private static $transport = null;

    /** Tests: replace the network. $request: method, url, headers (list), body (?string). */
    public static function fake(?callable $transport): void
    {
        self::$transport = $transport;
    }

    // ---- Connection -----------------------------------------------------------------------------------------

    /** The redirect URI registered on the OAuth client (https://hodlekki.org/integrations/google/callback on live) */
    public static function redirectUri(): string
    {
        $set = trim(site_env('GOOGLE_OAUTH_REDIRECT_URI'));

        return $set !== '' ? $set : site_url('/integrations/google/callback');
    }

    public static function hasClient(): bool
    {
        return trim(site_env('GOOGLE_OAUTH_CLIENT_ID')) !== '' && trim(site_env('GOOGLE_OAUTH_CLIENT_SECRET')) !== '';
    }

    /**
     * Where things stand, for the Integrations page and System Health.
     * state: no_table | no_client | no_key | not_connected | connected | revoked
     */
    public static function status(): array
    {
        $out = ['state' => 'no_table', 'email' => null, 'connected_at' => null, 'connected_by' => null,
            'missing_scopes' => [], 'last_ok_at' => null, 'last_error' => null];
        if (!Settings::ready()) {
            return $out;
        }
        $changed = Settings::changed(self::REFRESH);
        $out['email'] = Settings::get(self::EMAIL);
        $out['connected_at'] = $changed[0] ?? null;
        $out['connected_by'] = $changed[1] ?? null;
        $out['last_ok_at'] = Settings::get(self::LAST_OK);
        $out['last_error'] = Settings::get(self::LAST_ERROR);
        $out['missing_scopes'] = $changed ? self::missingScopes() : [];
        $out['state'] = match (true) {
            !self::hasClient() => 'no_client',
            !Vault::ready() => 'no_key',
            Settings::get(self::STATUS) === 'revoked' => 'revoked',
            $changed === null || Settings::get(self::STATUS) !== 'connected' => 'not_connected',
            default => 'connected',
        };

        return $out;
    }

    public static function isConnected(): bool
    {
        return self::status()['state'] === 'connected';
    }

    /** @return list<string> the scopes this build needs that the connection was not given */
    public static function missingScopes(): array
    {
        $granted = array_filter(explode(' ', (string) Settings::get(self::GRANTED)));
        // Google reports openid and email by their long names too
        $aliases = ['email' => 'https://www.googleapis.com/auth/userinfo.email', 'openid' => 'openid'];

        return array_values(array_filter(self::SCOPES, fn ($s) => !in_array($s, $granted, true)
            && !in_array($aliases[$s] ?? '', $granted, true) && $s !== 'openid'));
    }

    /** A PKCE pair (RFC 7636, S256): [verifier, challenge] */
    public static function pkce(): array
    {
        $verifier = self::b64url(random_bytes(48));

        return [$verifier, self::b64url(hash('sha256', $verifier, true))];
    }

    /** Where Connect sends the admin: offline access, consent every time (so Google returns a refresh token) */
    public static function authorizationUrl(string $state, string $challenge): string
    {
        if (!self::hasClient()) {
            throw new GoogleError('no_client');
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => trim(site_env('GOOGLE_OAUTH_CLIENT_ID')),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchange the callback's code (with the PKCE verifier); keeps the refresh token. Returns the account's email. */
    public static function connect(string $code, string $verifier, ?int $by = null): string
    {
        if (!Vault::ready()) {
            throw new GoogleError('no_key');
        }
        $token = self::tokenRequest(['code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code']);
        if (empty($token['refresh_token'])) {
            // Google only sends one with prompt=consent; without it nothing works once the hour is up
            throw new GoogleError('failed');
        }
        $email = self::emailFromIdToken($token['id_token'] ?? null);
        Settings::setSecret(self::REFRESH, (string) $token['refresh_token'], $by);
        Settings::set(self::EMAIL, $email, $by);
        Settings::set(self::GRANTED, (string) ($token['scope'] ?? ''), $by);
        Settings::set(self::STATUS, 'connected', $by);
        Settings::set(self::LAST_ERROR, null);
        self::cacheAccessToken($token);
        self::recordOk();

        return (string) $email;
    }

    /** Revoke at Google (best effort) and forget the connection. The client in .env stays. */
    public static function disconnect(): void
    {
        $refresh = Settings::secret(self::REFRESH);
        if ($refresh !== null) {
            try {
                self::send(['method' => 'POST', 'url' => self::REVOKE_URL, 'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                    'body' => http_build_query(['token' => $refresh])]);
            } catch (Throwable) {
                // Already revoked, or Google unreachable: forgetting it here is what matters
            }
        }
        foreach ([self::REFRESH, self::ACCESS, self::EMAIL, self::GRANTED, self::STATUS, self::LAST_OK, self::LAST_ERROR] as $key) {
            Settings::set($key, null);
        }
    }

    /** A harmless call to prove the connection works (one event of the calendar, nothing changed). Returns the email. */
    public static function test(): string
    {
        self::call('GET', self::CALENDAR . '/calendars/primary/events', ['maxResults' => 1, 'singleEvents' => 'true']);

        return (string) Settings::get(self::EMAIL);
    }

    // ---- Calendar and Meet ----------------------------------------------------------------------------------

    /**
     * A calendar event with a Meet link. $m: title, starts_at, ends_at (DateTimeInterface or Lagos 'Y-m-d H:i'),
     * description (optional). No guests are added and Google sends nothing: Trainings gives the link and reminds.
     *
     * @return array{event_id: string, meet_url: ?string, meet_code: ?string, html_link: ?string}
     */
    public static function createEvent(array $m): array
    {
        $event = self::call('POST', self::CALENDAR . '/calendars/primary/events', ['conferenceDataVersion' => 1, 'sendUpdates' => 'none'],
            self::eventBody($m) + ['conferenceData' => ['createRequest' => [
                'requestId' => bin2hex(random_bytes(16)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]]]);
        // Google may still be making the Meet space: read the event again for its link
        for ($i = 0; $i < 3 && ($event['conferenceData']['createRequest']['status']['statusCode'] ?? 'success') === 'pending'; $i++) {
            usleep(self::$transport !== null ? 0 : 800_000);
            $event = self::call('GET', self::CALENDAR . '/calendars/primary/events/' . rawurlencode((string) $event['id']));
        }

        return self::eventOut($event);
    }

    /** Move or retitle the event; the Meet link stays. */
    public static function updateEvent(string $eventId, array $m): array
    {
        return self::eventOut(self::call('PATCH', self::CALENDAR . '/calendars/primary/events/' . rawurlencode($eventId),
            ['conferenceDataVersion' => 1, 'sendUpdates' => 'none'], self::eventBody($m)));
    }

    /** Delete the event; one already gone counts as cancelled. */
    public static function cancelEvent(string $eventId): void
    {
        try {
            self::call('DELETE', self::CALENDAR . '/calendars/primary/events/' . rawurlencode($eventId), ['sendUpdates' => 'none']);
        } catch (GoogleError $e) {
            if ($e->reason !== 'not_found') {
                throw $e;
            }
        }
    }

    /**
     * The calls held in the Meet space with this code that started between the two moments, oldest first.
     *
     * @return list<array{name: string, start: ?string, end: ?string}>  name: conferenceRecords/{id}; times RFC 3339
     */
    public static function conferenceRecords(string $meetCode, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $filter = sprintf('space.meeting_code = "%s" AND start_time >= "%s" AND start_time <= "%s"',
            strtolower(preg_replace('/[^A-Za-z-]/', '', $meetCode)), self::utc($from), self::utc($to));
        $out = [];
        foreach (self::pages(self::MEET . '/conferenceRecords', 'conferenceRecords', ['filter' => $filter]) as $r) {
            $out[] = ['name' => (string) $r['name'], 'start' => $r['startTime'] ?? null, 'end' => $r['endTime'] ?? null];
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['start'], (string) $b['start']));

        return $out;
    }

    /**
     * Everyone in one call, with each stretch they were in it. On personal-Gmail meetings Google gives display
     * names, never email addresses.
     *
     * @return list<array{id: string, display_name: string, kind: string, sessions: list<array{0: ?string, 1: ?string}>}>
     */
    public static function participants(string $record): array
    {
        $out = [];
        foreach (self::pages(self::MEET . '/' . $record . '/participants', 'participants') as $p) {
            $kind = isset($p['signedinUser']) ? 'signed_in' : (isset($p['anonymousUser']) ? 'anonymous' : (isset($p['phoneUser']) ? 'phone' : 'other'));
            $name = (string) ($p['signedinUser']['displayName'] ?? $p['anonymousUser']['displayName'] ?? $p['phoneUser']['displayName'] ?? '');
            $sessions = [];
            foreach (self::pages(self::MEET . '/' . $p['name'] . '/participantSessions', 'participantSessions') as $s) {
                $sessions[] = [$s['startTime'] ?? null, $s['endTime'] ?? null];
            }
            if (!$sessions) {
                $sessions[] = [$p['earliestStartTime'] ?? null, $p['latestEndTime'] ?? null];
            }
            $out[] = ['id' => (string) $p['name'], 'display_name' => $name, 'kind' => $kind, 'sessions' => $sessions];
        }

        return $out;
    }

    // ---- Plumbing -------------------------------------------------------------------------------------------

    private static function eventBody(array $m): array
    {
        $time = fn ($t) => ['dateTime' => self::lagos($t)->format(DATE_RFC3339), 'timeZone' => self::TIME_ZONE];

        return array_filter([
            'summary' => (string) $m['title'],
            'description' => $m['description'] ?? null,
            'start' => $time($m['starts_at']),
            'end' => $time($m['ends_at']),
            'guestsCanModify' => false,
        ], fn ($v) => $v !== null);
    }

    private static function eventOut(array $event): array
    {
        $video = null;
        foreach ($event['conferenceData']['entryPoints'] ?? [] as $entry) {
            if (($entry['entryPointType'] ?? '') === 'video') {
                $video = $entry['uri'] ?? null;
                break;
            }
        }

        return [
            'event_id' => (string) ($event['id'] ?? ''),
            'meet_url' => $event['hangoutLink'] ?? $video,
            'meet_code' => $event['conferenceData']['conferenceId'] ?? null,
            'html_link' => $event['htmlLink'] ?? null,
        ];
    }

    private static function lagos(DateTimeInterface|string $t): DateTimeImmutable
    {
        $zone = new DateTimeZone(self::TIME_ZONE);

        return $t instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($t)->setTimezone($zone) : new DateTimeImmutable($t, $zone);
    }

    private static function utc(DateTimeInterface $t): string
    {
        return DateTimeImmutable::createFromInterface($t)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** One API call with a fresh access token; a 401 refreshes the token once and retries. */
    private static function call(string $method, string $url, array $query = [], ?array $body = null): array
    {
        for ($attempt = 0; ; $attempt++) {
            $token = self::accessToken($attempt > 0);
            $target = $query ? $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;
            try {
                $response = self::send(['method' => $method, 'url' => $target,
                    'headers' => ['Authorization: Bearer ' . $token, 'Accept: application/json'] + ($body !== null ? [2 => 'Content-Type: application/json'] : []),
                    'body' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
            } catch (RuntimeException $e) {
                self::recordError('failed');
                throw new GoogleError('failed', $e);
            }
            $status = (int) $response['status'];
            if ($status === 401 && $attempt === 0) {
                continue;
            }
            $data = json_decode((string) $response['body'], true);
            if ($status >= 200 && $status < 300) {
                self::recordOk();

                return is_array($data) ? $data : [];
            }
            throw self::failure($status, is_array($data) ? $data : [], $url);
        }
    }

    /** Follows nextPageToken (100 pages at most); yields the items under $key */
    private static function pages(string $url, string $key, array $query = []): \Generator
    {
        $token = null;
        for ($page = 0; $page < 100; $page++) {
            $data = self::call('GET', $url, array_filter($query + ['pageSize' => 100, 'pageToken' => $token], fn ($v) => $v !== null));
            yield from $data[$key] ?? [];
            $token = $data['nextPageToken'] ?? null;
            if (!$token) {
                return;
            }
        }
    }

    private static function failure(int $status, array $data, string $url): GoogleError
    {
        $reason = (string) ($data['error']['errors'][0]['reason'] ?? $data['error']['status'] ?? '');
        $kind = match (true) {
            $status === 429, in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'RESOURCE_EXHAUSTED'], true) => 'quota',
            $status === 401 => 'revoked',
            $status === 403 && in_array($reason, ['insufficientPermissions', 'PERMISSION_DENIED', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], true) => 'scopes',
            in_array($status, [404, 410], true) => 'not_found',
            default => 'failed',
        };
        if ($kind === 'revoked') {
            self::markRevoked();
        }
        if ($kind !== 'not_found') {
            self::recordError($kind);
            error_log('Google: a call failed (' . parse_url($url, PHP_URL_HOST) . ', HTTP ' . $status . ($reason !== '' ? ', ' . $reason : '') . ')');
        }

        return new GoogleError($kind);
    }

    /** A valid access token: the cached one, or a new one from the refresh token. */
    private static function accessToken(bool $fresh = false): string
    {
        if (!self::hasClient()) {
            throw new GoogleError('no_client');
        }
        if (Settings::get(self::STATUS) === 'revoked') {
            throw new GoogleError('revoked');
        }
        $refresh = Settings::secret(self::REFRESH);
        if ($refresh === null) {
            throw new GoogleError(Vault::ready() || Settings::get(self::REFRESH) === null ? 'not_connected' : 'no_key');
        }
        if (!$fresh) {
            $cached = json_decode((string) Settings::secret(self::ACCESS), true);
            if (is_array($cached) && is_string($cached['t'] ?? null) && (int) ($cached['e'] ?? 0) > time()) {
                return $cached['t'];
            }
        }

        return self::cacheAccessToken(self::tokenRequest(['refresh_token' => $refresh, 'grant_type' => 'refresh_token']));
    }

    private static function cacheAccessToken(array $token): string
    {
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new GoogleError('failed');
        }
        $expires = time() + max(60, (int) ($token['expires_in'] ?? 3600) - 120);
        Settings::setSecret(self::ACCESS, (string) json_encode(['t' => $access, 'e' => $expires]));

        return $access;
    }

    /** POST to the token endpoint with the client's credentials */
    private static function tokenRequest(array $params): array
    {
        try {
            $response = self::send(['method' => 'POST', 'url' => self::TOKEN_URL,
                'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                'body' => http_build_query($params + ['client_id' => trim(site_env('GOOGLE_OAUTH_CLIENT_ID')),
                    'client_secret' => trim(site_env('GOOGLE_OAUTH_CLIENT_SECRET'))], '', '&')]);
        } catch (RuntimeException $e) {
            self::recordError('failed');
            throw new GoogleError('failed', $e);
        }
        $data = json_decode((string) $response['body'], true);
        $data = is_array($data) ? $data : [];
        if ((int) $response['status'] >= 200 && (int) $response['status'] < 300) {
            return $data;
        }
        $error = (string) ($data['error'] ?? '');
        if ($error === 'invalid_grant' && ($params['grant_type'] ?? '') === 'refresh_token') {
            self::markRevoked();

            throw new GoogleError('revoked');
        }
        $kind = in_array($error, ['invalid_client', 'unauthorized_client'], true) ? 'client' : 'failed';
        self::recordError($kind);
        error_log('Google: the token request failed (HTTP ' . (int) $response['status'] . ($error !== '' ? ', ' . preg_replace('/[^a-z_]/', '', $error) : '') . ')');

        throw new GoogleError($kind);
    }

    private static function markRevoked(): void
    {
        if (Settings::get(self::STATUS) !== 'revoked') {
            Settings::set(self::STATUS, 'revoked');
            error_log('Google: access was withdrawn; an admin must Connect again (Integrations)');
        }
        Settings::set(self::ACCESS, null);
        self::recordError('revoked');
    }

    private static function recordOk(): void
    {
        $now = date('Y-m-d H:i:s');
        // One write a minute at most: every API call lands here
        if (substr((string) Settings::get(self::LAST_OK), 0, 16) !== substr($now, 0, 16)) {
            Settings::set(self::LAST_OK, $now);
        }
        if (Settings::get(self::LAST_ERROR) !== null) {
            Settings::set(self::LAST_ERROR, null);
        }
    }

    private static function recordError(string $reason): void
    {
        try {
            Settings::set(self::LAST_ERROR, $reason . ' ' . date('Y-m-d H:i:s'));
        } catch (Throwable) {
            // Health is told next time; the caller still gets the error
        }
    }

    /** The email in the ID token from Google's own token endpoint (fetched over TLS, so not re-verified) */
    private static function emailFromIdToken(?string $idToken): ?string
    {
        $parts = explode('.', (string) $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($claims) && !empty($claims['email']) ? strtolower((string) $claims['email']) : null;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array{status: int, body: string} */
    private static function send(array $request): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($request);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl missing');
        }
        $ch = curl_init($request['url']);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_HTTPHEADER => array_values($request['headers'] ?? []),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];
        if (($request['body'] ?? null) !== null) {
            $opts[CURLOPT_POSTFIELDS] = $request['body'];
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($out === false || $errno !== 0) {
            throw new RuntimeException('curl ' . $errno);
        }

        return ['status' => $status, 'body' => (string) $out];
    }
}
