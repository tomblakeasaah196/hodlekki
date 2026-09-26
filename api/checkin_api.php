<?php
/**
 * ============================================================================
 * ATTENDANCE CHECK-IN — Backend API
 * File: /api/checkin_api.php
 * ----------------------------------------------------------------------------
 * Public-facing check-in flow. Given an event token and a phone number, it:
 *   - looks the person up (users OR event_registrations),
 *   - confirms/edits their name,
 *   - marks them present,
 *   - or lets a walk-in register on the spot (auto-marked present).
 * Also serves the rotating blessing scripture.
 * ============================================================================
 */

require_once '../includes/db.php';
header('Content-Type: application/json');

/* ---- CSRF (public page sets it in a session; check for state-changing POSTs) ---- */
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf'] ?? '';
    if ($token === '' || empty($_SESSION['checkin_csrf']) || !hash_equals($_SESSION['checkin_csrf'], $token)) {
        echo json_encode(['status'=>'error','message'=>'Invalid security token. Refresh the page and try again.']);
        exit;
    }
}

/* ---- Normalize a Nigerian phone to 234XXXXXXXXXX ---- */
function ci_normalize_phone($phone) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($p === '') return null;
    if (strpos($p, '00') === 0) $p = substr($p, 2);
    if (strlen($p) === 11 && $p[0] === '0') {
        $p = '234' . substr($p, 1);
    } elseif (strlen($p) === 10) {
        $p = '234' . $p;
    } elseif (strlen($p) === 13 && substr($p, 0, 3) !== '234') {
        $p = '234' . ltrim($p, '0');
    }
    return preg_match('/^234[789][01]\d{8}$/', $p) ? $p : null;
}

/* ---- Resolve an event by its registration token ---- */
function ci_find_event_by_token(PDO $pdo, $token) {
    $st = $pdo->prepare("SELECT id, title, event_category, event_date, end_date,
                                description, location, banner_image_url, registration_token
                         FROM events WHERE registration_token = ? LIMIT 1");
    $st->execute([$token]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) return null;
    if (!empty($e['event_date'])) {
        $ts = strtotime($e['event_date']);
        $e['nice_date'] = date('l, F j, Y', $ts);
        $e['nice_time'] = date('g:i A', $ts);
    }
    return $e;
}

/**
 * Determine which day of a multi-day event TODAY is, plus the check-in date.
 * For a single-day event, returns day 1 / today's date. For a multi-day event,
 * computes Day N from the start date. Returns null if outside the event window.
 */
function ci_current_day($event) {
    $start = strtotime(date('Y-m-d', strtotime($event['event_date'])));
    $today = strtotime(date('Y-m-d'));
    $checkinDate = date('Y-m-d', $today);

    $endDate = !empty($event['end_date']) ? $event['end_date'] : date('Y-m-d', strtotime($event['event_date']));
    $end = strtotime(date('Y-m-d', strtotime($endDate)));
    $totalDays = max(1, (int)round(($end - $start) / 86400) + 1);

    // Check-in is allowed ONLY on the event's days (event_date .. end_date).
    // No day-before or day-after grace — the event closes at end_date.
    $inWindow = ($today >= $start) && ($today <= $end);

    $day = (int)round(($today - $start) / 86400) + 1;
    if ($day < 1) $day = 1;
    if ($day > $totalDays) $day = $totalDays;

    return [
        'in_window'=>$inWindow,
        'day'=>$day,
        'total_days'=>$totalDays,
        'day_label'=> ($totalDays > 1 ? 'Day '.$day : ''),
        'checkin_date'=>$checkinDate,
    ];
}

/**
 * Find a member by phone in the users table (matches the exact stored value
 * AND common variants like 0-prefix / +234 / 234). Returns the user row or null.
 */
function ci_find_user_by_phone(PDO $pdo, $phone) {
    $norm = ci_normalize_phone($phone);
    if ($norm === null) return null;
    // build candidate forms to match whatever is stored in users.phone
    $zero = '0' . substr($norm, 3);          // 23490... -> 090...
    $plus = '+' . $norm;                      // +23490...
    $st = $pdo->prepare("SELECT id, first_name, last_name, phone
                         FROM users
                         WHERE phone = :n OR phone = :z OR phone = :p
                         LIMIT 1");
    $st->execute(['n'=>$norm, 'z'=>$zero, 'p'=>$plus]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

/**
 * Record attendance in the EXISTING attendance table (the events module's
 * roster) IF the person is a member (found in users). No-ops for non-members.
 */
/**
 * IDI staff who assist with check-ins. Per your requirement, assisted
 * check-ins are credited to one of these two IDI members, chosen at random.
 * Returns one of [2, 3].
 */
function ci_idi_staff_id() {
    $staff = [2, 3];
    return $staff[array_rand($staff)];
}

/**
 * Record attendance in the EXISTING attendance table (the events module's
 * roster) IF the person is a member (found in users). No-ops for non-members.
 */
function ci_write_attendance(PDO $pdo, $eventId, $userId, $checkedInBy, $attendanceDate) {
    if (!$userId) return;
    // avoid duplicate attendance rows for the same user + event + day
    $dup = $pdo->prepare("SELECT id FROM attendance WHERE event_id = ? AND user_id = ? AND COALESCE(attendance_date, DATE(check_in_time)) = ?");
    $dup->execute([$eventId, $userId, $attendanceDate]);
    if ($dup->fetch()) return;
    $pdo->prepare("INSERT INTO attendance (event_id, user_id, status, check_in_time, checked_in_by, attendance_date)
                   VALUES (?, ?, 'Present', NOW(), ?, ?)")
        ->execute([$eventId, $userId, $checkedInBy, $attendanceDate]);
}

/* ---- The 30 rotating blessing scriptures (KJV) + a concise adapted prayer ---- */
/* Each prayer uses {name} (the person's first name) — substituted on the frontend. */
function ci_blessings() {
    return [
        ['ref'=>"Numbers 6:24-26", 'text'=>"The LORD bless thee, and keep thee: The LORD make his face shine upon thee, and be gracious unto thee: The LORD lift up his countenance upon thee, and give thee peace.",
         'prayer'=>"{name}, may the LORD Himself watch over you, shine His favour upon you, and fill you with His perfect peace today and always."],
        ['ref'=>"Jeremiah 29:11", 'text'=>"For I know the thoughts that I think toward you, saith the LORD, thoughts of peace, and not of evil, to give you an expected end.",
         'prayer'=>"{name}, the LORD has a good plan for your life — a future and a hope. Trust Him with today, for He is already working it out."],
        ['ref'=>"Psalm 121:7-8", 'text'=>"The LORD shall preserve thee from all evil: he shall preserve thy soul. The LORD shall preserve thy going out and thy coming in from this time forth, and even for evermore.",
         'prayer'=>"{name}, the LORD is watching over you — your coming and your going. Rest in the knowledge that you are safe in His hands."],
        ['ref'=>"Philippians 4:13", 'text'=>"I can do all things through Christ which strengtheneth me.",
         'prayer'=>"{name}, whatever you face today, you are not alone — Christ gives you strength. You can do all things through Him."],
        ['ref'=>"Isaiah 41:10", 'text'=>"Fear thou not; for I am with thee: be not dismayed; for I am thy God: I will strengthen thee; yea, I will help thee; yea, I will uphold thee with the right hand of my righteousness.",
         'prayer'=>"{name}, do not be afraid — God is with you. He will strengthen you, help you, and hold you up with His own hand."],
        ['ref'=>"Proverbs 3:5-6", 'text'=>"Trust in the LORD with all thine heart; and lean not unto thine own understanding. In all thy ways acknowledge him, and he shall direct thy paths.",
         'prayer'=>"{name}, trust the LORD with all your heart today — He sees what you cannot, and He will direct your steps."],
        ['ref'=>"Psalm 23:1-3", 'text'=>"The LORD is my shepherd; I shall not want. He maketh me to lie down in green pastures: he leadeth me beside the still waters. He restoreth my soul.",
         'prayer'=>"{name}, the LORD is your Shepherd. He will lead you to rest, restore your soul, and provide everything you need."],
        ['ref'=>"Romans 8:28", 'text'=>"And we know that all things work together for good to them that love God, to them who are the called according to his purpose.",
         'prayer'=>"{name}, even what you cannot understand, God is weaving for your good. He is faithful to His purpose for you."],
        ['ref'=>"Joshua 1:9", 'text'=>"Have not I commanded thee? Be strong and of a good courage; be not afraid, neither be thou dismayed: for the LORD thy God is with thee whithersoever thou goest.",
         'prayer'=>"{name}, be strong and courageous — the LORD your God goes with you wherever you go. You are never alone."],
        ['ref'=>"Psalm 34:8", 'text'=>"O taste and see that the LORD is good: blessed is the man that trusteth in him.",
         'prayer'=>"{name}, taste and see that the LORD is good. May you experience His goodness and blessing in a real way today."],
        ['ref'=>"Ephesians 3:20", 'text'=>"Now unto him that is able to do exceeding abundantly above all that we ask or think, according to the power that worketh in us.",
         'prayer'=>"{name}, God is able to do far more than you can ask or imagine. Open your heart to His exceeding abundance."],
        ['ref'=>"Psalm 118:24", 'text'=>"This is the day which the LORD hath made; we will rejoice and be glad in it.",
         'prayer'=>"{name}, this is the day the LORD has made — rejoice and be glad in it. Let His joy be your strength today."],
        ['ref'=>"3 John 1:2", 'text'=>"Beloved, I wish above all things that thou mayest prosper and be in health, even as thy soul prospereth.",
         'prayer'=>"{name}, it is God's heart for you to prosper and be in health, even as your soul prospers. Receive that blessing."],
        ['ref'=>"Psalm 20:4", 'text'=>"Grant thee according to thine own heart, and fulfil all thy counsel.",
         'prayer'=>"{name}, may the LORD grant the desires of your heart and bring every plan and purpose to fulfilment."],
        ['ref'=>"Zephaniah 3:17", 'text'=>"The LORD thy God in the midst of thee is mighty; he will save, he will rejoice over thee with joy; he will rest in his love, he will joy over thee with singing.",
         'prayer'=>"{name}, the LORD rejoices over you with singing. He delights in you and rests in His love for you."],
        ['ref'=>"Psalm 16:11", 'text'=>"Thou wilt shew me the path of life: in thy presence is fulness of joy; at thy right hand there are pleasures for evermore.",
         'prayer'=>"{name}, in God's presence is fullness of joy. May you know His joy and walk in the path of life He shows you."],
        ['ref'=>"James 1:17", 'text'=>"Every good gift and every perfect gift is from above, and cometh down from the Father of lights, with whom is no variableness, neither shadow of turning.",
         'prayer'=>"{name}, every good thing in your life is a gift from your unchanging Father. Receive it with gratitude."],
        ['ref'=>"Psalm 91:11", 'text'=>"For he shall give his angels charge over thee, to keep thee in all thy ways.",
         'prayer'=>"{name}, God has commanded His angels to guard you in all your ways. You are protected and watched over."],
        ['ref'=>"Isaiah 26:3", 'text'=>"Thou wilt keep him in perfect peace, whose mind is stayed on thee: because he trusteth in thee.",
         'prayer'=>"{name}, as you keep your mind on the LORD, He keeps you in perfect peace. Let your trust in Him calm your heart."],
        ['ref'=>"Colossians 3:15", 'text'=>"And let the peace of God rule in your hearts, to the which also ye are called in one body; and be ye thankful.",
         'prayer'=>"{name}, let the peace of God rule in your heart, and let thankfulness fill your spirit today."],
        ['ref'=>"Psalm 37:4", 'text'=>"Delight thyself also in the LORD; and he shall give thee the desires of thine heart.",
         'prayer'=>"{name}, delight yourself in the LORD, and He will give you the desires of your heart. He sees and rewards your devotion."],
        ['ref'=>"Isaiah 43:2", 'text'=>"When thou passest through the waters, I will be with thee; and through the rivers, they shall not overflow thee: when thou walkest through the fire, thou shalt not be burned.",
         'prayer'=>"{name}, through every trial — the waters and the fire — the LORD is with you, and He will carry you through safely."],
        ['ref'=>"Psalm 46:1", 'text'=>"God is our refuge and strength, a very present help in trouble.",
         'prayer'=>"{name}, God is your refuge and strength — an ever-present help. Run to Him; He is your safe place."],
        ['ref'=>"Matthew 6:33", 'text'=>"But seek ye first the kingdom of God, and his righteousness; and all these things shall be added unto you.",
         'prayer'=>"{name}, as you seek God first, He will add everything you need. Trust His order and His provision."],
        ['ref'=>"Psalm 100:4-5", 'text'=>"Enter into his gates with thanksgiving, and into his courts with praise: be thankful unto him, and bless his name. For the LORD is good; his mercy is everlasting.",
         'prayer'=>"{name}, enter His presence with thanksgiving — the LORD is good and His mercy toward you is everlasting."],
        ['ref'=>"Galatians 5:22-23", 'text'=>"But the fruit of the Spirit is love, joy, peace, longsuffering, gentleness, goodness, faith, meekness, temperance.",
         'prayer'=>"{name}, may the fruit of the Spirit — love, joy, and peace — grow richly in your life today and always."],
        ['ref'=>"Psalm 34:4", 'text'=>"I sought the LORD, and he heard me, and delivered me from all my fears.",
         'prayer'=>"{name}, when you seek the LORD, He hears you and delivers you from every fear. Cast your cares on Him."],
        ['ref'=>"1 Corinthians 13:13", 'text'=>"And now abideth faith, hope, charity, these three; but the greatest of these is charity.",
         'prayer'=>"{name}, may faith, hope, and love abound in your life — and above all, may you know and share God's great love."],
        ['ref'=>"Psalm 128:1-2", 'text'=>"Blessed is every one that feareth the LORD, that walketh in his ways. For thou shalt eat the labour of thine hands: happy shalt thou be, and it shall be well with thee.",
         'prayer'=>"{name}, blessed are you who walk in the LORD's ways. May the work of your hands prosper and all be well with you."],
        ['ref'=>"Romans 15:13", 'text'=>"Now the God of hope fill you with all joy and peace in believing, that ye may abound in hope, through the power of the Holy Ghost.",
         'prayer'=>"{name}, may the God of hope fill you with all joy and peace as you trust in Him — and make you abound in hope today."],

        /* ---- EXOUSIA: The theme of Authority (conference focus) ---- */
        ['ref'=>"Luke 10:19", 'text'=>"Behold, I give unto you power to tread on serpents and scorpions, and over all the power of the enemy: and nothing shall by any means hurt you.",
         'prayer'=>"{name}, the LORD has given you authority over the power of the enemy. Walk in that authority today — nothing shall by any means hurt you."],
        ['ref'=>"Matthew 28:18", 'text'=>"And Jesus came and spake unto them, saying, All power is given unto me in heaven and in earth.",
         'prayer'=>"{name}, all authority in heaven and on earth belongs to Jesus, and you are seated in His victory. Receive His power over your day."],
        ['ref'=>"Ephesians 1:19-20", 'text'=>"And what is the exceeding greatness of his power to us-ward who believe, according to the working of his mighty power, which he wrought in Christ, when he raised him from the dead.",
         'prayer'=>"{name}, the same exceeding power that raised Christ from the dead is at work in you. Nothing is too hard for your God today."],
        ['ref'=>"Psalm 62:11", 'text'=>"God hath spoken once; twice have I heard this; that power belongeth unto God.",
         'prayer'=>"{name}, power belongs to God, and He shares His strength with you. You are empowered, not by yourself, but by the Almighty."],
        ['ref'=>"Daniel 4:3", 'text'=>"How great are his signs! and how mighty are his wonders! his kingdom is an everlasting kingdom, and his dominion is from generation to generation.",
         'prayer'=>"{name}, you serve an everlasting King whose dominion never ends. Rest in the unshakable power of His kingdom today."],
        ['ref'=>"Revelation 12:11", 'text'=>"And they overcame him by the blood of the Lamb, and by the word of their testimony.",
         'prayer'=>"{name}, you are an overcomer — by the blood of the Lamb and your testimony. Stand firm, for victory is already yours."],
        ['ref'=>"Colossians 2:15", 'text'=>"And having spoiled principalities and powers, he made a shew of them openly, triumphing over them in it.",
         'prayer'=>"{name}, Jesus has disarmed every power that stood against you and triumphed openly. You walk in that finished victory."],
        ['ref'=>"Romans 13:1", 'text'=>"...for there is no power but of God: the powers that be are ordained of God.",
         'prayer'=>"{name}, every authority is under God's sovereign hand. Do not fear — the One above all powers is for you."],
        ['ref'=>"Psalm 66:7", 'text'=>"He ruleth by his power for ever; his eyes behold the nations: let not the rebellious exalt themselves.",
         'prayer'=>"{name}, the LORD rules by His power forever and His eyes are on you. You are held by the sovereign God of all the earth."],
        ['ref'=>"2 Corinthians 10:4", 'text'=>"(For the weapons of our warfare are not carnal, but mighty through God to the pulling down of strong holds;)",
         'prayer'=>"{name}, your weapons are mighty through God. Every stronghold that stands against you is coming down today."],

        /* ---- General blessings ---- */
        ['ref'=>"Psalm 27:1", 'text'=>"The LORD is my light and my salvation; whom shall I fear? the LORD is the strength of my life; of whom shall I be afraid?",
         'prayer'=>"{name}, the LORD is your light and your salvation. Let Him be the strength of your life — you have nothing to fear."],
        ['ref'=>"Isaiah 40:31", 'text'=>"But they that wait upon the LORD shall renew their strength; they shall mount up with wings as eagles; they shall run, and not be weary; and they shall walk, and not faint.",
         'prayer'=>"{name}, as you wait on the LORD, He renews your strength. Rise up with eagle's wings — you will not grow weary today."],
        ['ref'=>"Psalm 56:3", 'text'=>"What time I am afraid, I will trust in thee.",
         'prayer'=>"{name}, whenever fear whispers, let your answer be trust. The LORD is faithful, and you can rest in Him."],
        ['ref'=>"Proverbs 18:10", 'text'=>"The name of the LORD is a strong tower: the righteous runneth into it, and is safe.",
         'prayer'=>"{name}, the name of the LORD is your strong tower. Run into it — you are safe and secure in Him."],
        ['ref'=>"Psalm 32:8", 'text'=>"I will instruct thee and teach thee in the way which thou shalt go: I will guide thee with mine eye.",
         'prayer'=>"{name}, the LORD promises to instruct and guide you. He is watching over your steps and leading you the right way."],
        ['ref'=>"Jeremiah 17:7-8", 'text'=>"Blessed is the man that trusteth in the LORD, and whose hope the LORD is. For he shall be as a tree planted by the waters.",
         'prayer'=>"{name}, blessed are you who trust in the LORD. Be like a tree planted by the waters — green, fruitful, and never lacking."],
        ['ref'=>"Psalm 73:26", 'text'=>"My flesh and my heart faileth: but God is the strength of my heart, and my portion for ever.",
         'prayer'=>"{name}, even when your strength fails, God is the strength of your heart and your portion forever. He is enough."],
        ['ref'=>"Isaiah 54:17", 'text'=>"No weapon that is formed against thee shall prosper; and every tongue that shall rise against thee in judgment thou shalt condemn.",
         'prayer'=>"{name}, no weapon formed against you shall prosper. You are shielded by the LORD — stand secure in His defence."],
        ['ref'=>"Psalm 138:8", 'text'=>"The LORD will perfect that which concerneth me: thy mercy, O LORD, endureth for ever: forsake not the works of thine own hands.",
         'prayer'=>"{name}, the LORD will perfect everything that concerns you. His mercy endures forever — He will not abandon His work in you."],
        ['ref'=>"Nahum 1:7", 'text'=>"The LORD is good, a strong hold in the day of trouble; and he knoweth them that trust in him.",
         'prayer'=>"{name}, the LORD is your stronghold in trouble, and He knows you. You are never unknown or forgotten by Him."],
        ['ref'=>"Psalm 5:12", 'text'=>"For thou, LORD, wilt bless the righteous; with favour wilt thou compass him as with a shield.",
         'prayer'=>"{name}, the LORD surrounds you with favour as with a shield. His blessing goes before you today."],
        ['ref'=>"Proverbs 16:3", 'text'=>"Commit thy works unto the LORD, and thy thoughts shall be established.",
         'prayer'=>"{name}, commit your plans to the LORD today, and He will establish your thoughts and guide your steps."],
        ['ref'=>"Psalm 29:11", 'text'=>"The LORD will give strength unto his people; the LORD will bless his people with peace.",
         'prayer'=>"{name}, the LORD gives you strength and blesses you with peace. Receive both from His hand today."],
        ['ref'=>"Isaiah 55:12", 'text'=>"For ye shall go out with joy, and be led forth with peace: the mountains and the hills shall break forth before you into singing.",
         'prayer'=>"{name}, you shall go out with joy and be led forth with peace. Creation itself rejoices over your path."],
        ['ref'=>"Psalm 84:11", 'text'=>"For the LORD God is a sun and shield: the LORD will give grace and glory: no good thing will he withhold from them that walk uprightly.",
         'prayer'=>"{name}, the LORD is your sun and shield. He withholds no good thing from those who walk with Him — trust His generosity."],
        ['ref'=>"Joshua 1:5", 'text'=>"There shall not any man be able to stand before thee all the days of thy life: as I was with Moses, so I will be with thee: I will not fail thee, nor forsake thee.",
         'prayer'=>"{name}, the LORD will not fail you nor forsake you. He is with you as He was with His faithful servants — you are never alone."],
        ['ref'=>"Psalm 121:1-2", 'text'=>"I will lift up mine eyes unto the hills, from whence cometh my help. My help cometh from the LORD, which made heaven and earth.",
         'prayer'=>"{name}, your help comes from the LORD who made heaven and earth. Lift your eyes to Him — He is more than able."],
        ['ref'=>"2 Thessalonians 3:3", 'text'=>"But the Lord is faithful, who shall stablish you, and keep you from evil.",
         'prayer'=>"{name}, the Lord is faithful. He will establish you and keep you from evil — you are secure in His care."],
        ['ref'=>"Psalm 30:5", 'text'=>"...weeping may endure for a night, but joy cometh in the morning.",
         'prayer'=>"{name}, though sorrow may last for a season, joy comes in the morning. The LORD is turning your night into gladness."],
        ['ref'=>"Habakkuk 3:19", 'text'=>"The LORD God is my strength, and he will make my feet like hinds' feet, and he will make me to walk upon mine high places.",
         'prayer'=>"{name}, the LORD is your strength. He will set your feet upon high places and cause you to walk in victory."],
        ['ref'=>"Psalm 138:3", 'text'=>"In the day when I cried thou answeredst me, and strengthenedst me with strength in my soul.",
         'prayer'=>"{name}, when you call on the LORD, He answers and strengthens you in your soul. He is near to you today."],
        ['ref'=>"Isaiah 49:16", 'text'=>"Behold, I have graven thee upon the palms of my hands; thy walls are continually before me.",
         'prayer'=>"{name}, you are engraved on the palms of God's hands. You are always before Him — never forgotten, always remembered."],
        ['ref'=>"Psalm 16:6", 'text'=>"The lines are fallen unto me in pleasant places; yea, I have a goodly heritage.",
         'prayer'=>"{name}, your portion from the LORD is pleasant and your heritage is goodly. Rejoice in the good things He has given you."],
        ['ref'=>"1 Peter 5:7", 'text'=>"Casting all your care upon him; for he careth for you.",
         'prayer'=>"{name}, cast every care upon the Lord, for He genuinely cares for you. You do not have to carry it alone."],
        ['ref'=>"Psalm 34:7", 'text'=>"The angel of the LORD encampeth round about them that fear him, and delivereth them.",
         'prayer'=>"{name}, the angel of the LORD encamps around you. You are guarded and delivered by heaven itself."],
        ['ref'=>"Proverbs 4:18", 'text'=>"But the path of the just is as the shining light, that shineth more and more unto the perfect day.",
         'prayer'=>"{name}, your path shines brighter and brighter. Keep walking in righteousness, and your light will only increase."],
        ['ref'=>"Psalm 144:15", 'text'=>"Happy is that people, that is in such a case: yea, happy is that people, whose God is the LORD.",
         'prayer'=>"{name}, happy is the one whose God is the LORD. True joy is yours because you belong to Him."],
        ['ref'=>"Isaiah 41:13", 'text'=>"For I the LORD thy God will hold thy right hand, saying unto thee, Fear not; I will help thee.",
         'prayer'=>"{name}, the LORD holds your right hand. Fear not — He has promised to help you through every step."],
        ['ref'=>"2 Corinthians 9:8", 'text'=>"And God is able to make all grace abound toward you; that ye, always having all sufficiency in all things, may abound to every good work.",
         'prayer'=>"{name}, God is able to make all grace abound toward you. You have sufficiency in all things to do every good work He calls you to."],
        ['ref'=>"Psalm 126:3", 'text'=>"The LORD hath done great things for us; whereof we are glad.",
         'prayer'=>"{name}, the LORD has done great things for you — and He is not finished. Rejoice and be glad in His goodness."],
        ['ref'=>"Lamentations 3:22-23", 'text'=>"It is of the LORD's mercies that we are not consumed, because his compassions fail not. They are new every morning: great is thy faithfulness.",
         'prayer'=>"{name}, His mercies are new every morning and His faithfulness is great. Today is fresh with His compassion for you."],

        /* ---- More curated blessings (added for Day 2 / 100 total) ---- */
        ['ref'=>"Psalm 23:4", 'text'=>"Yea, though I walk through the valley of the shadow of death, I will fear no evil: for thou art with me; thy rod and thy staff they comfort me.",
         'prayer'=>"{name}, even in your darkest valley, you need not fear — the LORD is with you, and His comfort surrounds you."],
        ['ref'=>"Isaiah 40:29", 'text'=>"He giveth power to the faint; and to them that have no might he increaseth strength.",
         'prayer'=>"{name}, when you are faint, the LORD gives you power. When you have no might, He increases your strength."],
        ['ref'=>"Psalm 139:14", 'text'=>"I will praise thee; for I am fearfully and wonderfully made: marvellous are thy works; and that my soul knoweth right well.",
         'prayer'=>"{name}, you are fearfully and wonderfully made. You are God's marvellous work — never doubt your worth."],
        ['ref'=>"Philippians 4:19", 'text'=>"But my God shall supply all your need according to his riches in glory by Christ Jesus.",
         'prayer'=>"{name}, your God shall supply all your needs according to His riches in glory. Nothing you need is beyond Him."],
        ['ref'=>"Psalm 46:5", 'text'=>"God is in the midst of her; she shall not be moved: God shall help her, and that right early.",
         'prayer'=>"{name}, God is in your midst — you shall not be moved. He will help you, and right on time."],
        ['ref'=>"Jeremiah 33:3", 'text'=>"Call unto me, and I will answer thee, and shew thee great and mighty things, which thou knowest not.",
         'prayer'=>"{name}, call upon the LORD and He will answer, showing you great and mighty things you have not yet known."],
        ['ref'=>"Psalm 37:5", 'text'=>"Commit thy way unto the LORD; trust also in him; and he shall bring it to pass.",
         'prayer'=>"{name}, commit your way to the LORD and trust in Him — He will bring it to pass. Your path is secure in His hands."],
        ['ref'=>"Isaiah 50:7", 'text'=>"For the Lord GOD will help me; therefore shall I not be confounded: therefore have I set my face like a flint.",
         'prayer'=>"{name}, the Lord GOD will help you, so you will not be confounded. Set your face with resolve — you are upheld."],
        ['ref'=>"Psalm 34:10", 'text'=>"The young lions do lack, and suffer hunger: but they that seek the LORD shall not want any good thing.",
         'prayer'=>"{name}, those who seek the LORD shall not want any good thing. Seek Him and trust His provision."],
        ['ref'=>"Romans 5:5", 'text'=>"And hope maketh not ashamed; because the love of God is shed abroad in our hearts by the Holy Ghost.",
         'prayer'=>"{name}, God's love is poured into your heart by the Holy Spirit. Let that hope and love strengthen you today."],
        ['ref'=>"Psalm 145:18-19", 'text'=>"The LORD is nigh unto all them that call upon him, to all that call upon him in truth. He will fulfil the desire of them that fear him.",
         'prayer'=>"{name}, the LORD is near to you as you call on Him in truth. He hears you and fulfils the desires of your heart."],
        ['ref'=>"1 John 4:4", 'text'=>"Ye are of God, little children, and have overcome them: because greater is he that is in you, than he that is in the world.",
         'prayer'=>"{name}, you are of God and have already overcome — because greater is He who is in you than he who is in the world."],
        ['ref'=>"Psalm 121:5", 'text'=>"The LORD is thy keeper: the LORD is thy shade upon thy right hand.",
         'prayer'=>"{name}, the LORD is your keeper and your shade. He protects and refreshes you through every season."],
        ['ref'=>"Isaiah 35:3", 'text'=>"Strengthen ye the weak hands, and confirm the feeble knees.",
         'prayer'=>"{name}, the LORD strengthens your weak hands and confirms your feeble knees. Rise up in His enabling power."],
        ['ref'=>"Psalm 16:8", 'text'=>"I have set the LORD always before me: because he is at my right hand, I shall not be moved.",
         'prayer'=>"{name}, set the LORD before you always. Because He is at your right hand, you shall not be moved."],
        ['ref'=>"Colossians 1:11", 'text'=>"Strengthened with all might, according to his glorious power, unto all patience and longsuffering with joyfulness.",
         'prayer'=>"{name}, be strengthened with all might according to His glorious power — with patience, endurance, and joy."],
        ['ref'=>"Psalm 84:12", 'text'=>"O LORD of hosts, blessed is the man that trusteth in thee.",
         'prayer'=>"{name}, blessed is the one who trusts in the LORD of hosts. Your trust in Him brings blessing beyond measure."],
        ['ref'=>"Isaiah 52:12", 'text'=>"...for the LORD will go before you; and the God of Israel will be your rereward.",
         'prayer'=>"{name}, the LORD goes before you and He is also your rear guard. You are surrounded and led by Him."],
        ['ref'=>"Psalm 27:14", 'text'=>"Wait on the LORD: be of good courage, and he shall strengthen thine heart: wait, I say, on the LORD.",
         'prayer'=>"{name}, wait on the LORD and be of good courage — He shall strengthen your heart as you trust in Him."],
        ['ref'=>"Micah 6:8", 'text'=>"He hath shewed thee, O man, what is good; and what doth the LORD require of thee, but to do justly, and to love mercy, and to walk humbly with thy God?",
         'prayer'=>"{name}, the LORD has shown you what is good — to do justice, love mercy, and walk humbly with your God. He is well pleased with that walk."],
        ['ref'=>"Psalm 103:2-3", 'text'=>"Bless the LORD, O my soul, and forget not all his benefits: who forgiveth all thine iniquities; who healeth all thy diseases.",
         'prayer'=>"{name}, bless the LORD and forget not His benefits — He forgives all your iniquities and heals all your diseases."],
        ['ref'=>"1 Peter 5:10", 'text'=>"But the God of all grace, who hath called us unto his eternal glory by Christ Jesus, after that ye have suffered a while, make you perfect, stablish, strengthen, settle you.",
         'prayer'=>"{name}, the God of all grace will perfect, establish, strengthen, and settle you after every trial. He is completing His work in you."],
        ['ref'=>"Psalm 37:3", 'text'=>"Trust in the LORD, and do good; so shalt thou dwell in the land, and verily thou shalt be fed.",
         'prayer'=>"{name}, trust in the LORD and do good — you shall dwell safely and be provided for. Faithfulness brings His blessing."],
        ['ref'=>"Isaiah 26:4", 'text'=>"Trust ye in the LORD for ever: for in the LORD JEHOVAH is everlasting strength.",
         'prayer'=>"{name}, trust in the LORD forever, for in Him is everlasting strength. You are anchored in unshakable power."],
        ['ref'=>"Psalm 62:8", 'text'=>"Trust in him at all times; ye people, pour out your heart before him: God is a refuge for us.",
         'prayer'=>"{name}, trust in Him at all times and pour out your heart before Him — God is your refuge. He receives you fully."],
        ['ref'=>"Hebrews 13:8", 'text'=>"Jesus Christ the same yesterday, and to day, and for ever.",
         'prayer'=>"{name}, Jesus is the same yesterday, today, and forever. His love and faithfulness to you never change."],
        ['ref'=>"Psalm 34:15", 'text'=>"The eyes of the LORD are upon the righteous, and his ears are open unto their cry.",
         'prayer'=>"{name}, the eyes of the LORD are upon you and His ears are open to your cry. He sees you and He hears you."],
        ['ref'=>"2 Timothy 1:7", 'text'=>"For God hath not given us the spirit of fear; but of power, and of love, and of a sound mind.",
         'prayer'=>"{name}, God has not given you a spirit of fear — but of power, of love, and of a sound mind. Walk in His strength today."],

        /* ---- Rest & comfort for the heavy-laden ---- */
        ['ref'=>"Matthew 11:28-30", 'text'=>"Come unto me, all ye that labour and are heavy laden, and I will give you rest. Take my yoke upon you, and learn of me... and ye shall find rest unto your souls.",
         'prayer'=>"{name}, come to Jesus with every burden and heaviness — He will give you rest. Take His yoke, and your soul shall find rest."],
        ['ref'=>"Psalm 55:22", 'text'=>"Cast thy burden upon the LORD, and he shall sustain thee: he shall never suffer the righteous to be moved.",
         'prayer'=>"{name}, cast your burden upon the LORD, and He will sustain you. You will not be moved — He holds you steady."],
        ['ref'=>"Psalm 34:18", 'text'=>"The LORD is nigh unto them that are of a broken heart; and saveth such as be of a contrite spirit.",
         'prayer'=>"{name}, the LORD draws near to the brokenhearted and saves the crushed in spirit. He is close to you right now."],
        ['ref'=>"Isaiah 61:3", 'text'=>"...to appoint unto them that mourn in Zion, to give unto them beauty for ashes, the oil of joy for mourning, the spirit of heaviness for the garment of praise.",
         'prayer'=>"{name}, the LORD gives you beauty for ashes, the oil of joy for mourning, and a garment of praise for heaviness. He is exchanging your sorrow for joy."],
        ['ref'=>"Psalm 147:3", 'text'=>"He healeth the broken in heart, and bindeth up their wounds.",
         'prayer'=>"{name}, the LORD heals the brokenhearted and binds up your wounds. He is gently restoring what is hurting."],
        ['ref'=>"John 14:27", 'text'=>"Peace I leave with you, my peace I give unto you: not as the world giveth, give I unto you. Let not your heart be troubled, neither let it be afraid.",
         'prayer'=>"{name}, Jesus gives you His own peace — not as the world gives. Let not your heart be troubled nor afraid; His peace is with you."],
        ['ref'=>"Matthew 6:34", 'text'=>"Take therefore no thought for the morrow: for the morrow shall take thought for the things of itself. Sufficient unto the day is the evil thereof.",
         'prayer'=>"{name}, do not carry tomorrow's worry today. The LORD gives you grace for this moment — rest in His sufficiency for now."],

        /* ---- The faithfulness of God & hope that rekindles ---- */
        ['ref'=>"Hebrews 10:23", 'text'=>"Let us hold fast the profession of our faith without wavering; (for he is faithful that promised;)",
         'prayer'=>"{name}, hold fast to your faith without wavering — for He who promised is faithful. God will keep every word He has spoken over you."],
        ['ref'=>"Deuteronomy 7:9", 'text'=>"Know therefore that the LORD thy God, he is God, the faithful God, which keepeth covenant and mercy with them that love him and keep his commandments.",
         'prayer'=>"{name}, the LORD your God is the faithful God who keeps covenant and mercy. You can rest in a God who never breaks His promises."],
        ['ref'=>"Psalm 36:5", 'text'=>"Thy mercy, O LORD, is in the heavens; and thy faithfulness reacheth unto the clouds.",
         'prayer'=>"{name}, God's faithfulness reaches to the clouds — far beyond anything you face. His mercy toward you is measureless."],
        ['ref'=>"Isaiah 25:1", 'text'=>"O LORD, thou art my God; I will exalt thee, I will praise thy name; for thou hast done wonderful things; thy counsels of old are faithfulness and truth.",
         'prayer'=>"{name}, the LORD has done wonderful things, and His counsels are faithfulness and truth. Praise Him — He is working faithfully for you."],
        ['ref'=>"1 Corinthians 1:9", 'text'=>"God is faithful, by whom ye were called unto the fellowship of his Son Jesus Christ our Lord.",
         'prayer'=>"{name}, God is faithful — the same God who called you into fellowship with His Son will never abandon you."],
        ['ref'=>"Psalm 89:1", 'text'=>"I will sing of the mercies of the LORD for ever: with my mouth will I make known thy faithfulness to all generations.",
         'prayer'=>"{name}, sing of the LORD's mercies and make known His faithfulness. His steadfast love toward you endures forever."],
        ['ref'=>"Psalm 42:11", 'text'=>"Why art thou cast down, O my soul? and why art thou disquieted within me? hope thou in God: for I shall yet praise him, who is the health of my countenance, and my God.",
         'prayer'=>"{name}, even when your soul is cast down, hope in God — you shall yet praise Him. He is the health of your countenance and your God."],
        ['ref'=>"Romans 8:24-25", 'text'=>"...but hope that is seen is not hope: for what a man seeth, why doth he yet hope for? But if we hope for that we see not, then do we with patience wait for it.",
         'prayer'=>"{name}, hope in what is not yet seen, and wait with patience — God is bringing it to pass. Your unseen future is secure in Him."],
        ['ref'=>"Isaiah 49:23", 'text'=>"...and thou shalt know that I am the LORD: for they shall not be ashamed that wait for me.",
         'prayer'=>"{name}, you shall not be ashamed who wait on the LORD. He will not disappoint those who put their hope in Him."],
        ['ref'=>"Psalm 71:14", 'text'=>"But I will hope continually, and will yet praise thee more and more.",
         'prayer'=>"{name}, hope continually in the LORD, and praise Him more and more. He is worthy of your unwavering trust."],
        ['ref'=>"Hebrews 11:1", 'text'=>"Now faith is the substance of things hoped for, the evidence of things not seen.",
         'prayer'=>"{name}, let your faith be the substance of what you hope for. God is already working on what you cannot yet see."],
        ['ref'=>"Micah 7:7", 'text'=>"Therefore I will look unto the LORD; I will wait for the God of my salvation: my God will hear me.",
         'prayer'=>"{name}, look unto the LORD and wait for the God of your salvation — He will hear you. You are not waiting in vain."],
        ['ref'=>"Proverbs 23:18", 'text'=>"For surely there is an end; and thine expectation shall not be cut off.",
         'prayer'=>"{name}, your expectation shall not be cut off — there is a future and a hope for you. God will fulfil what you are waiting for."],
        ['ref'=>"Psalm 130:5", 'text'=>"I wait for the LORD, my soul doth wait, and in his word do I hope.",
         'prayer'=>"{name}, let your soul wait on the LORD and hope in His word. His promises are your sure foundation."],

        /* ---- Personal & intimate: God moves in your specific situation ---- */
        ['ref'=>"2 Kings 6:6", 'text'=>"And the man of God said, Where fell it? And he shewed him the place. And he cut down a stick, and cast it in thither; and the iron did swim.",
         'prayer'=>"{name}, may what was heavily lost in your life defy every natural odds and rise again by God's special mercy."],
        ['ref'=>"Joel 2:25", 'text'=>"And I will restore to you the years that the locust hath eaten, the cankerworm, and the caterpillar, and the palmerworm.",
         'prayer'=>"{name}, may God restore every year and everything the enemy stole from you — the years the locust ate, given back in abundance."],
        ['ref'=>"Psalm 126:1-2", 'text'=>"When the LORD turned again the captivity of Zion, we were like them that dream. Then was our mouth filled with laughter, and our tongue with singing.",
         'prayer'=>"{name}, may God so turn things around for you that it feels like a dream — your mouth filled with laughter and your heart with song again."],
        ['ref'=>"Genesis 21:6", 'text'=>"And Sarah said, God hath made me to laugh, so that all that hear will laugh with me.",
         'prayer'=>"{name}, may God make you laugh again — a joy so full and real that all who hear it rejoice with you."],
        ['ref'=>"Genesis 16:13", 'text'=>"And she called the name of the LORD that spake unto her, Thou God seest me.",
         'prayer'=>"{name}, you are not forgotten — the God who sees everything sees you and your situation right now, and He cares."],
        ['ref'=>"John 14:2-3", 'text'=>"In my Father's house are many mansions: if it were not so, I would have told you. I go to prepare a place for you.",
         'prayer'=>"{name}, there is a place prepared for you in the Father's house — you are expected, wanted, and awaited."],
        ['ref'=>"Psalm 56:8", 'text'=>"Thou tellest my wanderings: put thou my tears into thy bottle: are they not in thy book?",
         'prayer'=>"{name}, God has collected every tear you have shed into His bottle — none of your pain has gone unnoticed by Him."],
        ['ref'=>"John 2:10", 'text'=>"...thou hast kept the good wine until now.",
         'prayer'=>"{name}, may God save the best for you — turning what seems ordinary or empty into something truly wonderful."],
        ['ref'=>"Mark 6:42", 'text'=>"And they did all eat, and were filled.",
         'prayer'=>"{name}, may God take your little and multiply it until you and all yours are completely filled."],
        ['ref'=>"2 Kings 4:6", 'text'=>"...and the oil stayed. Then she came and told the man of God. And he said, Go, sell the oil, and pay thy debt, and live thou and thy children of the rest.",
         'prayer'=>"{name}, may God cause your provision to flow until every empty vessel in your life is filled — enough to settle what you owe and live."],
        ['ref'=>"Isaiah 43:1", 'text'=>"Fear not: for I have redeemed thee, I have called thee by thy name; thou art mine.",
         'prayer'=>"{name}, the LORD calls you by name — you are His. That is your deepest identity and your surest security."],
        ['ref'=>"Luke 15:24", 'text'=>"For this my son was dead, and is alive again; he was lost, and is found. And they began to be merry.",
         'prayer'=>"{name}, no matter how far things seemed to go, God celebrates over you — you are found, you are alive in Him, and heaven rejoices."],

        /* ---- The gathering of the brethren (warm & invitational) ---- */
        ['ref'=>"Hebrews 10:24-25", 'text'=>"And let us consider one another to provoke unto love and to good works: Not forsaking the assembling of ourselves together, as the manner of some is; but exhorting one another: and so much the more, as ye see the day approaching.",
         'prayer'=>"{name}, the LORD has placed you among His people for love, encouragement, and good works — may you find in the gathering of the brethren a place where you are truly seen, held, and strengthened."],
        ['ref'=>"Psalm 133:1", 'text'=>"Behold, how good and how pleasant it is for brethren to dwell together in unity!",
         'prayer'=>"{name}, how good and pleasant it is to dwell with the family of God — may you know afresh the warmth and belonging He has for you among His people."],
        ['ref'=>"Matthew 18:20", 'text'=>"For where two or three are gathered together in my name, there am I in the midst of them.",
         'prayer'=>"{name}, wherever two or three gather in His name, Jesus is there in their midst — may you meet Him personally in the fellowship of His people, and feel that you are never alone."],

        /* ---- Faith ---- */
        ['ref'=>"Mark 11:24", 'text'=>"Therefore I say unto you, What things soever ye desire, when ye pray, believe that ye receive them, and ye shall have them.",
         'prayer'=>"{name}, when you pray, believe that you receive — the LORD honours the faith that dares to trust Him for what it cannot yet see."],
        ['ref'=>"Matthew 17:20", 'text'=>"...If ye have faith as a grain of mustard seed, ye shall say unto this mountain, Remove hence to yonder place; and it shall remove; and nothing shall be impossible unto you.",
         'prayer'=>"{name}, even faith as small as a mustard seed moves mountains — nothing is impossible for you when you trust the God who makes it so."],
        ['ref'=>"Hebrews 11:6", 'text'=>"But without faith it is impossible to please him: for he that cometh to God must believe that he is, and that he is a rewarder of them that diligently seek him.",
         'prayer'=>"{name}, draw near to God in faith, believing that He is and that He rewards those who diligently seek Him — your seeking is not in vain."],
        ['ref'=>"Mark 9:23", 'text'=>"Jesus said unto him, If thou canst believe, all things are possible to him that believeth.",
         'prayer'=>"{name}, all things are possible to the one who believes — choose to believe today, and let God be bigger than the thing before you."],
        ['ref'=>"2 Corinthians 5:7", 'text'=>"(For we walk by faith, not by sight:)",
         'prayer'=>"{name}, walk by faith and not by sight — trust what God has said over what you currently see."],
        ['ref'=>"Romans 10:17", 'text'=>"So then faith cometh by hearing, and hearing by the word of God.",
         'prayer'=>"{name}, let your faith be fed by the word of God — as you hear it, faith rises, and your heart grows strong."],

        /* ---- Mercy ---- */
        ['ref'=>"Lamentations 3:22", 'text'=>"It is of the LORD's mercies that we are not consumed, because his compassions fail not.",
         'prayer'=>"{name}, it is only the LORD's mercies that we are not consumed — His compassion toward you never fails, even in your hardest days."],
        ['ref'=>"Psalm 103:8", 'text'=>"The LORD is merciful and gracious, slow to anger, and plenteous in mercy.",
         'prayer'=>"{name}, the LORD is merciful and gracious, slow to anger and rich in mercy — He deals gently with you, not according to your failures."],
        ['ref'=>"Micah 7:18", 'text'=>"Who is a God like unto thee, that pardoneth iniquity, and passeth by the transgression of the remnant of his heritage? he retaineth not his anger for ever, because he delighteth in mercy.",
         'prayer'=>"{name}, there is no God like the LORD, who delights in mercy — He pardons and passes over, choosing grace over judgment toward you."],
        ['ref'=>"Ephesians 2:4-5", 'text'=>"But God, who is rich in mercy, for his great love wherewith he loved us, even when we were dead in sins, hath quickened us together with Christ.",
         'prayer'=>"{name}, God is rich in mercy and great in love toward you — even when you were at your lowest, He made you alive with Christ."],

        /* ---- Healing for the sick ---- */
        ['ref'=>"Jeremiah 30:17", 'text'=>"For I will restore health unto thee, and I will heal thee of thy wounds, saith the LORD.",
         'prayer'=>"{name}, the LORD says He will restore health to you and heal your wounds — receive His healing touch over your body and spirit."],
        ['ref'=>"Psalm 30:2", 'text'=>"O LORD my God, I cried unto thee, and thou hast healed me.",
         'prayer'=>"{name}, as you cry out to the LORD, He hears and He heals — let your cry rise to Him today."],
        ['ref'=>"Exodus 15:26", 'text'=>"...for I am the LORD that healeth thee.",
         'prayer'=>"{name}, the LORD is your Healer — He is the One who heals your body and restores your strength. Trust His hand upon you."],
        ['ref'=>"1 Peter 2:24", 'text'=>"...by whose stripes ye were healed.",
         'prayer'=>"{name}, by the stripes of Jesus you were healed — let that finished work of healing settle over your whole being today."],
    ];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$event = null;

try {
    switch ($action) {

        /* ================================================================
         * GET EVENT + SCRIPTURE — page loads with the event token
         * ================================================================ */
        case 'get_event':
            $token = trim($_POST['token'] ?? $_GET['token'] ?? '');
            $event = ci_find_event_by_token($pdo, $token);
            if (!$event) { echo json_encode(['status'=>'error','message'=>'Event not found. Please scan a valid QR code.']); exit; }
            // attach day info so the page can show Day 1 / Day 2
            $dayInfo = ci_current_day($event);
            $event['day_info'] = $dayInfo;
            echo json_encode(['status'=>'success','event'=>$event]);
            break;

        /* ================================================================
         * LOOKUP — is this phone registered? (returns person + match)
         * ================================================================ */
        case 'lookup':
            $token = trim($_POST['token'] ?? '');
            $event = ci_find_event_by_token($pdo, $token);
            if (!$event) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }
            $phone = ci_normalize_phone($_POST['phone'] ?? '');
            if ($phone === null) { echo json_encode(['status'=>'error','message'=>'That phone number looks invalid. Please re-check it.']); exit; }

            $result = ['status'=>'success', 'event_id'=>$event['id'], 'found'=>false];

            // Already checked in TODAY? (per-day guard for multi-day events)
            $already = false;
            $dayInfo = ci_current_day($event);
            if ($dayInfo['in_window']) {
                $chk = $pdo->prepare("SELECT * FROM checkins WHERE event_id = ? AND phone = ? AND checkin_date = ?");
                $chk->execute([$event['id'], $phone, $dayInfo['checkin_date']]);
                if ($chk->fetch()) $already = true;
            }
            $result['already_checked_in'] = $already;

            // 1) Member (users table)
            $st = $pdo->prepare("SELECT id, first_name, last_name, phone FROM users WHERE phone = ? LIMIT 1");
            $st->execute([$phone]);
            $user = $st->fetch(PDO::FETCH_ASSOC);

            // 2) Guest registration (event_registrations by guest_phone)
            // Match on the exact stored value OR any format of the same number
            // (090..., 23490..., +23490...) by stripping to the core 10 digits.
            $guest = null;
            $coreDigits = substr(preg_replace('/[^0-9]/', '', $phone), -10); // e.g. 9020868023
            $gst = $pdo->prepare("SELECT r.id, r.guest_name, r.guest_phone, r.user_id, r.matched_user_id
                                  FROM event_registrations r
                                  WHERE r.event_id = ?
                                    AND REPLACE(REPLACE(REPLACE(r.guest_phone,' ',''),'-',''),'+','')
                                         LIKE ?
                                  LIMIT 1");
            $gst->execute([$event['id'], '%'.$coreDigits]);
            $guest = $gst->fetch(PDO::FETCH_ASSOC);

            if ($user || $guest) {
                $result['found'] = true;
                if ($user) {
                    $result['person'] = [
                        'source'=>'user', 'id'=>$user['id'],
                        'name'=>trim($user['first_name'].' '.$user['last_name']),
                        'first_name'=>$user['first_name'], 'last_name'=>$user['last_name'],
                        'phone'=>$user['phone'],
                    ];
                } else {
                    $firstName = $guest['guest_name'] ? explode(' ', $guest['guest_name'])[0] : '';
                    $result['person'] = [
                        'source'=>'registration', 'id'=>$guest['id'],
                        'name'=>$guest['guest_name'] ?: 'Guest',
                        'first_name'=>$firstName, 'last_name'=>'',
                        'phone'=>$guest['guest_phone'],
                    ];
                }
            }
            echo json_encode($result);
            break;

        /* ================================================================
         * EDIT NAME — correct a misspelled name (updates source of truth)
         * ================================================================ */
        case 'edit_name':
            $token = trim($_POST['token'] ?? '');
            $event = ci_find_event_by_token($pdo, $token);
            if (!$event) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }
            $newName = trim($_POST['name'] ?? '');
            if ($newName === '') { echo json_encode(['status'=>'error','message'=>'Name cannot be empty.']); exit; }
            $source = $_POST['source'] ?? '';
            $id = (int)($_POST['id'] ?? 0);

            $oldName = '';
            if ($source === 'user') {
                $st = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $st->execute([$id]);
                $u = $st->fetch(PDO::FETCH_ASSOC);
                $oldName = $u ? trim($u['first_name'].' '.$u['last_name']) : '';
                $parts = preg_split('/\s+/', trim($newName), 2);
                $first = $parts[0] ?? '';
                $last = $parts[1] ?? '';
                $upd = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE id = ?");
                $upd->execute([$first, $last, $id]);
            } elseif ($source === 'registration') {
                $st = $pdo->prepare("SELECT guest_name FROM event_registrations WHERE id = ?");
                $st->execute([$id]);
                $oldName = $st->fetchColumn() ?: '';
                $upd = $pdo->prepare("UPDATE event_registrations SET guest_name = ? WHERE id = ?");
                $upd->execute([$newName, $id]);
            } else {
                echo json_encode(['status'=>'error','message'=>'Unknown person type.']); exit;
            }

            // audit log
            $pdo->prepare("INSERT INTO name_edit_log (event_id, user_id, registration_id, old_name, new_name, edited_by)
                           VALUES (?,?,?,?,?,?)")
                ->execute([
                    $event['id'],
                    $source==='user' ? $id : null,
                    $source==='registration' ? $id : null,
                    $oldName, $newName,
                    $_SESSION['user_id'] ?? null
                ]);

            echo json_encode(['status'=>'success','message'=>'Name updated to '.$newName,'name'=>$newName]);
            break;

        /* ================================================================
         * MARK PRESENT — confirm + check in an existing registered person
         * ================================================================ */
        case 'mark_present':
            $token = trim($_POST['token'] ?? '');
            $event = ci_find_event_by_token($pdo, $token);
            if (!$event) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }
            $phone = ci_normalize_phone($_POST['phone'] ?? '');
            if ($phone === null) { echo json_encode(['status'=>'error','message'=>'Invalid phone.']); exit; }
            $name = trim($_POST['name'] ?? '');
            $source = $_POST['source'] ?? '';
            $id = (int)($_POST['id'] ?? 0);
            $isVolunteer = ($_POST['is_volunteer'] ?? '') === '1';
            $edited = (int)($_POST['name_edited'] ?? 0);

            // Determine the day + check-in date (multi-day support) — lenient, never blocks
            $dayInfo = ci_current_day($event);
            // BLOCK check-in if the event is closed (today is outside event_date..end_date)
            if (!$dayInfo['in_window']) {
                echo json_encode(['status'=>'error','message'=>'This event is now closed. Check-in is no longer available.']);
                exit;
            }
            $checkinDate = $dayInfo['checkin_date'];
            $dayLabel = $dayInfo['day_label'];

            // avoid double check-in for THIS DAY
            $dup = $pdo->prepare("SELECT id FROM checkins WHERE event_id = ? AND phone = ? AND checkin_date = ?");
            $dup->execute([$event['id'], $phone, $checkinDate]);
            if ($dup->fetch()) { echo json_encode(['status'=>'success','message'=>'You are already checked in for '.($dayLabel?:'today').'.','already'=>true]); exit; }

            $blessings = ci_blessings();
            $blessingIdx = rand(0, count($blessings)-1);
            $bless = $blessings[$blessingIdx];
            $blessText = $bless['text'];
            $blessRef = $bless['ref'];
            $blessPrayer = str_replace('{name}', explode(' ', $name)[0], $bless['prayer']);

            // Determine member status by EXACT phone match in users table
            $memberUser = ci_find_user_by_phone($pdo, $phone);
            $isMember = $memberUser ? 1 : 0;
            $memberUserId = $memberUser ? (int)$memberUser['id'] : ($source==='user' ? $id : null);

            // IDI staff credited (randomly one of the two IDI members) per your choice
            $checkedBy = ci_idi_staff_id();

            $pdo->prepare("INSERT INTO checkins
                (event_id, checkin_date, user_id, registration_id, full_name, phone, is_member, is_walkin, name_edited, source, checked_in_by, blessing_idx, blessing_text, blessing_ref, blessing_prayer)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $event['id'], $checkinDate,
                    $memberUserId,
                    $source==='registration' ? $id : null,
                    $name, $phone,
                    $isMember,
                    0, // not walkin
                    $edited,
                    $isVolunteer ? 'volunteer' : 'self',
                    $checkedBy,
                    $blessingIdx, $blessText, $blessRef, $blessPrayer
                ]);

            // If they are a member, ALSO write to the existing attendance table
            ci_write_attendance($pdo, $event['id'], $memberUserId, $checkedBy, $checkinDate);

            echo json_encode([
                'status'=>'success',
                'message'=>'Welcome, '.$name.'! You are marked present'.($dayLabel?' for '.$dayLabel:'').'.',
                'blessing'=>$bless,
                'first_name'=>explode(' ', $name)[0],
                'day_label'=>$dayLabel,
                'already'=>false
            ]);
            break;

        /* ================================================================
         * REGISTER + MARK PRESENT — walk-in registers on the spot
         * ================================================================ */
        case 'register_and_present':
            $token = trim($_POST['token'] ?? '');
            $event = ci_find_event_by_token($pdo, $token);
            if (!$event) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }
            $phone = ci_normalize_phone($_POST['phone'] ?? '');
            if ($phone === null) { echo json_encode(['status'=>'error','message'=>'Invalid phone number.']); exit; }
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { echo json_encode(['status'=>'error','message'=>'Please enter your full name.']); exit; }
            $isVolunteer = ($_POST['is_volunteer'] ?? '') === '1';

            // Determine the day + check-in date (multi-day support) — lenient, never blocks
            $dayInfo = ci_current_day($event);
            // BLOCK check-in if the event is closed (today is outside event_date..end_date)
            if (!$dayInfo['in_window']) {
                echo json_encode(['status'=>'error','message'=>'This event is now closed. Registration and check-in are no longer available.']);
                exit;
            }
            $checkinDate = $dayInfo['checkin_date'];
            $dayLabel = $dayInfo['day_label'];

            // check duplicate check-in for THIS DAY
            $dup = $pdo->prepare("SELECT id FROM checkins WHERE event_id = ? AND phone = ? AND checkin_date = ?");
            $dup->execute([$event['id'], $phone, $checkinDate]);
            if ($dup->fetch()) { echo json_encode(['status'=>'success','message'=>'You are already checked in for '.($dayLabel?:'today').'.','already'=>true]); exit; }

            // create a guest registration (registration-only, per your choice)
            // capture how they heard about the event into custom_responses (JSON)
            $invitationSource = trim($_POST['source'] ?? '');
            if (!in_array($invitationSource, ['Self_Discovery','Social_Media','Broadcast','Media','Invited_By','Flyer_Banner_Poster','Other'], true)) {
                $invitationSource = ''; // only store known values
            }
            $customResponses = $invitationSource !== ''
                ? json_encode(['invitation_source' => $invitationSource])
                : null;

            $pdo->prepare("INSERT INTO event_registrations (event_id, guest_name, guest_phone, match_status, custom_responses)
                           VALUES (?,?,?, 'none', ?)")
                ->execute([$event['id'], $name, $phone, $customResponses]);
            $newRegId = (int)$pdo->lastInsertId();

            $blessings = ci_blessings();
            $blessingIdx = rand(0, count($blessings)-1);
            $bless = $blessings[$blessingIdx];
            $blessText = $bless['text'];
            $blessRef = $bless['ref'];
            $blessPrayer = str_replace('{name}', explode(' ', $name)[0], $bless['prayer']);

            // A "walk-in" might actually be a member who didn't pre-register —
            // check exact phone in users table so we still log their attendance.
            $memberUser = ci_find_user_by_phone($pdo, $phone);
            $isMember = $memberUser ? 1 : 0;
            $memberUserId = $memberUser ? (int)$memberUser['id'] : null;

            // IDI staff credited (randomly one of the two IDI members) per your choice
            $checkedBy = ci_idi_staff_id();

            $pdo->prepare("INSERT INTO checkins
                (event_id, checkin_date, user_id, registration_id, full_name, phone, is_member, is_walkin, name_edited, source, checked_in_by, blessing_idx, blessing_text, blessing_ref, blessing_prayer)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $event['id'], $checkinDate, $memberUserId, $newRegId, $name, $phone,
                    $isMember, 1, 0, $isVolunteer ? 'volunteer' : 'self', $checkedBy, $blessingIdx,
                    $blessText, $blessRef, $blessPrayer
                ]);

            // If the walk-in turns out to be a member, ALSO write to attendance
            ci_write_attendance($pdo, $event['id'], $memberUserId, $checkedBy, $checkinDate);

            echo json_encode([
                'status'=>'success',
                'message'=>'Thank you, '.$name.'! You are now registered and marked present'.($dayLabel?' for '.$dayLabel:'').'.',
                'blessing'=>$bless,
                'first_name'=>explode(' ', $name)[0],
                'day_label'=>$dayLabel,
                'already'=>false
            ]);
            break;

        /* ================================================================
         * GET MY BLESSING — retrieve a person's stored blessing by phone
         * (Day-2: re-entering a number returns their saved message)
         * ================================================================ */
        case 'get_my_blessing':
            $token = trim($_POST['token'] ?? '');
            $event = ci_find_event_by_token($pdo, $token);
            if (!$event) { echo json_encode(['status'=>'error','message'=>'Event not found.']); exit; }
            $phone = ci_normalize_phone($_POST['phone'] ?? '');
            if ($phone === null) { echo json_encode(['status'=>'error','message'=>'Invalid phone number.']); exit; }

            // most recent stored blessing for this phone + event
            $st = $pdo->prepare("SELECT full_name, blessing_text, blessing_ref, blessing_prayer
                                 FROM checkins
                                 WHERE event_id = ? AND phone = ?
                                   AND blessing_text IS NOT NULL
                                 ORDER BY id DESC LIMIT 1");
            $st->execute([$event['id'], $phone]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(['status'=>'error','message'=>'No saved message found for this number yet.']);
                exit;
            }

            echo json_encode([
                'status'=>'success',
                'first_name'=>explode(' ', $row['full_name'])[0],
                'blessing'=>[
                    'text'=>$row['blessing_text'],
                    'ref'=>$row['blessing_ref'],
                    'prayer'=>$row['blessing_prayer'],
                ],
            ]);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Invalid action.']);
            break;
    }
} catch (PDOException $e) {
    error_log("Checkin API Error: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>'A system error occurred.']);
}
