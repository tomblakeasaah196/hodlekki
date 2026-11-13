<?php
// /includes/our_sermons/fetch.php
//
// The module's only way out to the internet, and what it reads there.
//
//   our_sermons_http_get()     GET over https only, to a public address (the
//                              name is resolved and pinned, private and reserved
//                              ranges refused, so a pasted feed link can never
//                              reach the server's own network), redirects
//                              followed by hand and re-checked, a size cap, short
//                              timeouts. Tests replace the transport with
//                              $GLOBALS['our_sermons_http'] = fn ($url, $opts) => [...].
//   our_sermons_oembed()       Spotify's and YouTube's oEmbed (no key): title + thumbnail
//   our_sermons_feed_parse()   an RSS feed → its episodes (DOCTYPE refused, LIBXML_NONET)
//   our_sermons_store_artwork() copy a picture to /uploads/sermons/artwork/ under a
//                              random name (checked to be an image, shrunk when GD is there)

const OUR_SERMONS_UA = 'HODLekki-Sermons/1.0 (+podcast reader)';
const OUR_SERMONS_FEED_MAX_BYTES = 10 * 1024 * 1024;
const OUR_SERMONS_OEMBED_MAX_BYTES = 256 * 1024;
const OUR_SERMONS_IMAGE_MAX_BYTES = 6 * 1024 * 1024;
const OUR_SERMONS_FEED_MAX_ITEMS = 1000;
const OUR_SERMONS_ARTWORK_EDGE = 640;
// Where the oEmbed thumbnails may come from (the feed's own artwork may be on any public https host).
const OUR_SERMONS_THUMB_HOSTS = ['i.scdn.co', 'mosaic.scdn.co', 'image-cdn-ak.spotifycdn.com', 'image-cdn-fa.spotifycdn.com',
    'i.ytimg.com', 'i9.ytimg.com'];

/**
 * GET $url. Options: max_bytes, timeout, headers (list of "Name: value"),
 * accept, hosts (an allow-list of host names; null = any public host).
 *
 * @return array{ok:bool, status:int, body:string, headers:array<string,string>, url:string, error:string}
 */
function our_sermons_http_get(string $url, array $o = []): array
{
    $fail = static fn (string $error, int $status = 0) => ['ok' => false, 'status' => $status, 'body' => '', 'headers' => [],
        'url' => $url, 'error' => $error];
    $max = (int) ($o['max_bytes'] ?? 1024 * 1024);
    $hosts = isset($o['hosts']) ? array_map('strtolower', (array) $o['hosts']) : null;

    for ($hop = 0; $hop < 5; $hop++) {
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int) $p['port'] !== 443)) {
            return $fail('Use an https link.');
        }
        $host = strtolower((string) $p['host']);
        if ($hosts !== null && !in_array($host, $hosts, true)) {
            return $fail('That address is not allowed here.');
        }
        if (isset($GLOBALS['our_sermons_http']) && is_callable($GLOBALS['our_sermons_http'])) {
            $r = ($GLOBALS['our_sermons_http'])($url, $o);
            $r += ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => ''];
            $r['headers'] = array_change_key_case((array) $r['headers'], CASE_LOWER);
        } else {
            $ip = our_sermons_public_ip($host);
            if ($ip === null) {
                return $fail('That address could not be reached.');
            }
            $r = our_sermons_curl($url, $host, $ip, $o, $max);
        }
        if (in_array((int) $r['status'], [301, 302, 303, 307, 308], true) && !empty($r['headers']['location'])) {
            $url = our_sermons_absolute_url((string) $r['headers']['location'], $url);
            continue;
        }
        $r['url'] = $url;
        $r['status'] = (int) $r['status'];
        if ($r['error'] === '' && strlen((string) $r['body']) > $max) {
            $r['error'] = 'That file is too big.';
            $r['body'] = '';
        }
        $r['ok'] = $r['error'] === '' && ($r['status'] === 200 || $r['status'] === 304);
        if (!$r['ok'] && $r['error'] === '') {
            $r['error'] = $r['status'] === 404 ? 'Nothing was found at that link.' : 'That site answered with an error (' . (int) $r['status'] . ').';
        }
        return $r;
    }
    return $fail('That link redirects too many times.');
}

/** The host's first IPv4 address, only when every address it has is public. */
function our_sermons_public_ip(string $host): ?string
{
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $flags) ? $host : null;
    }
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
        return null;
    }
    $ips = @gethostbynamel($host);
    if (!is_array($ips) || $ips === []) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $flags)) {
            return null;
        }
    }
    return $ips[0];
}

/** One request, pinned to $ip, aborted past $max bytes. */
function our_sermons_curl(string $url, string $host, string $ip, array $o, int $max): array
{
    $out = ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => ''];
    if (!function_exists('curl_init')) {
        $out['error'] = 'The server cannot fetch links right now.';
        return $out;
    }
    $headers = array_merge(['User-Agent: ' . OUR_SERMONS_UA, 'Accept: ' . ($o['accept'] ?? '*/*')], (array) ($o['headers'] ?? []));
    $body = '';
    $tooBig = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => [$host . ':443:' . $ip],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => (int) ($o['timeout'] ?? 20),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$out): int {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $out['headers'][strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig, $max): int {
            if (strlen($body) + strlen($chunk) > $max) {
                $tooBig = true;
                return 0;   // aborts the transfer
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    unset($ch);
    if ($tooBig) {
        $out['error'] = 'That file is too big.';
    } elseif ($ok === false || $errno !== 0) {
        $out['error'] = 'That address could not be reached.';
    } else {
        $out['body'] = $body;
    }
    return $out;
}

/** A Location header made absolute against the URL that sent it. */
function our_sermons_absolute_url(string $location, string $base): string
{
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }
    $b = parse_url($base);
    $origin = 'https://' . ($b['host'] ?? '');
    if (str_starts_with($location, '//')) {
        return 'https:' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $dir = preg_replace('#/[^/]*$#', '/', (string) ($b['path'] ?? '/'));
    return $origin . $dir . $location;
}

/**
 * oEmbed for a parsed link (our_sermons_parse_link()): the episode's or
 * video's title and a thumbnail URL on the provider's image host.
 *
 * @return array{ok:bool, title:string, thumbnail:?string, author:?string, error:string}
 */
function our_sermons_oembed(array $link): array
{
    $endpoint = $link['provider'] === 'spotify'
        ? ['https://open.spotify.com/oembed?url=' . rawurlencode($link['url']), ['open.spotify.com']]
        : ['https://www.youtube.com/oembed?format=json&url=' . rawurlencode($link['url']), ['www.youtube.com']];
    $r = our_sermons_http_get($endpoint[0], ['hosts' => $endpoint[1], 'max_bytes' => OUR_SERMONS_OEMBED_MAX_BYTES,
        'accept' => 'application/json', 'timeout' => 12]);
    if (!$r['ok']) {
        $error = in_array($r['status'], [400, 401, 403, 404], true)
            ? ($link['provider'] === 'spotify' ? 'Spotify could not find that episode.' : 'YouTube could not find that video. Is it public?')
            : $r['error'];
        return ['ok' => false, 'title' => '', 'thumbnail' => null, 'author' => null, 'error' => $error];
    }
    $j = json_decode($r['body'], true);
    $title = is_array($j) && is_string($j['title'] ?? null) ? our_sermons_clean_title($j['title']) : '';
    if ($title === '') {
        return ['ok' => false, 'title' => '', 'thumbnail' => null, 'author' => null, 'error' => 'That link has no title to read.'];
    }
    $thumb = is_string($j['thumbnail_url'] ?? null) ? $j['thumbnail_url'] : null;
    if ($thumb !== null && !in_array(strtolower((string) parse_url($thumb, PHP_URL_HOST)), OUR_SERMONS_THUMB_HOSTS, true)) {
        $thumb = null;
    }
    $author = is_string($j['author_name'] ?? null) ? our_sermons_text($j['author_name'], 120) : null;
    return ['ok' => true, 'title' => mb_substr($title, 0, 255), 'thumbnail' => $thumb, 'author' => $author, 'error' => ''];
}

/**
 * Read an RSS 2.0 podcast feed. Hostile input: a DOCTYPE (and so any entity)
 * is refused before parsing, the network is off for the parser, at most
 * OUR_SERMONS_FEED_MAX_ITEMS episodes are read.
 *
 * Each item: guid, title, published_at (Y-m-d H:i:s, Lagos, or null),
 * audio_url (https only), duration, image (https), link.
 *
 * @return array{ok:bool, title:string, image:?string, items:list<array>, error:string}
 */
function our_sermons_feed_parse(string $xml): array
{
    $none = ['ok' => false, 'title' => '', 'image' => null, 'items' => [], 'error' => ''];
    $head = substr(ltrim($xml), 0, 2048);
    if ($head === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        return ['error' => $head === '' ? 'The feed is empty.' : 'That feed cannot be read safely.'] + $none;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($doc === false || !isset($doc->channel)) {
        return ['error' => 'That link is not a podcast feed.'] + $none;
    }
    $ns = ['itunes' => 'http://www.itunes.com/dtds/podcast-1.0.dtd'];
    $channel = $doc->channel;
    $https = static fn ($u): ?string => is_string($u) && preg_match('#^https://[^\s"<>]+$#i', trim($u)) ? mb_substr(trim($u), 0, 1000) : null;

    $chImage = null;
    $it = $channel->children($ns['itunes']);
    if (isset($it->image)) {
        $chImage = $https((string) ($it->image->attributes()['href'] ?? ''));
    }
    if ($chImage === null && isset($channel->image->url)) {
        $chImage = $https((string) $channel->image->url);
    }

    $items = [];
    $tz = new DateTimeZone(OUR_SERMONS_TZ);
    foreach ($channel->item as $item) {
        if (count($items) >= OUR_SERMONS_FEED_MAX_ITEMS) {
            break;
        }
        $title = our_sermons_clean_title((string) $item->title);
        $iti = $item->children($ns['itunes']);
        $enc = isset($item->enclosure) ? $item->enclosure->attributes() : null;
        $audio = $enc !== null ? $https((string) ($enc['url'] ?? '')) : null;
        $type = $enc !== null ? strtolower((string) ($enc['type'] ?? '')) : '';
        if ($audio !== null && $type !== '' && !str_starts_with($type, 'audio/')) {
            $audio = null;   // a video enclosure is not our audio
        }
        $guid = trim((string) ($item->guid ?? ''));
        if ($guid === '') {
            $guid = $audio ?? ($title !== '' ? 'title:' . $title : '');
        }
        if ($guid === '' || $title === '') {
            continue;
        }
        if (strlen($guid) > 255) {
            $guid = 'sha1:' . sha1($guid);
        }
        $published = null;
        $raw = trim((string) $item->pubDate);
        if ($raw !== '') {
            try {
                $published = (new DateTimeImmutable($raw))->setTimezone($tz)->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $published = null;
            }
        }
        $image = isset($iti->image) ? $https((string) ($iti->image->attributes()['href'] ?? '')) : null;
        $items[] = [
            'guid' => $guid,
            'title' => mb_substr($title, 0, 255),
            'published_at' => $published,
            'audio_url' => $audio,
            'duration' => our_sermons_duration((string) ($iti->duration ?? '')),
            'image' => $image ?? $chImage,
            'link' => $https((string) $item->link),
        ];
    }
    return ['ok' => true, 'title' => mb_substr(our_sermons_clean_title((string) $channel->title), 0, 255), 'image' => $chImage,
        'items' => $items, 'error' => ''];
}

/**
 * Copy a picture from $url to /uploads/sermons/artwork/ under a random name
 * (upload_guard's names and allow-list), so pages keep img-src 'self'. It must
 * really be a JPEG, PNG or WebP; with GD it is shrunk to 640 px and saved as
 * JPEG. $hosts: an allow-list for oEmbed thumbnails, null for a feed's artwork.
 *
 * @return string|null the stored URL
 */
function our_sermons_store_artwork(string $url, ?array $hosts = null, ?string $dir = null): ?string
{
    $r = our_sermons_http_get($url, ['hosts' => $hosts, 'max_bytes' => OUR_SERMONS_IMAGE_MAX_BYTES, 'accept' => 'image/*', 'timeout' => 15]);
    if (!$r['ok'] || $r['body'] === '') {
        return null;
    }
    $bytes = $r['body'];
    $info = @getimagesizefromstring($bytes);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]]) || $info[0] < 16 || $info[1] < 16 || $info[0] > 12000 || $info[1] > 12000) {
        return null;
    }
    $ext = $types[$info[2]];
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')
        && max($info[0], $info[1]) > OUR_SERMONS_ARTWORK_EDGE && $info[0] * $info[1] <= 40000000) {
        $src = @imagecreatefromstring($bytes);
        if ($src !== false) {
            $scale = OUR_SERMONS_ARTWORK_EDGE / max($info[0], $info[1]);
            $w = max(1, (int) round($info[0] * $scale));
            $h = max(1, (int) round($info[1] * $scale));
            $dst = imagecreatetruecolor($w, $h);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
            ob_start();
            imagejpeg($dst, null, 82);
            $out = (string) ob_get_clean();
            if ($out !== '') {
                $bytes = $out;
                $ext = 'jpg';
            }
        }
    }
    if (hod_upload_extension('x.' . $ext, HOD_UPLOAD_IMAGES) === '') {
        return null;
    }
    $dir ??= dirname(__DIR__, 2) . OUR_SERMONS_ARTWORK_URL;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    $name = hod_upload_name($ext);
    if (@file_put_contents(rtrim($dir, '/') . '/' . $name, $bytes) === false) {
        return null;
    }
    return OUR_SERMONS_ARTWORK_URL . $name;
}

/** Remove one of our artwork files from disk (only a name we made). */
function our_sermons_artwork_delete(?string $url, ?string $dir = null): void
{
    if ($url === null || !preg_match('#^' . preg_quote(OUR_SERMONS_ARTWORK_URL, '#') . '(\d{8}_[0-9a-f]{16}\.(jpg|png|webp))$#', $url, $m)) {
        return;
    }
    $dir ??= dirname(__DIR__, 2) . OUR_SERMONS_ARTWORK_URL;
    $path = rtrim($dir, '/') . '/' . $m[1];
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * The hosts an episode's audio is served from, for the sermon page's
 * media-src: the audio URL's own host, plus any host a tracking prefix
 * forwards to (Spotify for Creators writes
 * https://anchor.fm/s/…/podcast/play/…/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2F…).
 *
 * @return list<string> e.g. ['https://anchor.fm', 'https://d3ctxlq1ktw2nl.cloudfront.net']
 */
function our_sermons_media_hosts(?string $audioUrl): array
{
    if ($audioUrl === null || $audioUrl === '') {
        return [];
    }
    $hosts = [];
    $candidates = [$audioUrl];
    $decoded = $audioUrl;
    for ($i = 0; $i < 2; $i++) {
        $decoded = rawurldecode($decoded);
        $candidates[] = $decoded;
    }
    foreach ($candidates as $c) {
        if (preg_match_all('#https://([a-z0-9.-]+\.[a-z]{2,})(?=[/:?]|$)#i', $c, $m)) {
            foreach ($m[1] as $h) {
                $hosts['https://' . strtolower($h)] = true;
            }
        }
    }
    return array_slice(array_keys($hosts), 0, 6);
}
