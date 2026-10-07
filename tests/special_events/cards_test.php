<?php
// /tests/special_events/cards_test.php — eligibility for a repeatable
// "I'm going" card (guide §14.3).

echo "    active registration eligibility\n";

ok('a confirmed registration can make the card', se_card_registration_is_active(['status' => 'confirmed']));
ok('a waitlisted registration can make the card', se_card_registration_is_active(['status' => 'waitlisted']));
ok('a cancelled registration cannot make the card', !se_card_registration_is_active(['status' => 'cancelled']));
ok('a removed registration cannot make the card', !se_card_registration_is_active(['status' => 'removed']));
ok('a missing registration status is not eligible', !se_card_registration_is_active([]));
