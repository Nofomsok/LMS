<?php

require_once __DIR__ . '/includes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(page_url('complete'));
}

require_course_user();

$userId = current_course_user_id();
$rating = (int) (isset($_POST['rating']) ? $_POST['rating'] : 0);
$feedbackText = trim((string) (isset($_POST['feedback_text']) ? $_POST['feedback_text'] : ''));
$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
$returnUrl = page_url('complete') . '#course-feedback';

if ($userId <= 0 || !verify_csrf(is_string($token) ? $token : null)) {
    redirect(page_url('complete') . '&feedback=blocked#course-feedback');
}

if (!course_feedback_available()) {
    redirect(page_url('complete') . '&feedback=unavailable#course-feedback');
}

if ($rating < 1 || $rating > 5) {
    redirect(page_url('complete') . '&feedback=rating#course-feedback');
}

if (strlen($feedbackText) > 2000) {
    redirect(page_url('complete') . '&feedback=long#course-feedback');
}

$stmt = db()->prepare('INSERT INTO course_feedback (course_user_id, rating, feedback_text) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), feedback_text = VALUES(feedback_text), updated_at = CURRENT_TIMESTAMP');
$stmt->execute([$userId, $rating, $feedbackText !== '' ? $feedbackText : null]);

redirect(page_url('complete') . '&feedback=saved#course-feedback');
