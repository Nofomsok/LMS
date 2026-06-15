<?php

require_once __DIR__ . '/includes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(page_url('complete'));
}

require_course_user();

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
$userId = current_course_user_id();

if ($userId <= 0 || !verify_csrf(is_string($token) ? $token : null)) {
    redirect(page_url('complete') . '&retake=blocked');
}

if (video_progress_available()) {
    $stmt = db()->prepare('DELETE FROM video_progress WHERE course_user_id = ?');
    $stmt->execute([$userId]);
}

redirect(page_url('overview') . '&retake=started');