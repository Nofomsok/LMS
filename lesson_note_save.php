<?php

require_once __DIR__ . '/includes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(page_url('overview'));
}

require_course_user();

$moduleId = (int) (isset($_POST['module_id']) ? $_POST['module_id'] : 0);
$noteText = trim((string) (isset($_POST['note_text']) ? $_POST['note_text'] : ''));
$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

$stmt = db()->prepare('SELECT slug FROM modules WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$moduleId]);
$module = $stmt->fetch();
$returnUrl = $module ? page_url($module['slug']) : page_url('overview');

if (!verify_csrf(is_string($token) ? $token : null) || !$module || strlen($noteText) > 5000) {
    redirect($returnUrl . '&note=blocked');
}

if (!save_learner_note($moduleId, $noteText)) {
    redirect($returnUrl . '&note=unavailable');
}

redirect($returnUrl . '&note=saved');