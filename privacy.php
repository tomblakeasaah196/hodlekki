<?php
// /privacy.php
// Household of David Lekki Centre: the public privacy notice (hodlekki.org/privacy).
// What the church's platform keeps about people, why, who sees it, and how to ask
// about it. It also carries the Google API Services disclosure that Google requires
// before the "HOD Lekki" sign-in (Trainings' Meet links and attendance) can go live:
// how the connected Google account's data is used, stored and shared, and the
// Limited Use statement. Keep it true to what the code does (docs/INTEGRATIONS.md,
// AGENTS.md "AI"); change the date below whenever the notice changes.
//
// Baseline security headers: like verify.php, this page does not load
// includes/db.php, which is where other pages get them.
require_once __DIR__ . '/includes/security_headers.php';
security_send_baseline_headers();
session_start();

require_once __DIR__ . '/includes/ui/public.php';
require_once __DIR__ . '/includes/ui/components.php';

const PRIVACY_UPDATED = '12 November 2026';
const PRIVACY_CONTACT = 'info@hodlekki.org';

ui_public_open([
    'title' => 'Privacy',
    'mode' => 'strict',
    'indexable' => true,
    'home' => '/',
]);
?>
<div class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
    <div class="mb-8">
        <div class="ui-title-row">
            <h1 class="ui-h1">Privacy</h1>
            <?= ui_info('Household of David Lekki Centre runs hodlekki.org for its members, workers and visitors.', 'About this notice') ?>
        </div>
        <p class="mt-2 text-ink-muted">How we look after your details. Updated <?= ui_e(PRIVACY_UPDATED) ?>.</p>
    </div>

    <article class="ui-card">
        <div class="ui-card__body ui-prose">
            <h2>What we keep</h2>
            <ul>
                <li>Your name, phone number, email and the details you give on our forms (Connect, registration, testimonies, requests).</li>
                <li>Your church life with us: attendance, departments, follow-ups, trainings and their results.</li>
                <li>Messages we send you by email, text or app notification, and your replies.</li>
                <li>Basic technical records to keep the site safe and working: sign-ins, errors and page speed.</li>
            </ul>

            <h2>Why we keep it</h2>
            <ul>
                <li>To welcome, care for and stay in touch with you.</li>
                <li>To run services, departments, events and trainings.</li>
                <li>To keep the site secure and working well.</li>
            </ul>

            <h2>Who sees it</h2>
            <ul>
                <li>Only church staff and workers whose role needs it. Each role sees only its own part.</li>
                <li>We never sell your details or use them for advertising.</li>
                <li>Services we rely on handle data for us: our web host, Cloudflare (security), our text-message provider, and Google (see below).</li>
                <li>When staff use AI help, only the text they choose is sent to Google's Gemini service. Personal records are not sent.</li>
                <li>Training slides are shown through Microsoft's Office viewer, which reads the slide file from our site.</li>
                <li>Sermon audio plays from Spotify's servers; a sermon video plays from YouTube (no-cookie mode) only when you choose Watch.</li>
            </ul>

            <h2>Cookies</h2>
            <p>We use cookies only to keep you signed in and protect forms.</p>

            <h2>Google account data</h2>
            <p>The church may connect one Google account to run online classes.</p>
            <ul>
                <li><strong>What we access:</strong> that account's email address, the calendar events we create for classes, and attendance for those Google Meet calls (each participant's display name and time in the call).</li>
                <li><strong>How we use it:</strong> only to create the class's Meet link and to record who attended. Nothing else.</li>
                <li><strong>How we store it:</strong> the connection key is encrypted on our server. Attendance is kept in the training's records.</li>
                <li><strong>Sharing:</strong> we do not share or sell Google data. Tutors and the Academy team see attendance for their own classes.</li>
                <li><strong>Removing it:</strong> an admin can disconnect at any time, or the account owner can remove access in their Google Account settings.</li>
                <li><strong>Limited Use:</strong> HOD Lekki's use and transfer of information received from Google APIs follows the
                    <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener" target="_blank">Google API Services User Data Policy</a>,
                    including the Limited Use requirements.</li>
            </ul>

            <h2>Your choices</h2>
            <ul>
                <li>Ask to see the details we hold about you.</li>
                <li>Ask us to correct them, or to delete what we no longer need.</li>
                <li>Stop our news messages at any time from the message or your profile.</li>
            </ul>

            <h2>Contact us</h2>
            <p>Write to <a href="mailto:<?= ui_e(PRIVACY_CONTACT) ?>"><?= ui_e(PRIVACY_CONTACT) ?></a> about anything on this page.</p>
        </div>
    </article>
</div>
<?php
ui_public_close();
