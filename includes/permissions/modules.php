<?php
// /includes/permissions/modules.php
//
// THE MODULE REGISTRY: every module (and sub-feature) the permission matrix has
// a row for. Keys are lowercase, stable and never reused — other code and the
// database refer to them. What a module OFFERS lives here because it describes
// the code; what each role or seat GETS lives in the database (perm_grants).
//
//   label        what the matrix shows
//   group        a section of includes/ui/nav.php (the matrix groups rows the same way)
//   max          highest ladder level the module has: none|view|create|edit|delete
//   extras       which of approve / export / manage exist for it
//   partitioned  true when the module's rows belong to a department (dashboard,
//                departments, requisition): a seat applies in its OWN department
//                without any owner row, limited to that department.
//                false: a seat applies only in the module's owner departments and
//                then covers the module's data (the department's data IS the module)
//   sub_units    false: a leader seat counts for its own department only here,
//                not its sub-units (requisitions are raised per department)
//   nav          optional: the abilities that make the nav link appear (default:
//                any ability beyond the holder's own records)
//   member       true: a member-facing page whose link every signed-in person sees
//   own          what "own records" means here (shown in the matrix)
//
// A dotted key is a sub-feature with an audience of its own; the matrix shows
// it indented under its module.

return [
    'dashboard' => ['label' => 'Dashboard', 'group' => 'command', 'max' => 'view', 'extras' => [], 'partitioned' => true,
        'own' => 'your alerts'],
    'member_portal' => ['label' => 'My Portal', 'group' => 'command', 'max' => 'edit', 'extras' => [], 'partitioned' => false,
        'member' => true, 'own' => 'your portal'],
    'profile' => ['label' => 'My Profile', 'group' => 'command', 'max' => 'edit', 'extras' => [], 'partitioned' => false,
        'member' => true, 'own' => 'your profile'],
    'notifications' => ['label' => 'Notifications', 'group' => 'command', 'max' => 'edit', 'extras' => [], 'partitioned' => false,
        'own' => 'your notifications'],
    'bible' => ['label' => 'The Bible', 'group' => 'command', 'max' => 'edit', 'extras' => ['manage'], 'partitioned' => false,
        'member' => true, 'own' => 'your notes, highlights and plans'],
    'hymnal' => ['label' => 'Hymnary', 'group' => 'command', 'max' => 'edit', 'extras' => ['approve', 'manage'], 'partitioned' => false,
        'member' => true, 'own' => 'your favourites'],
    'library' => ['label' => 'Library', 'group' => 'command', 'max' => 'create', 'extras' => [], 'partitioned' => false,
        'own' => 'your reservations'],
    'regions' => ['label' => 'Regional Hubs', 'group' => 'command', 'max' => 'delete', 'extras' => ['export', 'manage'], 'partitioned' => false,
        'member' => true, 'own' => 'your region'],
    'idi' => ['label' => 'Data Insights (IDI)', 'group' => 'command', 'max' => 'view', 'extras' => [], 'partitioned' => false],
    // Mail (docs/MAIL.md). Who works from a shared mailbox is NOT a matrix cell: it is that mailbox's own grants
    // (mail_grants: roles, department seats, departments, people). The matrix decides what follows.
    'mail' => ['label' => 'Mail: manage mailboxes (create, grants, suspend, quota)', 'group' => 'command', 'max' => 'none',
        'extras' => ['manage'], 'partitioned' => false, 'nav' => ['manage']],
    'mail.personal' => ['label' => 'Hold a personal mailbox (firstname.lastname@)', 'group' => 'command', 'max' => 'view', 'extras' => [],
        'partitioned' => false, 'nav' => []],
    'mail.ai' => ['label' => 'AI summaries and draft replies in Mail', 'group' => 'command', 'max' => 'view', 'extras' => [],
        'partitioned' => false, 'nav' => []],
    'mail.export_admin' => ['label' => 'Approve exporting a departed holder\'s mail (admin side)', 'group' => 'command', 'max' => 'none',
        'extras' => ['approve'], 'partitioned' => false, 'nav' => []],
    'mail.export_pastoral' => ['label' => 'Approve exporting a departed holder\'s mail (pastoral side)', 'group' => 'command', 'max' => 'none',
        'extras' => ['approve'], 'partitioned' => false, 'nav' => []],

    'congregation' => ['label' => 'Congregation', 'group' => 'growth', 'max' => 'delete', 'extras' => [], 'partitioned' => false],
    'reach' => ['label' => 'Reach (Evangelism)', 'group' => 'growth', 'max' => 'delete', 'extras' => ['export', 'manage'], 'partitioned' => false,
        'own' => 'leads assigned to you'],
    'embrace' => ['label' => 'Embrace (First Timers)', 'group' => 'growth', 'max' => 'delete', 'extras' => ['approve', 'export', 'manage'],
        'partitioned' => false, 'own' => 'first timers assigned to you'],
    'assimilation' => ['label' => 'Assimilation', 'group' => 'growth', 'max' => 'delete', 'extras' => ['export', 'manage'], 'partitioned' => false,
        'own' => 'people assigned to you'],
    'charis' => ['label' => 'Charis (Welfare)', 'group' => 'growth', 'max' => 'delete', 'extras' => ['approve', 'export', 'manage'],
        'partitioned' => false, 'own' => 'cases and tasks assigned to you'],
    'pastoral_notes' => ['label' => 'Notes marked for pastors (Charis, Embrace)', 'group' => 'growth', 'max' => 'view', 'extras' => [],
        'partitioned' => false],
    // Trainings (the new HOD Academy, TRAININGS-PLAN.md). It took over the retired 'academy' row: migration
    // 20261208090200 copied its cells and owners. Create = build and assign trainings; Edit = any training, mark
    // work; Delete = remove a training; Approve = confirm graduates (Membership Class → Member, Maturity Class →
    // ready to serve); Manage = the Academy's settings. Own records: a learner's trainings and handed-in work.
    'trainings' => ['label' => 'Trainings (HOD Academy)', 'group' => 'growth', 'max' => 'delete', 'extras' => ['approve', 'manage'],
        'partitioned' => false, 'member' => true, 'own' => 'your trainings and the work you hand in'],
    // Our sermons (OUR-SERMONS-PLAN.md): the members' sermon library, separate from the public Word Library.
    // View = browse and listen (everyone); Create = paste a link; Edit = check drafts, publish, hide;
    // Delete = remove a sermon; Manage = the RSS feeds. Owned by Envision (migration 20261213090100).
    'our_sermons' => ['label' => 'Our sermons', 'group' => 'command', 'max' => 'delete', 'extras' => ['manage'],
        'partitioned' => false, 'member' => true],

    'junior_church' => ['label' => 'Junior Church', 'group' => 'units', 'max' => 'delete', 'extras' => [], 'partitioned' => false],
    'river_of_life' => ['label' => 'River of Life (Choir)', 'group' => 'units', 'max' => 'delete', 'extras' => [], 'partitioned' => false],
    'envision' => ['label' => 'Envision (Media)', 'group' => 'units', 'max' => 'delete', 'extras' => ['approve', 'manage'], 'partitioned' => false],
    'special_events' => ['label' => 'Special Events', 'group' => 'units', 'max' => 'delete', 'extras' => ['manage'], 'partitioned' => false,
        'own' => 'events you crew'],
    'zoe' => ['label' => 'Zoe (Prayer)', 'group' => 'units', 'max' => 'delete', 'extras' => ['manage'], 'partitioned' => false,
        'own' => 'your prayer requests'],

    'departments' => ['label' => 'Departments', 'group' => 'operations', 'max' => 'edit', 'extras' => ['export', 'manage'], 'partitioned' => true],
    'tribes' => ['label' => 'Tribes', 'group' => 'operations', 'max' => 'delete', 'extras' => [], 'partitioned' => false,
        'own' => 'your tribe'],
    'events' => ['label' => 'Events & Attendance', 'group' => 'operations', 'max' => 'delete', 'extras' => ['export'], 'partitioned' => false],
    'events.private_notes' => ['label' => 'Pastors\' private event notes', 'group' => 'operations', 'max' => 'edit', 'extras' => [],
        'partitioned' => false],
    'events.unlock' => ['label' => 'Unlock a closed event', 'group' => 'operations', 'max' => 'edit', 'extras' => [], 'partitioned' => false],
    'contact_extractor' => ['label' => 'Contact extractor (every phone number)', 'group' => 'operations', 'max' => 'view', 'extras' => ['export'],
        'partitioned' => false],
    'sms_studio' => ['label' => 'SMS Studio', 'group' => 'operations', 'max' => 'delete', 'extras' => ['manage'], 'partitioned' => false],
    // Email campaigns (docs/EMAIL.md, "Campaigns"): a campaign belongs to the whole church or to one department.
    // Create = write and send your own; Edit = any in scope; Delete = drafts; Manage = email settings.
    'campaigns' => ['label' => 'Email Campaigns', 'group' => 'operations', 'max' => 'delete', 'extras' => ['manage'], 'partitioned' => true],
    'assets' => ['label' => 'Asset Management', 'group' => 'operations', 'max' => 'delete', 'extras' => [], 'partitioned' => false],
    'pastoral' => ['label' => 'Pastoral Desk', 'group' => 'operations', 'max' => 'edit', 'extras' => ['manage'], 'partitioned' => false,
        'own' => 'what you submitted'],
    'announcements' => ['label' => 'Announcements', 'group' => 'operations', 'max' => 'delete', 'extras' => ['approve'], 'partitioned' => false,
        'nav' => ['approve'], 'own' => 'what you submitted'],
    'testimonies' => ['label' => 'Testimonies (admin)', 'group' => 'operations', 'max' => 'delete', 'extras' => [], 'partitioned' => false],

    'finance' => ['label' => 'Finance & Stewardship', 'group' => 'stewardship', 'max' => 'delete', 'extras' => [], 'partitioned' => false],
    'finance.countersign' => ['label' => 'Countersign Charis events (Finance Director)', 'group' => 'stewardship', 'max' => 'none',
        'extras' => ['approve'], 'partitioned' => false],
    'requisition' => ['label' => 'Requisitions', 'group' => 'stewardship', 'max' => 'create', 'extras' => ['approve', 'export'],
        'partitioned' => true, 'sub_units' => false, 'own' => 'requisitions you submitted'],
    'requisition.disburse' => ['label' => 'Batch and disburse approved requisitions', 'group' => 'stewardship', 'max' => 'edit',
        'extras' => [], 'partitioned' => false],
    'roles' => ['label' => 'Roles & permissions', 'group' => 'stewardship', 'max' => 'view', 'extras' => ['manage'], 'partitioned' => false],
    'security' => ['label' => 'Security Centre', 'group' => 'stewardship', 'max' => 'edit', 'extras' => ['manage'], 'partitioned' => false],
    'alerts' => ['label' => 'Alerts Centre', 'group' => 'stewardship', 'max' => 'view', 'extras' => ['manage'], 'partitioned' => false],

    // The system monitor (nav entry 'system_health'). includes/monitor/bootstrap.php
    // asks can('monitor', 'view' | 'manage') through monitor_user_can().
    'monitor' => ['label' => 'System monitor', 'group' => 'system', 'max' => 'view', 'extras' => ['manage'], 'partitioned' => false],
    'ui_guide' => ['label' => 'Design System', 'group' => 'system', 'max' => 'view', 'extras' => [], 'partitioned' => false],
    // Outside services the ERP connects to (docs/INTEGRATIONS.md): Google for Trainings' live classes. Manage only.
    'integrations' => ['label' => 'Integrations: connect Google and other services', 'group' => 'system', 'max' => 'none',
        'extras' => ['manage'], 'partitioned' => false, 'nav' => ['manage']],
];
