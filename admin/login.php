<?php

require_once __DIR__ . '/../includes.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) (isset($_POST['username']) ? $_POST['username'] : '');
    $password = (string) (isset($_POST['password']) ? $_POST['password'] : '');
    $challengeAnswer = isset($_POST['challenge_answer']) ? $_POST['challenge_answer'] : '';

    if (verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null) && verify_admin_login_challenge($challengeAnswer) && verify_admin_login($username, $password)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        redirect('index.php');
    }

    $error = 'Invalid login details.';
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="../assets/styles.css">
  </head>
  <body>
    <main class="admin-shell admin-login">
      <section class="admin-card">
        <h1>LMS DEMO Admin</h1>
        <p class="admin-login-note">Administrator access only.</p>
        <?php if ($error): ?><p class="admin-alert"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <label>Username <input name="username" autocomplete="username" required></label>
          <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
          <label>Solve this: <?= e(admin_login_challenge()) ?> <input type="text" name="challenge_answer" inputmode="numeric" autocomplete="off" maxlength="3" required></label>
          <button class="btn" type="submit">Login</button>
        </form>
      </section>
      <div class="admin-login-logo">
        <?= app_logo_markup(false) ?>
      </div>
    </main>
  </body>
</html>
