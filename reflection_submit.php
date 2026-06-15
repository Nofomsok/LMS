<?php

require_once __DIR__ . '/includes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$moduleId = (int) (isset($_POST['module_id']) ? $_POST['module_id'] : 0);
$text = trim((string) (isset($_POST['reflection_text']) ? $_POST['reflection_text'] : ''));
$startedAt = (int) (isset($_POST['started_at']) ? $_POST['started_at'] : 0);
$honeypot = trim((string) (isset($_POST['website']) ? $_POST['website'] : ''));
$challengeResponse = isset($_POST['challenge_answer']) ? $_POST['challenge_answer'] : '';
$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

$stmt = db()->prepare('SELECT slug FROM modules WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$moduleId]);
$module = $stmt->fetch();
$returnUrl = $module ? page_url($module['slug']) : page_url('overview');

if (!verify_csrf(is_string($token) ? $token : null) || $honeypot !== '' || time() - $startedAt < REFLECTION_MIN_SECONDS || !$module || $text === '' || strlen($text) > 2000 || !validate_reflection_challenge($moduleId, $challengeResponse)) {
    redirect($returnUrl . '&comment=blocked');
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$ipHash = hash('sha256', $ip . DB_NAME);
$ua = substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 255);

$rate = db()->prepare('SELECT COUNT(*) FROM reflections WHERE ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
$rate->execute([$ipHash]);
if ((int) $rate->fetchColumn() >= REFLECTION_MAX_PER_HOUR) {
    redirect($returnUrl . '&comment=rate');
}

$hasStatus = reflection_status_available();
if ($hasStatus) {
    $insert = db()->prepare('INSERT INTO reflections (module_id, reflection_text, status, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$moduleId, $text, 'pending', $ipHash, $ua]);
} else {
    $insert = db()->prepare('INSERT INTO reflections (module_id, reflection_text, ip_hash, user_agent) VALUES (?, ?, ?, ?)');
    $insert->execute([$moduleId, $text, $ipHash, $ua]);
}

redirect($returnUrl . '&comment=saved');
