<?php
// /live_radio.php
// The Sunday service audio player. The stream address and the on-air switch
// are set in Envision (system_settings zeno_stream_url / is_live_audio_on,
// the same two api/public_sermons_api.php reads); they are read here so the
// strict CSP can allow exactly that stream (Zeno redirects to its own
// *.zeno.fm hosts). Script: assets/ui/js/pages/live-radio.js.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ui/standalone.php';

$radio = ['stream' => '', 'live' => false];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('zeno_stream_url', 'is_live_audio_on')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    // An http:// address is upgraded: the page is https, and browsers upgrade
    // mixed audio anyway (Zeno serves both).
    $url = preg_replace('#^http://#i', 'https://', trim((string) ($rows['zeno_stream_url'] ?? '')));
    $radio['stream'] = preg_match('#^https://[^\s"<>]+$#i', $url) ? $url : '';
    $radio['live'] = (int) ($rows['is_live_audio_on'] ?? 0) === 1;
} catch (Throwable $e) {
    error_log('live_radio settings: ' . $e->getMessage());
}
$mediaSources = ['https://*.zeno.fm'];
if ($radio['stream'] !== '') {
    $parts = parse_url($radio['stream']);
    if (!empty($parts['host'])) {
        $mediaSources[] = 'https://' . $parts['host'] . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}

ui_standalone_open('Live Service', [
    'bare' => true,
    'boot' => true,
    'csp' => ['media-src' => array_values(array_unique($mediaSources))],
    'scripts' => ['/assets/ui/js/pages/live-radio.js'],
]);
echo ui_json_script('radio-config', $radio);
$onAir = $radio['live'] && $radio['stream'] !== '';
?>
<div class="flex min-h-screen items-center justify-center bg-gradient-to-br from-[#070c24] via-hodBlue to-black p-4 text-white">
    <div class="w-full max-w-sm rounded-ui-xl border border-white/10 bg-white/5 p-8 text-center shadow-ui-lg">
        <p class="text-xs font-semibold uppercase tracking-widest text-white/60">Household of David Lekki</p>

        <div class="relative mx-auto my-8 grid h-40 w-40 place-items-center">
            <span class="absolute inset-0 rounded-full border-4 <?= $onAir ? 'border-hodRed/40 motion-safe:animate-ping' : 'border-white/10' ?>" id="radio-ring" aria-hidden="true"></span>
            <span class="relative grid h-36 w-36 place-items-center rounded-full bg-white p-5">
                <img src="/assets/images/hod_logo.svg" alt="HOD Lekki" class="h-full w-full object-contain" width="104" height="104">
            </span>
            <span class="absolute -bottom-2 rounded-full px-3 py-1 text-xs font-bold tracking-widest <?= $onAir ? 'bg-hodRed' : 'bg-white/20' ?>" id="radio-badge"><?= $onAir ? 'LIVE' : 'OFFLINE' ?></span>
        </div>

        <h1 class="font-display text-3xl font-bold">Sunday Service</h1>
        <p class="mt-1 text-white/70">Audio Broadcast</p>

        <audio id="radio-player" preload="none"></audio>

        <div class="mt-8 flex flex-col items-center gap-5">
            <div class="flex h-7 items-end gap-1" id="radio-eq" aria-hidden="true">
                <span class="h-2.5 w-1 rounded-full bg-hodRed opacity-40"></span>
                <span class="h-5 w-1 rounded-full bg-hodRed opacity-40"></span>
                <span class="h-4 w-1 rounded-full bg-hodRed opacity-40"></span>
                <span class="h-6 w-1 rounded-full bg-hodRed opacity-40"></span>
                <span class="h-3 w-1 rounded-full bg-hodRed opacity-40"></span>
            </div>
            <button type="button" class="grid h-20 w-20 place-items-center rounded-full bg-hodRed text-white shadow-ui-lg transition hover:scale-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:scale-100"
                id="radio-toggle" data-action="radio.toggle" aria-label="Play" aria-pressed="false"<?= $onAir ? '' : ' disabled' ?>>
                <svg class="ml-1 h-9 w-9" id="radio-icon-play" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                <svg class="h-9 w-9" id="radio-icon-pause" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true" hidden><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
            </button>
        </div>

        <p class="mt-8 text-sm text-white/60"><?= $onAir ? 'You can minimize this window to keep listening.' : 'The broadcast is off air.' ?></p>
    </div>
</div>
<?php
ui_standalone_close();
