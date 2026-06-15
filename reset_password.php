<?php

require_once __DIR__ . '/includes.php';

$token = isset($_GET['token']) ? (string) $_GET['token'] : (isset($_POST['token']) ? (string) $_POST['token'] : '');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) (isset($_POST['password']) ? $_POST['password'] : '');
    $confirm = (string) (isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '');

    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null) || $token === '') {
        $error = 'Security check failed.';
    } elseif (strlen($password) < 8 || $password !== $confirm) {
        $error = 'Passwords must match and be at least 8 characters.';
    } else {
        $stmt = db()->prepare('SELECT id FROM course_users WHERE reset_token_hash = ? AND reset_expires_at > NOW() LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();
        if ($user) {
            db()->prepare('UPDATE course_users SET password_hash = ?, reset_token_hash = NULL, reset_expires_at = NULL WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
            $message = 'Password updated. You can sign in now.';
        } else {
            $error = 'This reset link is invalid or expired.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
  <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset Password</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/styles.css"></head>
  <body class="auth-page">
    <header class="auth-topbar"><?= app_logo_markup(false) ?><nav><a href="login.php">Sign In</a></nav></header>
    <main class="auth-shell"><section class="auth-panel"><h1>New password</h1><?php if ($message): ?><p class="admin-success"><?= e($message) ?></p><?php endif; ?><?php if ($error): ?><p class="admin-alert"><?= e($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><label>Password <input type="password" name="password" required minlength="8"></label><label>Confirm Password <input type="password" name="password_confirm" required minlength="8"></label><button class="auth-button" type="submit">Update password</button></form></section></main>
  </body>
</html>
