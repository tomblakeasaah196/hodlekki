<?php
// /includes/mail/Team/team_config.php

/** Teamwork and alerts (PR 4), read through HOD\Mail\Team\MailTeam::config(). Nothing secret here. */

return [
    // A viewing / replying mark lasts this long unless the page renews it
    'presence_seconds' => 60,
    // How often an open conversation renews its mark and picks up the team's changes (mail-team.js)
    'poll_seconds' => 20,
    // Lines shown in the activity timeline, newest first
    'activity_limit' => 30,
    // New-mail pushes: more than this many for one person in one run become a single push
    'push_group' => 3,
    // Key alerts to a personal email: at most one per conversation per this many minutes, and this many a day
    'personal_per_thread_minutes' => 60,
    'personal_per_day' => 20,
    // Daily summary: a conversation open this long counts as "open over a day"
    'summary_open_hours' => 24,
];
