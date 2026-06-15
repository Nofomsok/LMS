<?php

require_once __DIR__ . '/includes.php';

$message = '';
$prefillEmail = strtolower(trim((string) (isset($_GET['email']) ? $_GET['email'] : '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) (isset($_POST['email']) ? $_POST['email'] : '')));
    $prefillEmail = $email;
    if (verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null) && filter_var($email, FILTER_VALIDATE_EMAIL) && course_users_available()) {
        $stmt = db()->prepare('SELECT id FROM course_users WHERE email = ? AND status = ? LIMIT 1');
        $stmt->execute([$email, 'approved']);
        $user = $stmt->fetch();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            db()->prepare('UPDATE course_users SET reset_token_hash = ?, reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')->execute([hash('sha256', $token), (int) $user['id']]);
            $link = current_base_url() . '/reset_password.php?token=' . rawurlencode($token);
            send_app_email($email, 'Reset your course password', "Use this link to reset your password:\n\n" . $link . "\n\nThis link expires in one hour.");
        }
    }
    $message = 'If an approved account exists for that email, a reset link has been sent.';
}
?>
<!doctype html>
<html lang="en">
  <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Forgot Password</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/styles.css"></head>
  <body class="auth-page">
    <header class="auth-topbar"><?= app_logo_markup(false) ?><nav><a href="login.php">Sign In</a></nav></header>
    <main class="auth-shell"><section class="auth-panel"><h1>Reset password</h1><?php if ($message): ?><p class="admin-success"><?= e($message) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email <input type="email" name="email" placeholder="Email" value="<?= e($prefillEmail) ?>" required></label><button class="auth-button" type="submit">Send reset link</button></form></section></main>
  </body>
</html>
