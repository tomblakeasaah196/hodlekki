<?php
// /tests/couples_test.php
//
// BEHAVIOURAL tests for titles and couples (includes/couples.php). They
// EXECUTE the real functions against in-memory SQLite and pin what the owner
// asked for (26 Nov 2026):
//
//   - Pastor > Dir. > Min. > Bro./Sis.; an Associate Pastor (any live pastor
//     role) is Pastor; an Assistant/Deputy HOD is Min.; a sub-unit head is
//     Bro./Sis.; no gender and no seat = no title.
//   - A couple's wedding anniversary is ONE line, husband first, both first
//     names then his last name (28 Nov 2026): "Pastor Ebele & Min. Bolanle
//     Uzo-Peters"; no last name for him: first names only; one spouse in
//     church: "Bro. John Uzo & wife".
//   - Of a couple's two rows the assigned one is kept, else the husband's.
//   - Linking from Congregation links both ways, marks the spouse Married and
//     shares the anniversary; Single/Divorced or a new spouse unlinks; a
//     delete clears the link on the spouse.
//   - Before the migration nothing breaks.
//
// Run from the repo root:
//     php tests/couples_test.php
//
// Exit code 0 = all good, 1 = at least one failure. CLI only.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

require_once __DIR__ . '/../includes/couples.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function same(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function cp_db(bool $migrated = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, gender TEXT,
                spiritual_status TEXT, marital_status TEXT, wedding_anniversary TEXT'
        . ($migrated ? ', spouse_id INTEGER NULL' : '') . ')');
    return $pdo;
}

function cp_person(PDO $pdo, int $id, string $first, string $gender, string $status = 'Member', string $marital = 'Single', ?string $anniv = null): void
{
    $pdo->prepare('INSERT INTO users (id, first_name, last_name, gender, spiritual_status, marital_status, wedding_anniversary) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $first, 'Surname' . $id, $gender, $status, $marital, $anniv]);
}

function cp_row(PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

// Seat ranks as perm_seat_types ships them: pastor in charge 60, director 50, hod 40, deputy below.
// Min. starts at the Deputy (Assistant) HOD, 35; a sub-unit head is 30.
$floors = ['pastor' => 60, 'director' => 50, 'hod' => 35];

echo "Titles\n";
same('a male member is Bro.', 'Bro.', couples_title(['gender' => 'Male', 'spiritual_status' => 'Member'], 0, $floors));
same('a female worker is Sis.', 'Sis.', couples_title(['gender' => 'Female', 'spiritual_status' => 'Worker'], 0, $floors));
same('an HOD is Min.', 'Min.', couples_title(['gender' => 'Female', 'spiritual_status' => 'Worker'], 40, $floors));
same('an Assistant (Deputy) HOD is Min.', 'Min.', couples_title(['gender' => 'Male', 'spiritual_status' => 'Worker'], 35, $floors));
same('a sub-unit head is Bro.', 'Bro.', couples_title(['gender' => 'Male', 'spiritual_status' => 'Worker'], 30, $floors));
same('an Associate Pastor role is Pastor', 'Pastor', couples_title(['gender' => 'Male', 'spiritual_status' => 'Worker'], 0, $floors, true));
ok('deputy_hod is the Assistant HOD seat', couples_is_assistant_hod('deputy_hod', 'Deputy HOD'));
ok('a seat named "Assistant HOD" counts', couples_is_assistant_hod('asst_hod', 'Assistant HOD'));
ok('the HOD seat itself is not an assistant', !couples_is_assistant_hod('hod', 'Head of Department'));
ok('a sub-unit head is not an assistant HOD', !couples_is_assistant_hod('sub_unit_head', 'Sub-Unit Head'));

$roles = new PDO('sqlite::memory:');
$roles->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$roles->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, role_name TEXT, label TEXT, is_archived INTEGER DEFAULT 0)');
$roles->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, is_frozen INTEGER DEFAULT 0, starts_on TEXT NULL, ends_on TEXT NULL)');
$roles->exec("INSERT INTO roles (id, role_name, label) VALUES (1, 'Assoc_Pastor', 'Associate Pastor'), (2, 'Worker', 'Worker'), (3, 'Elder', 'Senior Pastor')");
$roles->exec("INSERT INTO user_roles (user_id, role_id, is_frozen, ends_on) VALUES (10, 1, 0, NULL), (11, 2, 0, NULL), (12, 1, 1, NULL), (13, 1, 0, '2026-01-01'), (14, 3, 0, NULL)");
same('live pastor roles, by name or label', [10 => true, 14 => true], couples_pastor_roles($roles, '2026-11-26'));
same('a Director is Dir.', 'Dir.', couples_title(['gender' => 'Male', 'spiritual_status' => 'Worker'], 50, $floors));
same('a Pastor in charge seat is Pastor', 'Pastor', couples_title(['gender' => 'Male', 'spiritual_status' => 'Worker'], 60, $floors));
same('spiritual status Pastor wins over an HOD seat', 'Pastor', couples_title(['gender' => 'Male', 'spiritual_status' => 'Pastor'], 40, $floors));
same('no gender, no seat: no title', '', couples_title(['gender' => null, 'spiritual_status' => 'Member'], 0, $floors));
same('no seat types yet: gender only', 'Sis.', couples_title(['gender' => 'Female', 'spiritual_status' => 'Worker'], 40, []));

echo "Couple lines\n";
same('husband first, then his last name', 'Pastor Ebele & Min. Bolanle Uzo-Peters',
    couples_name(['first_name' => 'Bolanle', 'last_name' => 'Adeyemi', 'gender' => 'Female', 'title' => 'Min.'],
        ['first_name' => 'Ebele', 'last_name' => 'Uzo-Peters', 'gender' => 'Male', 'title' => 'Pastor']));
same('no last name for him: first names only', 'Pastor Ebele & Min. Bolanle',
    couples_name(['first_name' => 'Bolanle', 'last_name' => 'Adeyemi', 'gender' => 'Female', 'title' => 'Min.'],
        ['first_name' => 'Ebele', 'last_name' => '  ', 'gender' => 'Male', 'title' => 'Pastor']));
same('husband alone in church', 'Bro. John Uzo & wife', couples_name(['first_name' => 'John', 'last_name' => 'Uzo', 'gender' => 'Male', 'title' => 'Bro.'], null));
same('wife alone in church', 'Sis. Ada Okafor & husband', couples_name(['first_name' => 'Ada', 'last_name' => 'Okafor', 'gender' => 'Female', 'title' => 'Sis.'], null));

echo "Linking\n";
$pdo = cp_db();
cp_person($pdo, 1, 'Ebele', 'Male', 'Pastor', 'Married');
cp_person($pdo, 2, 'Bolanle', 'Female', 'Worker', 'Single', null);
cp_person($pdo, 3, 'Ada', 'Female');
same('linking succeeds', null, couples_sync($pdo, 1, 'Married', 2, '2015-12-05'));
same('the person points at the spouse', 2, (int) cp_row($pdo, 1)['spouse_id']);
same('the spouse points back', 1, (int) cp_row($pdo, 2)['spouse_id']);
same('the spouse is marked Married', 'Married', cp_row($pdo, 2)['marital_status']);
same('the spouse gets the anniversary', '2015-12-05', cp_row($pdo, 2)['wedding_anniversary']);
same('a blank date takes the spouse\'s', null, couples_sync($pdo, 2, 'Married', 1, null));
same('… and keeps it', '2015-12-05', cp_row($pdo, 2)['wedding_anniversary']);
same('nobody is their own spouse', 'Choose someone else as their spouse.', couples_sync($pdo, 1, 'Married', 1, null));
ok('a missing spouse is refused', couples_sync($pdo, 1, 'Married', 99, null) !== null);

couples_sync($pdo, 1, 'Married', 3, '2015-12-05');
same('a new spouse is linked', 3, (int) cp_row($pdo, 1)['spouse_id']);
same('the old spouse is let go', null, cp_row($pdo, 2)['spouse_id']);
couples_sync($pdo, 2, 'Married', 3, '2016-01-01');
same('taking someone\'s spouse unlinks their old partner', null, cp_row($pdo, 1)['spouse_id']);
same('… and links the new pair', 2, (int) cp_row($pdo, 3)['spouse_id']);
couples_sync($pdo, 3, 'Divorced', 2, null);
same('Divorced clears this side', null, cp_row($pdo, 3)['spouse_id']);
same('… and the other side', null, cp_row($pdo, 2)['spouse_id']);
couples_sync($pdo, 1, 'Married', 2, '2015-12-05');
couples_after_status($pdo, 2, 'Single', null);
same('Single on the profile page unlinks both', [null, null], [cp_row($pdo, 1)['spouse_id'], cp_row($pdo, 2)['spouse_id']]);
couples_sync($pdo, 1, 'Married', 2, '2015-12-05');
couples_after_status($pdo, 2, 'Married', '2015-12-06');
same('a date changed on the profile reaches the spouse', '2015-12-06', cp_row($pdo, 1)['wedding_anniversary']);
couples_forget($pdo, 2);
same('a delete clears the link on the spouse', null, cp_row($pdo, 1)['spouse_id']);

echo "Charis rows\n";
$pdo = cp_db();
cp_person($pdo, 1, 'Ebele', 'Male', 'Pastor', 'Married', '2015-12-05');
cp_person($pdo, 2, 'Bolanle', 'Female', 'Worker', 'Married', '2015-12-05');
cp_person($pdo, 3, 'John', 'Male', 'Member', 'Married', '2010-12-20');
cp_person($pdo, 4, 'Ngozi', 'Female', 'Member');
couples_sync($pdo, 1, 'Married', 2, '2015-12-05');
$seats = ['ranks' => [2 => 40], 'floors' => $floors];
$wa = static fn (int $id, string $first, ?int $assignment = null): array => [
    'target_user_id' => $id, 'event_type' => 'Wedding_Anniversary', 'event_date' => '2015-12-05',
    'first_name' => $first, 'last_name' => 'Surname' . $id, 'assignment_id' => $assignment,
];
$rows = couples_label_rows($pdo, [$wa(2, 'Bolanle'), $wa(1, 'Ebele'), array_merge($wa(3, 'John'), ['event_date' => '2010-12-20'])], 'target_user_id', ['Wedding_Anniversary'], $seats);
same('a couple is one row', 2, count($rows));
same('the couple line ends with his last name', 'Pastor Ebele & Min. Bolanle Surname1', $rows[0]['display_name']);
same('the husband\'s row is kept', 1, $rows[0]['target_user_id']);
same('one spouse in church', 'Bro. John Surname3 & wife', $rows[1]['display_name']);
$rows = couples_label_rows($pdo, [$wa(2, 'Bolanle')], 'target_user_id', ['Wedding_Anniversary'], $seats);
same('the wife\'s row still ends with HIS last name', 'Pastor Ebele & Min. Bolanle Surname1', $rows[0]['display_name']);
$rows = couples_label_rows($pdo, [$wa(1, 'Ebele'), $wa(2, 'Bolanle', 77)], 'target_user_id', ['Wedding_Anniversary'], $seats);
same('the assigned row is kept', [1, 2, 77], [count($rows), $rows[0]['target_user_id'], $rows[0]['assignment_id']]);

$bd = couples_label_rows($pdo, [
    ['target_user_id' => 4, 'event_type' => 'Birthday', 'first_name' => 'Ngozi', 'last_name' => 'Surname4'],
    ['target_user_id' => 'jc_4', 'event_type' => 'Birthday', 'first_name' => 'Tobi', 'last_name' => 'Ade (JC)'],
], 'target_user_id', ['Wedding_Anniversary'], $seats);
same('a birthday keeps the full name with its title', 'Sis. Ngozi Surname4', $bd[0]['display_name']);
same('a Junior Church child gets no title', 'Tobi Ade (JC)', $bd[1]['display_name']);

$recap = couples_label_rows($pdo, [
    ['id' => 1, 'source' => 'user', 'event_type' => 'Anniversary', 'event_date' => '5th Dec', 'first_name' => 'Ebele', 'last_name' => 'Uzo-Peters', 'display_picture' => null],
    ['id' => 2, 'source' => 'user', 'event_type' => 'Anniversary', 'event_date' => '5th Dec', 'first_name' => 'Bolanle', 'last_name' => 'Adeyemi', 'display_picture' => '/w.jpg'],
    ['id' => 1, 'source' => 'jc', 'event_type' => 'JC_Birthday', 'event_date' => '9th Dec', 'first_name' => 'Kid', 'last_name' => 'K'],
], 'id', ['Anniversary'], $seats);
same('the recap folds the couple too', 2, count($recap));
same('… named with his last name', 'Pastor Ebele & Min. Bolanle Uzo-Peters', $recap[0]['display_name']);
same('… keeping the photo they have', '/w.jpg', $recap[0]['display_picture']);
same('a Junior Church id is not read as a member', '', $recap[1]['title']);

echo "Before the migration\n";
$old = cp_db(false);
cp_person($old, 1, 'Ebele', 'Male', 'Pastor', 'Married', '2015-12-05');
same('sync is a no-op', null, couples_sync($old, 1, 'Married', 2, null));
$rows = couples_label_rows($old, [$wa(1, 'Ebele')], 'target_user_id', ['Wedding_Anniversary'], $seats);
same('rows are still named', 'Pastor Ebele Surname1 & wife', $rows[0]['display_name']);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
