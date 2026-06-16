<?php

require_once __DIR__ . '/config.php';

session_start();

function db()
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_logo_markup($training = false)
{
    $mark = $training ? '#9be4dc' : '#008777';
    $text = $training ? '#ffffff' : 'currentColor';

    return '
        <a class="brand" href="index.php" aria-label="Return to home">
          <svg width="44" height="44" viewBox="0 0 64 64" aria-hidden="true">
            <rect x="10" y="12" width="44" height="38" rx="6" fill="none" stroke="' . $mark . '" stroke-width="3"/>
            <path d="M18 24h28M18 33h14M18 42h22" fill="none" stroke="' . $mark . '" stroke-width="3" stroke-linecap="round"/>
            <path d="M43 18v-6M32 18v-6M21 18v-6" fill="none" stroke="' . $mark . '" stroke-width="3" stroke-linecap="round"/>
          </svg>
          <span style="color:' . $text . '">LMS DEMO<small>Learning platform</small></span>
        </a>';
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        if (function_exists('random_bytes')) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        } else {
            $_SESSION['csrf_token'] = sha1(uniqid('', true) . mt_rand());
        }
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf($token)
{
    $sessionToken = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    return is_string($token) && hash_equals($sessionToken, $token);
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function current_base_url()
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $basePath = trim(APP_BASE_PATH, '/');
    if ($basePath === '' && isset($_SERVER['SCRIPT_NAME'])) {
        $basePath = trim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
        if ($basePath === '.') {
            $basePath = '';
        }
        if (substr($basePath, -6) === '/admin') {
            $basePath = substr($basePath, 0, -6);
        } elseif ($basePath === 'admin') {
            $basePath = '';
        }
    }

    return $scheme . '://' . $host . ($basePath !== '' ? '/' . $basePath : '');
}

function page_url($page)
{
    return $page === 'home' ? 'index.php' : 'index.php?page=' . rawurlencode($page);
}

function is_admin()
{
    return !empty($_SESSION['admin_logged_in']);
}

function is_master_admin()
{
    return is_admin() && isset($_SESSION['admin_username']) && hash_equals(ADMIN_USERNAME, (string) $_SESSION['admin_username']);
}

function require_admin()
{
    if (!is_admin()) {
        redirect('login.php');
    }
}

function admin_users_available()
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([DB_NAME, 'admin_users']);
        $available = (int) $stmt->fetchColumn() === 1;
    } catch (Exception $exception) {
        $available = false;
    }

    return $available;
}

function get_admin_users()
{
    if (!admin_users_available()) {
        return array();
    }

    return db()->query('SELECT id, username, email, is_active, created_at, updated_at FROM admin_users ORDER BY username ASC')->fetchAll();
}

function course_users_available()
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([DB_NAME, 'course_users']);
        $available = (int) $stmt->fetchColumn() === 1;
    } catch (Exception $exception) {
        $available = false;
    }

    return $available;
}

function is_course_user()
{
    return !empty($_SESSION['course_user_id']) && ((int) $_SESSION['course_user_id'] === -1 || course_users_available());
}

function require_course_user()
{
    if (!is_course_user()) {
        $next = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'index.php?page=overview';
        redirect('login.php?next=' . rawurlencode($next));
    }
}

function current_course_user()
{
    if (!is_course_user()) {
        return null;
    }

    if ((int) $_SESSION['course_user_id'] === -1 && is_admin()) {
        return array(
            'id' => -1,
            'email' => isset($_SESSION['course_user_email']) ? $_SESSION['course_user_email'] : 'admin',
            'full_name' => 'Administrator',
            'status' => 'approved',
        );
    }

    $stmt = db()->prepare('SELECT * FROM course_users WHERE id = ? AND status = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['course_user_id'], 'approved']);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['course_user_id'], $_SESSION['course_user_email']);
        return null;
    }

    return $user;
}

function verify_course_login($login, $password)
{
    if (!course_users_available()) {
        return false;
    }

    $login = strtolower(trim((string) $login));
    $stmt = db()->prepare('SELECT id, email, full_name, password_hash, status FROM course_users WHERE LOWER(email) = ? OR LOWER(full_name) = ? LIMIT 2');
    $stmt->execute([$login, $login]);
    $users = $stmt->fetchAll();

    if (count($users) !== 1) {
        return false;
    }

    $user = $users[0];
    $password = (string) $password;
    $passwordMatches = password_verify($password, $user['password_hash']);
    if (!$passwordMatches && trim($password) !== $password) {
        $passwordMatches = password_verify(trim($password), $user['password_hash']);
    }

    if ($user['status'] !== 'approved' || !$passwordMatches) {
        return false;
    }

    $_SESSION['course_user_id'] = (int) $user['id'];
    $_SESSION['course_user_email'] = $user['email'];
    db()->prepare('UPDATE course_users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);

    return true;
}

function get_course_users()
{
    if (!course_users_available()) {
        return array();
    }

    return db()->query('SELECT * FROM course_users ORDER BY FIELD(status, "pending", "approved", "rejected"), created_at DESC')->fetchAll();
}

function get_authorized_user_emails()
{
    if (!course_users_available()) {
        return array();
    }

    return db()->query('SELECT * FROM authorized_user_emails ORDER BY email ASC')->fetchAll();
}

function email_is_authorized($email)
{
    $stmt = db()->prepare('SELECT id FROM authorized_user_emails WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim((string) $email))]);
    return (bool) $stmt->fetch();
}

function random_password($length = 12)
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $password;
}

function send_app_email($to, $subject, $body)
{
    $headers = "From: " . APP_NAME . " <no-reply@" . preg_replace('/^www\./', '', parse_url(current_base_url(), PHP_URL_HOST)) . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($to, $subject, $body, $headers);
}

function send_course_password_email($email, $password)
{
    $body = "Your course account has been approved.\n\n";
    $body .= "Login: " . current_base_url() . "/login.php\n";
    $body .= "Email: " . $email . "\n";
    $body .= "Password: " . $password . "\n\n";
    $body .= "Please sign in and keep this password private.";

    return send_app_email($email, 'Your course login details', $body);
}

function send_course_registration_email($email, $name, $approved)
{
    $body = "Hello " . $name . ",\n\n";
    if ($approved) {
        $body .= "Your registration for " . APP_NAME . " has been received and approved.\n";
        $body .= "A separate email with your login details has been sent.\n\n";
    } else {
        $body .= "Your registration for " . APP_NAME . " has been received and is waiting for admin approval.\n";
        $body .= "You will receive login details by email after your account is approved.\n\n";
    }
    $body .= "Sign in page: " . current_base_url() . "/login.php\n\n";
    $body .= "Thank you.";

    return send_app_email($email, 'Registration received', $body);
}

function math_challenge($key)
{
    $a = random_int(2, 9);
    $b = random_int(1, 9);
    $op = random_int(0, 1) === 1 ? '+' : '-';

    if ($op === '-' && $a < $b) {
        $tmp = $a;
        $a = $b;
        $b = $tmp;
    }

    $_SESSION[$key] = array(
        'question' => $a . ' ' . $op . ' ' . $b . ' = ?',
        'answer' => (string) ($op === '+' ? $a + $b : $a - $b),
        'created_at' => time(),
    );

    return $_SESSION[$key]['question'];
}

function verify_math_challenge($key, $response)
{
    if (empty($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return false;
    }

    $challenge = $_SESSION[$key];
    unset($_SESSION[$key]);

    if (!isset($challenge['created_at']) || time() - (int) $challenge['created_at'] > 600) {
        return false;
    }

    return trim((string) $response) === (string) $challenge['answer'];
}

function verify_admin_login($username, $password)
{
    if (admin_users_available()) {
        $stmt = db()->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_user_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_source'] = 'database';
            return true;
        }
    }

    if (!hash_equals(ADMIN_USERNAME, $username)) {
        return false;
    }

    if (ADMIN_PASSWORD_HASH !== '') {
        $valid = password_verify($password, ADMIN_PASSWORD_HASH);
    } else {
        $valid = hash_equals(ADMIN_PASSWORD, $password);
    }

    if ($valid) {
        $_SESSION['admin_user_id'] = 0;
        $_SESSION['admin_username'] = ADMIN_USERNAME;
        $_SESSION['admin_source'] = 'config';
    }

    return $valid;
}

function admin_login_challenge()
{
    return math_challenge('admin_login_challenge');
}

function verify_admin_login_challenge($response)
{
    return verify_math_challenge('admin_login_challenge', $response);
}

function youtube_embed_url($url)
{
    $videoId = youtube_video_id($url);
    if ($videoId !== '') {
        return 'https://www.youtube.com/embed/' . $videoId;
    }

    return $url;
}

function youtube_video_id($url)
{
    if (preg_match('~(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{6,})~', (string) $url, $match)) {
        return $match[1];
    }

    return '';
}

function youtube_embed_url_with_api($url, $startSeconds = 0)
{
    $embed = youtube_embed_url($url);
    $params = array(
        'enablejsapi' => '1',
        'rel' => '0',
    );
    $startSeconds = max(0, (int) $startSeconds);
    if ($startSeconds > 0) {
        $params['start'] = (string) $startSeconds;
    }

    $separator = strpos($embed, '?') === false ? '?' : '&';
    return $embed . $separator . http_build_query($params);
}

function get_modules($includeInactive = false)
{
    $sql = 'SELECT * FROM modules';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $modules = db()->query($sql)->fetchAll();

    foreach ($modules as &$module) {
        $module['focus'] = json_decode($module['focus_json'] ?: '[]', true) ?: [];
    }

    return $modules;
}

function get_module_by_slug($slug)
{
    $stmt = db()->prepare('SELECT * FROM modules WHERE slug = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$slug]);
    $module = $stmt->fetch();

    if (!$module) {
        return null;
    }

    $module['focus'] = json_decode($module['focus_json'] ?: '[]', true) ?: [];

    return $module;
}

function get_training_resources()
{
    return db()->query('SELECT * FROM resources ORDER BY sort_order ASC, id ASC')->fetchAll();
}

function site_settings_available()
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([DB_NAME, 'site_settings']);
        $available = (int) $stmt->fetchColumn() === 1;
    } catch (Exception $exception) {
        $available = false;
    }

    return $available;
}

function get_site_settings()
{
    $defaults = array(
        'landing_title' => 'LMS DEMO',
        'landing_intro' => 'A ready-to-demo learning management system for selling online training, onboarding, and member education.',
        'landing_button' => 'Start Course',
        'welcome_title' => 'Welcome',
        'welcome_intro' => 'This demo shows how learners move through lessons, watch videos, track progress, download resources, and submit comments while admins manage users, content, approvals, and reporting.',
        'pathway_title' => 'Course Pathway',
        'training_tips_title' => 'How to Use This Training',
        'training_tips' => "Work through the lessons in order\nPause and reflect when prompted\nApply learnings to your practice",
        'training_widget_2_title' => 'Before You Begin',
        'training_widget_2_text' => "Choose a quiet time to complete each section\nKeep a patient or family conversation in mind\nUse comments and private notes to capture practical next steps",
        'training_widget_3_title' => 'After Each Lesson',
        'training_widget_3_text' => "Review the key question\nIdentify one phrase or skill to practice\nReturn to the course pathway when you are ready to continue",
    );

    if (!site_settings_available()) {
        return $defaults;
    }

    $settings = $defaults;
    $rows = db()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    return $settings;
}

function save_site_settings($settings)
{
    if (!site_settings_available()) {
        return false;
    }

    $stmt = db()->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    return true;
}

function get_module_reflections($moduleId, $limit = 6)
{
    $hasStatus = reflection_status_available();
    if ($hasStatus) {
        $stmt = db()->prepare('SELECT reflection_text, created_at FROM reflections WHERE module_id = ? AND status = ? ORDER BY created_at DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, (int) $moduleId, PDO::PARAM_INT);
        $stmt->bindValue(2, 'approved', PDO::PARAM_STR);
        $stmt->bindValue(3, (int) $limit, PDO::PARAM_INT);
    } else {
        $stmt = db()->prepare('SELECT reflection_text, created_at FROM reflections WHERE module_id = ? ORDER BY created_at DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, (int) $moduleId, PDO::PARAM_INT);
        $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetchAll();
}

function reflection_status_available()
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
        $stmt->execute([DB_NAME, 'reflections', 'status']);
        $available = (int) $stmt->fetchColumn() === 1;
    } catch (Exception $exception) {
        $available = false;
    }

    return $available;
}

function resource_meta_columns_present()
{
    static $present = null;

    if ($present !== null) {
        return $present;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name IN (?, ?)');
    $stmt->execute(array(DB_NAME, 'resources', 'file_name', 'file_type'));
    $present = (int) $stmt->fetchColumn() === 2;

    return $present;
}

function resource_download_target($resource)
{
    $url = isset($resource['file_url']) ? $resource['file_url'] : '';
    if ($url !== '' && $url !== '#') {
        return '_blank';
    }

    return '_self';
}

function resource_download_rel($resource)
{
    return resource_download_target($resource) === '_blank' ? 'noopener noreferrer' : '';
}

function learner_notes_available()
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([DB_NAME, 'learner_notes']);
        $available = (int) $stmt->fetchColumn() === 1;
    } catch (Exception $exception) {
        $available = false;
    }

    return $available;
}

function get_learner_notes($moduleId)
{
    $userId = current_course_user_id();
    if ($userId <= 0 || !learner_notes_available()) {
        return array();
    }

    $stmt = db()->prepare('SELECT id, note_text, created_at, updated_at FROM learner_notes WHERE course_user_id = ? AND module_id = ? ORDER BY updated_at DESC, id DESC');
    $stmt->execute([$userId, (int) $moduleId]);

    return $stmt->fetchAll();
}

function save_learner_note($moduleId, $noteText, $noteId = 0)
{
    $userId = current_course_user_id();
    if ($userId <= 0 || !learner_notes_available()) {
        return false;
    }

    if ((int) $noteId > 0) {
        $stmt = db()->prepare('UPDATE learner_notes SET note_text = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND course_user_id = ? AND module_id = ?');
        $stmt->execute([(string) $noteText, (int) $noteId, $userId, (int) $moduleId]);
        return $stmt->rowCount() > 0;
    }

    $stmt = db()->prepare('INSERT INTO learner_notes (course_user_id, module_id, note_text) VALUES (?, ?, ?)');
    $stmt->execute([$userId, (int) $moduleId, (string) $noteText]);

    return true;
}

function delete_learner_note($moduleId, $noteId)
{
    $userId = current_course_user_id();
    if ($userId <= 0 || !learner_notes_available() || (int) $noteId <= 0) {
        return false;
    }

    $stmt = db()->prepare('DELETE FROM learner_notes WHERE id = ? AND course_user_id = ? AND module_id = ?');
    $stmt->execute([(int) $noteId, $userId, (int) $moduleId]);

    return $stmt->rowCount() > 0;
}
function video_progress_available()
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([DB_NAME, 'video_progress']);
        $available = (int) $stmt->fetchColumn() === 1;
    } catch (Exception $exception) {
        $available = false;
    }

    return $available;
}

function current_course_user_id()
{
    if (!is_course_user() || empty($_SESSION['course_user_id'])) {
        return 0;
    }

    $id = (int) $_SESSION['course_user_id'];
    return $id > 0 ? $id : 0;
}

function get_video_progress($moduleId)
{
    $userId = current_course_user_id();
    if ($userId <= 0 || !video_progress_available()) {
        return array(
            'current_seconds' => 0,
            'duration_seconds' => 0,
            'percent_watched' => 0,
            'is_completed' => 0,
        );
    }

    $stmt = db()->prepare('SELECT current_seconds, duration_seconds, percent_watched, is_completed, updated_at FROM video_progress WHERE course_user_id = ? AND module_id = ? LIMIT 1');
    $stmt->execute([$userId, (int) $moduleId]);
    $progress = $stmt->fetch();

    if (!$progress) {
        return array(
            'current_seconds' => 0,
            'duration_seconds' => 0,
            'percent_watched' => 0,
            'is_completed' => 0,
        );
    }

    return $progress;
}

function get_video_progress_map($userId = 0)
{
    $userId = $userId > 0 ? (int) $userId : current_course_user_id();
    if ($userId <= 0 || !video_progress_available()) {
        return array();
    }

    $stmt = db()->prepare('SELECT module_id, current_seconds, duration_seconds, percent_watched, is_completed, updated_at FROM video_progress WHERE course_user_id = ?');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    $map = array();
    foreach ($rows as $row) {
        $map[(int) $row['module_id']] = $row;
    }

    return $map;
}

function get_admin_video_progress_rows()
{
    if (!video_progress_available()) {
        return array();
    }

    return db()->query('SELECT video_progress.*, course_users.full_name, course_users.email, modules.module_type, modules.display_number, modules.title AS module_title FROM video_progress JOIN course_users ON course_users.id = video_progress.course_user_id JOIN modules ON modules.id = video_progress.module_id ORDER BY video_progress.updated_at DESC, course_users.full_name ASC, modules.sort_order ASC')->fetchAll();
}

function progress_percent_label($progress)
{
    if (!$progress || !isset($progress['percent_watched'])) {
        return '0%';
    }

    return (string) min(100, max(0, (int) round((float) $progress['percent_watched']))) . '%';
}

function format_video_time($seconds)
{
    $seconds = max(0, (int) $seconds);
    $minutes = floor($seconds / 60);
    $remaining = $seconds % 60;

    return $minutes . ':' . str_pad((string) $remaining, 2, '0', STR_PAD_LEFT);
}

function next_sort_order()
{
    return (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM modules')->fetchColumn();
}

function reflection_challenge_for_module($moduleId)
{
    if (!isset($_SESSION['reflection_challenges']) || !is_array($_SESSION['reflection_challenges'])) {
        $_SESSION['reflection_challenges'] = array();
    }

    $a = random_int(2, 9);
    $b = random_int(1, 9);
    $ops = array('+', '-');
    $op = $ops[array_rand($ops)];
    $answer = $op === '+' ? $a + $b : max(1, $a - $b);
    if ($op === '-' && $a < $b) {
        $tmp = $a;
        $a = $b;
        $b = $tmp;
        $answer = $a - $b;
    }

    $_SESSION['reflection_challenges'][(int) $moduleId] = array(
        'question' => $a . ' ' . $op . ' ' . $b . ' = ?',
        'answer' => (string) $answer,
        'created_at' => time(),
    );

    return $_SESSION['reflection_challenges'][(int) $moduleId]['question'];
}

function validate_reflection_challenge($moduleId, $response)
{
    if (!isset($_SESSION['reflection_challenges'][(int) $moduleId])) {
        return false;
    }

    $challenge = $_SESSION['reflection_challenges'][(int) $moduleId];
    if (!isset($challenge['created_at']) || time() - (int) $challenge['created_at'] > 600) {
        return false;
    }

    return trim((string) $response) === (string) $challenge['answer'];
}

function slugify($value)
{
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));
    return $slug !== '' ? $slug : 'module-' . time();
}

