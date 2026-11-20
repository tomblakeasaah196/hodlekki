<?php
// /api/department_attendance_export.php
//
// The department attendance roll for one event as a CSV: the absent workers
// by default (?scope=absent), or everyone (?scope=all), department by
// department. Same roll the Departments Attendance tab shows, from the same
// helper (includes/department_attendance.php).
//
//   ?event_id=<id>   required
//   &scope=absent|all   defaults to absent
//
// A direct link, not AJAX, so the browser performs the download (the pattern
// of api/department_export_excel.php). It lists names and phone numbers, so it
// follows the same permission: departments / export.

require_once '../includes/db.php';
require_once '../includes/department_helpers.php';
require_once '../includes/department_attendance.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized. Please log in.');
}
if (!can('departments', 'export')) {
    http_response_code(403);
    die('Access Denied. You do not have clearance to export department rosters.');
}
if (!dept_schema_ready($pdo)) {
    http_response_code(503);
    die(dept_schema_message());
}

$event = dept_att_event($pdo, (int) ($_GET['event_id'] ?? 0));
if (!$event) {
    http_response_code(404);
    die('Event not found.');
}
$year = dept_active_year($pdo);
if (!$year) {
    http_response_code(404);
    die('No ministry year configured.');
}

$scope = ($_GET['scope'] ?? 'absent') === 'all' ? 'all' : 'absent';
$roll = dept_att_roll($pdo, $event['id'], (int) $year['id'], dept_leader_roles());
$rows = dept_att_export_rows($roll, $scope, dept_role_labels());

$slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $event['title']), '_');
$file = ($scope === 'absent' ? 'Absent_' : 'Attendance_') . ($slug !== '' ? $slug : 'Event') . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel opens UTF-8 names correctly

$t = $roll['totals'];
fputcsv($out, [dept_att_csv_cell((string) $event['title'])]);
fputcsv($out, [($scope === 'absent' ? 'Absent workers' : 'All workers') . ' · ' . date('j M Y', strtotime((string) $event['event_date']))]);
fputcsv($out, ["{$t['present']} present of {$t['workers']} · {$t['absent']} absent · generated " . date('j M Y, g:i A')]);
fputcsv($out, []);
fputcsv($out, array_merge(['#'], $rows ? array_keys($rows[0]) : ['Department', 'Part of', 'Name', 'Phone', 'Seat', 'Status', 'Time in']));
$n = 0;
foreach ($rows as $r) {
    $n++;
    fputcsv($out, array_merge([$n], array_map('dept_att_csv_cell', array_values($r))));
}
fclose($out);
