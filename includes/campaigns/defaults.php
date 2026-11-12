<?php
// /includes/campaigns/defaults.php
//
// The rules each module starts with. They are created SWITCHED OFF by
// automation_ensure_defaults(); someone with the module's manage right reads
// them, edits the words in the Automation tab, and switches them on.
//
// Scripture blocks carry only a reference: the words come from the Bible
// module's KJV when the email is rendered (AGENTS.md: scripture is never typed
// into code). Service times are not written in: the church's times can change
// and a wrong time in a welcome email is worse than none.

function automation_default_name(string $module): string
{
    return [
        'embrace'      => 'First-timer journey',
        'assimilation' => 'Absence follow-up',
        'charis'       => 'Absence follow-up',
    ][$module] ?? 'Automation';
}

function automation_default_config(string $module): array
{
    return match ($module) {
        'embrace'      => automation_default_journey(),
        'assimilation' => automation_default_absence('assimilation'),
        'charis'       => automation_default_absence('charis'),
        default        => [],
    };
}

/** One weekly email: heading, paragraphs, optional scripture and button, signature. */
function automation_default_doc(string $subject, string $preheader, string $heading, array $paragraphs,
                                ?string $scripture = null, ?array $button = null, string $signature = 'The Embrace Team'): array
{
    $blocks = [
        ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => $heading],
        email_doc_paragraphs(...$paragraphs),
    ];
    if ($scripture !== null) {
        $blocks[] = ['type' => 'scripture', 'ref' => $scripture];
    }
    if ($button !== null) {
        $blocks[] = ['type' => 'button', 'label' => $button[0], 'href' => $button[1], 'style' => 'primary', 'align' => 'left'];
    }
    $blocks[] = ['type' => 'signature', 'name' => $signature, 'line' => CAMPAIGNS_CHURCH_NAME];
    $blocks[] = ['type' => 'footer'];
    return ['v' => EMAIL_DOC_VERSION, 'subject' => $subject, 'preheader' => $preheader, 'blocks' => $blocks];
}

function automation_default_journey(): array
{
    $home = site_url('/');
    $sermons = site_url('/sermons');
    $reply = 'If you have a question or something we can pray with you about, just reply to this email. A real person from our Embrace team reads every reply.';

    $weeks = [
        [
            'email' => automation_default_doc(
                'Welcome to the family, {{first_name}}',
                'We were so glad to worship with you.',
                'We are so glad you came',
                [
                    'Hi {{first_name}},',
                    'Thank you for worshipping with us at ' . CAMPAIGNS_CHURCH_NAME . '. Whether it was your very first visit or you had been meaning to come for a while, it was a joy to have you, and we would love to see you again.',
                    'Join us again on {{next_sunday}}, and at our Thursday midweek service.',
                    $reply,
                ],
                'Psalm 122:1',
                ['Visit our website', $home]
            ),
            'sms' => 'Hi {{first_name}}, welcome to Household of David Lekki! We were so glad to worship with you. See you again this Sunday. - Embrace Team',
        ],
        [
            'email' => automation_default_doc(
                'Getting to know us, {{first_name}}',
                'A little more about your new church family.',
                'Getting to know us',
                [
                    'Hi {{first_name}},',
                    'It has been a week since you joined us. Household of David is a family, and every Sunday and Thursday is a chance to grow together in the Word and in prayer.',
                    'If you missed a message, you can catch up on recent sermons on our website.',
                    $reply,
                ],
                'Hebrews 10:24-25',
                ['Listen to recent sermons', $sermons]
            ),
            'sms' => 'Hi {{first_name}}, it was good to have you at Household of David Lekki. Recent sermons are on our website. See you on Sunday! - Embrace Team',
        ],
        [
            'email' => automation_default_doc(
                'Find your place, {{first_name}}',
                'There is room for you to belong and to serve.',
                'Find your place',
                [
                    'Hi {{first_name}},',
                    'Church is more than a service on Sunday. Our departments and regional hubs are where friendships grow and where your gifts can bless others.',
                    'Ask anyone from the Embrace team on Sunday about joining a department or a hub near you.',
                    $reply,
                ],
                '1 Peter 4:10'
            ),
            'sms' => 'Hi {{first_name}}, there is a place for you at Household of David Lekki. Ask the Embrace team on Sunday about joining a department. - Embrace Team',
        ],
        [
            'email' => automation_default_doc(
                'Growing together, {{first_name}}',
                'A word for your week.',
                'Growing together',
                [
                    'Hi {{first_name}},',
                    'We are praying for you this week. Growing in faith is a journey we take together, one step and one Sunday at a time.',
                    'We look forward to seeing you on {{next_sunday}}.',
                    $reply,
                ],
                'Psalm 119:105'
            ),
            'sms' => 'Hi {{first_name}}, we are praying for you this week. We look forward to seeing you on Sunday at Household of David Lekki. - Embrace Team',
        ],
        [
            'email' => automation_default_doc(
                'Serve with us, {{first_name}}',
                'Every member of the body matters.',
                'Serve with us',
                [
                    'Hi {{first_name}},',
                    'Many people found their church family by serving: welcoming guests, singing, helping with media, or caring for the children. If you would like to find out more, speak to anyone from the Embrace team on Sunday.',
                    $reply,
                ],
                'Romans 12:4-5'
            ),
            'sms' => 'Hi {{first_name}}, would you like to serve at Household of David Lekki? Speak to the Embrace team on Sunday. - Embrace Team',
        ],
        [
            'email' => automation_default_doc(
                "We're glad you're here, {{first_name}}",
                'A blessing for you and your household.',
                "We're glad you're here",
                [
                    'Hi {{first_name}},',
                    'It has been a joy to welcome you over these past weeks. You are part of this family, and our doors and hearts are always open to you.',
                    $reply,
                ],
                'Numbers 6:24-26'
            ),
            'sms' => 'Hi {{first_name}}, it has been a joy to welcome you to Household of David Lekki. You are part of this family. God bless you! - Embrace Team',
        ],
    ];

    return [
        'from_name' => 'HOD Lekki Embrace Team',
        'reply_to'  => '',
        'audience'  => ['stop_status' => '3rd_Timer', 'weeks' => 6],
        'steps'     => [
            [
                'key'    => 'welcome',
                'label'  => 'Monday welcome',
                'target' => 'member',
                'when'   => ['weekday' => 1, 'time' => '10:00'],
                'series' => $weeks,
            ],
            [
                'key'        => 'midweek',
                'label'      => 'Midweek reminder',
                'target'     => 'member',
                'when'       => ['weekday' => 3, 'time' => '12:00'],
                'date_bound' => true,
                'email'      => automation_default_doc(
                    'See you at midweek service tomorrow?',
                    'Thursday midweek service: come as you are.',
                    'See you tomorrow?',
                    [
                        'Hi {{first_name}},',
                        'Our midweek service is tomorrow, Thursday. It is a refreshing hour in the middle of the week, and we would love to see you there. Come as you are.',
                    ]
                ),
                'sms' => 'Hi {{first_name}}, our midweek service is tomorrow (Thursday). We would love to see you at Household of David Lekki. - Embrace Team',
            ],
            [
                'key'        => 'sunday',
                'label'      => 'Sunday reminder',
                'target'     => 'member',
                'when'       => ['weekday' => 6, 'time' => '12:00'],
                'date_bound' => true,
                'email'      => automation_default_doc(
                    'See you tomorrow, {{first_name}}?',
                    'There is a seat for you this Sunday.',
                    'There is a seat for you tomorrow',
                    [
                        'Hi {{first_name}},',
                        'Tomorrow is Sunday, and there is a seat with your name on it. Bring a friend or come on your own: either way, we cannot wait to worship with you.',
                    ],
                    'Psalm 118:24'
                ),
                'sms' => 'Hi {{first_name}}, tomorrow is Sunday and there is a seat for you at Household of David Lekki. See you there! - Embrace Team',
            ],
        ],
    ];
}

function automation_default_absence(string $module): array
{
    $sermons = site_url('/sermons');
    $team = $module === 'charis' ? 'Charis' : 'Assimilation';
    $statuses = $module === 'charis' ? ['Member', 'Worker', 'Pastor'] : ['Visitor', 'Non_Member'];

    return [
        'from_name' => CAMPAIGNS_CHURCH_NAME,
        'reply_to'  => '',
        'audience'  => ['statuses' => $statuses],
        'when'      => ['weekday' => 1, 'time' => '09:00'],
        'steps'     => [
            [
                'key'       => 'miss_note',
                'label'     => 'We miss you',
                'target'    => 'member',
                'threshold' => 2,
                'email'     => automation_default_doc(
                    'We miss you, {{first_name}}',
                    'Your church family is thinking of you.',
                    'We miss you',
                    [
                        'Hi {{first_name}},',
                        'We have missed you at church these past couple of Sundays, and we wanted you to know you are in our thoughts and prayers.',
                        'If anything is going on (a busy season, a new job, illness, or something you would like prayer for), just reply to this email. Someone from our team will get back to you.',
                        'Until we see you again, you can listen to recent sermons on our website.',
                    ],
                    'Philippians 1:3',
                    ['Listen to recent sermons', $sermons],
                    'Your church family'
                ),
                'sms' => 'Hi {{first_name}}, we have missed you at church and you are in our prayers. We would love to see you on Sunday. - Household of David Lekki',
            ],
            [
                'key'       => 'team_alert',
                'label'     => 'Tell the team',
                'target'    => 'team',
                'threshold' => 4,
                'alert'     => [
                    'title'   => '{{streak}} Sundays away: {{full_name}}',
                    'message' => "{{full_name}} has missed {{streak}} Sundays in a row (last in church: {{last_attended}}). Please reach out this week. ({$team} automation)",
                ],
            ],
            [
                'key'       => 'pastor_alert',
                'label'     => 'Tell their pastor',
                'target'    => 'pastor',
                'threshold' => 8,
                'alert'     => [
                    'title'   => '{{streak}} Sundays away: {{full_name}}',
                    'message' => "{{full_name}} has missed {{streak}} Sundays in a row (last in church: {{last_attended}}). The {$team} team was told at 4. A call from you may help.",
                ],
            ],
        ],
    ];
}
