<?php
// /includes/special_events/kjv_bundle.php
//
// The KJV text of every verse the ready-made content uses: the joy verses
// for the welcome cards and the Finish-the-verse questions (Appendix G).
//
// Copied verbatim from the public-domain KJV that bible-api.com serves
// (eng-kjv.osis.xml in github.com/seven1m/open-bibles), and checked word for
// word against a second KJV edition. se_bible_lookup() answers from here
// when the cache has nothing, so the ready-made verses work even when the
// Bible service cannot be reached from the server. Never edit a text by
// hand: a verse that is not here is fetched from the service as before.
//
// Keys are se_bible_ref_normalize()'s ref_norm.

function se_kjv_bundle(): array
{
    return [
        'nehemiah 8:10'     => 'Then he said unto them, Go your way, eat the fat, and drink the sweet, and send portions unto them for whom nothing is prepared: for this day is holy unto our Lord: neither be ye sorry; for the joy of the LORD is your strength.',
        'psalms 16:11'      => 'Thou wilt shew me the path of life: in thy presence is fulness of joy; at thy right hand there are pleasures for evermore.',
        'psalms 30:5'       => 'For his anger endureth but a moment; in his favour is life: weeping may endure for a night, but joy cometh in the morning.',
        'psalms 95:1'       => 'O come, let us sing unto the LORD: let us make a joyful noise to the rock of our salvation.',
        'psalms 98:4'       => 'Make a joyful noise unto the LORD, all the earth: make a loud noise, and rejoice, and sing praise.',
        'psalms 100:1-2'    => 'Make a joyful noise unto the LORD, all ye lands. Serve the LORD with gladness: come before his presence with singing.',
        'psalms 118:24'     => 'This is the day which the LORD hath made; we will rejoice and be glad in it.',
        'psalms 126:2'      => 'Then was our mouth filled with laughter, and our tongue with singing: then said they among the heathen, The LORD hath done great things for them.',
        'psalms 126:3'      => 'The LORD hath done great things for us; whereof we are glad.',
        'proverbs 17:22'    => 'A merry heart doeth good like a medicine: but a broken spirit drieth the bones.',
        'ecclesiastes 3:4'  => 'A time to weep, and a time to laugh; a time to mourn, and a time to dance;',
        'isaiah 55:12'      => 'For ye shall go out with joy, and be led forth with peace: the mountains and the hills shall break forth before you into singing, and all the trees of the field shall clap their hands.',
        'zephaniah 3:17'    => 'The LORD thy God in the midst of thee is mighty; he will save, he will rejoice over thee with joy; he will rest in his love, he will joy over thee with singing.',
        'luke 2:10'         => 'And the angel said unto them, Fear not: for, behold, I bring you good tidings of great joy, which shall be to all people.',
        'john 15:11'        => 'These things have I spoken unto you, that my joy might remain in you, and that your joy might be full.',
        'john 16:24'        => 'Hitherto have ye asked nothing in my name: ask, and ye shall receive, that your joy may be full.',
        'romans 15:13'      => 'Now the God of hope fill you with all joy and peace in believing, that ye may abound in hope, through the power of the Holy Ghost.',
        'galatians 5:22-23' => 'But the fruit of the Spirit is love, joy, peace, longsuffering, gentleness, goodness, faith, Meekness, temperance: against such there is no law.',
        'philippians 4:4'   => 'Rejoice in the Lord alway: and again I say, Rejoice.',
        'james 1:2'         => 'My brethren, count it all joy when ye fall into divers temptations;',
        '1 peter 1:8'       => 'Whom having not seen, ye love; in whom, though now ye see him not, yet believing, ye rejoice with joy unspeakable and full of glory:',
        'psalms 5:11'       => 'But let all those that put their trust in thee rejoice: let them ever shout for joy, because thou defendest them: let them also that love thy name be joyful in thee.',
        'john 3:16'         => 'For God so loved the world, that he gave his only begotten Son, that whosoever believeth in him should not perish, but have everlasting life.',
        'psalms 23:1'       => 'The LORD is my shepherd; I shall not want.',
        'psalms 119:105'    => 'Thy word is a lamp unto my feet, and a light unto my path.',
        'matthew 6:33'      => 'But seek ye first the kingdom of God, and his righteousness; and all these things shall be added unto you.',
    ];
}
