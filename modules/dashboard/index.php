<?php
// /modules/dashboard/index.php
// An older copy of the home dashboard used to live here. It required
// 'includes/header.php' by a path relative to the site root, which does not
// resolve from this folder, so it answered HTTP 500 — while Departments still
// linked to it ("Back to Dashboard"). The dashboard is /index.php; this URL
// now sends people there (sign-in and the role-based landing happen there).
header('Location: /', true, 302);
exit;
