<?php

require_once __DIR__ . '/includes.php';

if (is_course_user() && current_course_user()) {
    redirect('index.php');
}

$error = '';
$next = isset($_GET['next']) ? (string) $_GET['next'] : 'index.php';

function course_login_next($next)
{
    $next = trim((string) $next);
    if ($next === '' || stripos($next, 'login.php') !== false || preg_match('~^https?://~i', $next) || strpos($next, '//') === 0) {
        return 'index.php';
    }

    return $next;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string) (isset($_POST['email']) ? $_POST['email'] : ''));
    $password = (string) (isset($_POST['password']) ? $_POST['password'] : '');
    $next = isset($_POST['next']) ? (string) $_POST['next'] : $next;

    if (verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null) && verify_course_login($login, $password)) {
        session_write_close();
        redirect(course_login_next($next));
    }

    if (verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null) && verify_admin_login($login, $password)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['course_user_id'] = -1;
        $_SESSION['course_user_email'] = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : $login;
        session_write_close();
        redirect(course_login_next($next));
    }

    $error = 'Invalid login details, or your account is still waiting for approval.';
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/styles.css">
  </head>
  <body class="auth-page">
    <header class="auth-topbar">
      <?= app_logo_markup(false) ?>
      <nav><a href="register.php">Request Access</a><a href="admin/login.php">Admin Sign In</a></nav>
    </header>
    <main class="auth-shell">
      <section class="auth-panel">
        <h1>Welcome!</h1>
        <?php if ($error): ?><p class="admin-alert"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="next" value="<?= e($next) ?>">
          <label>Email, Registered Name, or Admin Username <input name="email" placeholder="Email, registered name, or admin username" autocomplete="username" required></label>
          <label>Password <input type="password" name="password" placeholder="Password" autocomplete="current-password" required></label>
          <div class="auth-row">
            <label class="auth-check"><input type="checkbox" name="remember" checked> Remember me</label>
            <a href="forgot_password.php">Forgot password?</a>
          </div>
          <button class="auth-button" type="submit">Sign in</button>
          <p class="auth-switch">New user? <a href="register.php">Request access</a></p>
          <p class="auth-admin-link">Administrator? <a href="admin/login.php">Use admin login</a></p>
        </form>
      </section>
    </main>
  </body>
</html>
