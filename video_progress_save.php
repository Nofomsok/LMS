<?php

require_once __DIR__ . '/includes.php';
require_course_user();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Method not allowed.'));
    exit;
}

$userId = current_course_user_id();
if ($userId <= 0) {
    echo json_encode(array('ok' => true, 'tracked' => false));
    exit;
}

if (!video_progress_available()) {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'Video progress table is not installed.'));
    exit;
}

$moduleId = (int) (isset($_POST['module_id']) ? $_POST['module_id'] : 0);
$currentSeconds = (int) floor((float) (isset($_POST['current_seconds']) ? $_POST['current_seconds'] : 0));
$durationSeconds = (int) floor((float) (isset($_POST['duration_seconds']) ? $_POST['duration_seconds'] : 0));
$percentWatched = (float) (isset($_POST['percent_watched']) ? $_POST['percent_watched'] : 0);
$completed = !empty($_POST['is_completed']) ? 1 : 0;

if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'Security check failed.'));
    exit;
}

$stmt = db()->prepare('SELECT id FROM modules WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$moduleId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'Lesson not found.'));
    exit;
}

$currentSeconds = max(0, $currentSeconds);
$durationSeconds = max(0, $durationSeconds);
if ($durationSeconds > 0) {
    $percentWatched = max($percentWatched, ($currentSeconds / $durationSeconds) * 100);
}
$percentWatched = min(100, max(0, $percentWatched));
if ($percentWatched >= 90) {
    $completed = 1;
}

$stmt = db()->prepare('INSERT INTO video_progress (course_user_id, module_id, current_seconds, duration_seconds, percent_watched, is_completed) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE current_seconds = VALUES(current_seconds), duration_seconds = GREATEST(duration_seconds, VALUES(duration_seconds)), percent_watched = GREATEST(percent_watched, VALUES(percent_watched)), is_completed = GREATEST(is_completed, VALUES(is_completed)), updated_at = CURRENT_TIMESTAMP');
$stmt->execute([$userId, $moduleId, $currentSeconds, $durationSeconds, $percentWatched, $completed]);

echo json_encode(array(
    'ok' => true,
    'tracked' => true,
    'percent_watched' => round($percentWatched, 2),
    'is_completed' => $completed,
));

