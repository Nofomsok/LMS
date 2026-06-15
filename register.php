<?php

require_once __DIR__ . '/includes.php';

$message = '';
$error = '';
$registered = false;
$registeredApproved = false;
$registeredEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) (isset($_POST['full_name']) ? $_POST['full_name'] : ''));
    $organization = trim((string) (isset($_POST['organization']) ? $_POST['organization'] : ''));
    $roleTitle = trim((string) (isset($_POST['role_title']) ? $_POST['role_title'] : ''));
    $email = strtolower(trim((string) (isset($_POST['email']) ? $_POST['email'] : '')));
    $phone = trim((string) (isset($_POST['phone']) ? $_POST['phone'] : ''));
    $interests = isset($_POST['interests']) && is_array($_POST['interests']) ? implode(', ', array_map('trim', $_POST['interests'])) : '';
    $challengeAnswer = isset($_POST['challenge_answer']) ? $_POST['challenge_answer'] : '';

    if (!course_users_available()) {
        $error = 'Registration is not ready yet. Please contact the site administrator.';
    } elseif (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null) || !verify_math_challenge('registration_challenge', $challengeAnswer)) {
        $error = 'Security check failed. Please try again.';
    } elseif ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Name and a valid email address are required.';
    } else {
        $password = random_password();
        $approved = email_is_authorized($email);
        $status = $approved ? 'approved' : 'pending';

        try {
            $stmt = db()->prepare('INSERT INTO course_users (full_name, organization, role_title, email, phone, interests, password_hash, status, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . ($approved ? 'NOW()' : 'NULL') . ')');
            $stmt->execute([$fullName, $organization ?: null, $roleTitle ?: null, $email, $phone ?: null, $interests ?: null, password_hash($password, PASSWORD_DEFAULT), $status]);
            send_course_registration_email($email, $fullName, $approved);
            $registered = true;
            $registeredApproved = $approved;
            $registeredEmail = $email;

            if ($approved) {
                send_course_password_email($email, $password);
                $message = 'Your account is approved. Login details were sent to your email.';
            } else {
                $message = 'Your registration was received and is waiting for admin approval.';
            }
        } catch (Exception $exception) {
            $error = 'This email may already be registered.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request Access | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/styles.css">
  </head>
  <body class="auth-page">
    <header class="auth-topbar">
      <?= app_logo_markup(false) ?>
      <nav><a href="index.php">All Products</a><a href="login.php">Sign In</a></nav>
    </header>
    <main class="auth-shell register-shell">
      <section class="register-panel">
        <?php if ($registered): ?>
          <div class="registration-confirmation">
            <h1>Registration received</h1>
            <p class="confirmation-lead"><?= e($message) ?></p>
            <p>We sent a confirmation email to <strong><?= e($registeredEmail) ?></strong>.</p>
            <?php if ($registeredApproved): ?>
              <p>Your email is on the authorized list, so your account was approved automatically. Please check your email for login details.</p>
              <a class="auth-button" href="login.php">Sign in</a>
            <?php else: ?>
              <p>Your account is waiting for administrator approval. You will receive login details after approval.</p>
              <a class="auth-button" href="index.php">Back to site</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <h1>Request Access</h1>
          <?php if ($error): ?><p class="admin-alert"><?= e($error) ?></p><?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="register-grid">
              <input name="full_name" placeholder="Name" required>
              <input name="organization" placeholder="Organization (optional)">
              <input name="role_title" placeholder="Role / Title (optional)">
              <input type="email" name="email" placeholder="Email Address" required>
              <input name="phone" placeholder="Phone (optional)">
            </div>
            <fieldset class="interest-field">
              <legend>Area of Interest</legend>
              <label><input type="checkbox" name="interests[]" value="Training programs"> Training programs</label>
              <label><input type="checkbox" name="interests[]" value="Organizational implementation"> Organizational implementation</label>
              <label><input type="checkbox" name="interests[]" value="Research or collaboration"> Research or collaboration</label>
              <label><input type="checkbox" name="interests[]" value="General inquiry"> General inquiry</label>
            </fieldset>
            <label class="auth-challenge">Solve this: <?= e(math_challenge('registration_challenge')) ?> <input type="text" name="challenge_answer" inputmode="numeric" autocomplete="off" maxlength="3" required></label>
            <button class="auth-button" type="submit">Submit request</button>
          </form>
        <?php endif; ?>
      </section>
    </main>
  </body>
</html>
