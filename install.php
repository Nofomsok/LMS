<?php

require_once __DIR__ . '/includes.php';

$messages = [];
$errors = [];

function install_log(&$messages, $message)
{
    $messages[] = $message;
}

function table_exists($table)
{
    $stmt = db()->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS modules (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          slug VARCHAR(120) NOT NULL UNIQUE,
          module_type VARCHAR(80) NOT NULL,
          display_number VARCHAR(20) NOT NULL,
          title VARCHAR(255) NOT NULL,
          image_url TEXT NOT NULL,
          video_url TEXT NOT NULL,
          time_label VARCHAR(80) NOT NULL,
          duration_label VARCHAR(80) NOT NULL,
          focus_json LONGTEXT NOT NULL,
          key_question TEXT NULL,
          content_text LONGTEXT NULL,
          reflection_prompt TEXT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'modules table ready');

    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->execute([DB_NAME, 'modules', 'content_text']);
    if ((int) $stmt->fetchColumn() === 0) {
        db()->exec('ALTER TABLE modules ADD content_text LONGTEXT NULL AFTER key_question');
        install_log($messages, 'lesson content field added');
    }

    db()->exec("
        CREATE TABLE IF NOT EXISTS reflections (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          module_id INT UNSIGNED NOT NULL,
          reflection_text TEXT NOT NULL,
          visitor_name VARCHAR(120) NULL,
          status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
          ip_hash CHAR(64) NOT NULL,
          user_agent VARCHAR(255) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX module_created_idx (module_id, created_at),
          INDEX status_created_idx (status, created_at),
          INDEX ip_created_idx (ip_hash, created_at),
          CONSTRAINT reflections_module_fk FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'comments table ready');

    db()->exec("
        CREATE TABLE IF NOT EXISTS resources (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(180) NOT NULL,
          file_url TEXT NOT NULL,
          file_name VARCHAR(255) NULL,
          file_type VARCHAR(40) NULL,
          sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'resources table ready');

    db()->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(80) NOT NULL UNIQUE,
          email VARCHAR(190) NULL UNIQUE,
          password_hash VARCHAR(255) NOT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'admin users table ready');

    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->execute([DB_NAME, 'admin_users', 'email']);
    if ((int) $stmt->fetchColumn() === 0) {
        db()->exec('ALTER TABLE admin_users ADD email VARCHAR(190) NULL UNIQUE AFTER username');
        install_log($messages, 'admin email field added');
    }

    $adminCount = (int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($adminCount === 0) {
        $passwordHash = ADMIN_PASSWORD_HASH !== '' ? ADMIN_PASSWORD_HASH : password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT);
        $stmt = db()->prepare('INSERT INTO admin_users (username, password_hash, is_active) VALUES (?, ?, 1)');
        $stmt->execute([ADMIN_USERNAME, $passwordHash]);
        install_log($messages, 'default admin user inserted');
    } else {
        install_log($messages, 'admin users already exist, seed skipped');
    }

    db()->exec("
        CREATE TABLE IF NOT EXISTS authorized_user_emails (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          email VARCHAR(190) NOT NULL UNIQUE,
          organization VARCHAR(190) NULL,
          notes TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'authorized user email table ready');

    db()->exec("
        CREATE TABLE IF NOT EXISTS course_users (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          full_name VARCHAR(160) NOT NULL,
          organization VARCHAR(190) NULL,
          role_title VARCHAR(160) NULL,
          email VARCHAR(190) NOT NULL UNIQUE,
          phone VARCHAR(80) NULL,
          interests TEXT NULL,
          admin_notes TEXT NULL,
          password_hash VARCHAR(255) NOT NULL,
          status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
          reset_token_hash CHAR(64) NULL,
          reset_expires_at DATETIME NULL,
          approved_at DATETIME NULL,
          last_login_at DATETIME NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX status_created_idx (status, created_at),
          INDEX reset_token_idx (reset_token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'course users table ready');

    db()->exec("
        CREATE TABLE IF NOT EXISTS video_progress (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          course_user_id INT UNSIGNED NOT NULL,
          module_id INT UNSIGNED NOT NULL,
          current_seconds INT UNSIGNED NOT NULL DEFAULT 0,
          duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
          percent_watched DECIMAL(5,2) NOT NULL DEFAULT 0.00,
          is_completed TINYINT(1) NOT NULL DEFAULT 0,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_video_progress_user_module (course_user_id, module_id),
          INDEX idx_video_progress_module (module_id),
          CONSTRAINT video_progress_user_fk FOREIGN KEY (course_user_id) REFERENCES course_users(id) ON DELETE CASCADE,
          CONSTRAINT video_progress_module_fk FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'video progress table ready');


    db()->exec("
        CREATE TABLE IF NOT EXISTS learner_notes (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          course_user_id INT UNSIGNED NOT NULL,
          module_id INT UNSIGNED NOT NULL,
          note_text LONGTEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_learner_notes_module (module_id),
          INDEX idx_learner_notes_user_module (course_user_id, module_id),
          CONSTRAINT learner_notes_user_fk FOREIGN KEY (course_user_id) REFERENCES course_users(id) ON DELETE CASCADE,
          CONSTRAINT learner_notes_module_fk FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'learner notes table ready');
    db()->exec("
        CREATE TABLE IF NOT EXISTS site_settings (
          setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
          setting_value LONGTEXT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    install_log($messages, 'site settings table ready');

    $settings = get_site_settings();
    save_site_settings($settings);
    install_log($messages, 'site settings ready');

    $moduleCount = (int) db()->query('SELECT COUNT(*) FROM modules')->fetchColumn();
    if ($moduleCount === 0) {
        $modules = [
            ['introduction', 'Introduction', 'i', 'Platform Tour', 'https://images.unsplash.com/photo-1553877522-43269d4ea984?auto=format&fit=crop&w=1200&q=80', 'videos/demo-introduction.mp4', '5 minutes', '5:00', ['What the learner sees', 'How course navigation works', 'Where progress is tracked'], 'What should a buyer understand in the first two minutes of this LMS demo?', "## Welcome to LMS DEMO\nThis introduction gives a quick tour of the learner experience. It is designed for sales conversations where you need to show a real course flow without using client-specific content.\n\n## What this demo shows\n- A branded learner portal\n- Lesson navigation\n- Video playback and resume support\n- Profile and total video progress\n- Resources, comments, private notes, and completion flow", 'What part of the learner experience would matter most to your buyer?', 10],
            ['module-1', 'Lesson 1', '1', 'Learner Onboarding', 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=80', 'videos/demo-module-1.mp4', '8 minutes', '8:00', ['Account access', 'Course dashboard', 'Learner profile', 'First-session expectations'], 'How quickly can a new learner understand what to do next?', "## Lesson 1 Introduction\nThis lesson demonstrates the onboarding flow. A learner signs in, lands on the overview page, sees their profile, and understands the course pathway.\n\n## What to point out\n- Clean login and account state\n- Profile details that help admins identify learners\n- Total video progress across the course\n- Clear next step navigation", 'Where would you customize onboarding copy for a real client?', 20],
            ['module-2', 'Lesson 2', '2', 'Course Content Experience', 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1200&q=80', 'videos/demo-module-2.mp4', '9 minutes', '9:00', ['Lesson pages', 'Video placement', 'Learning focus', 'Key questions'], 'Does each lesson make the next learner action obvious?', "## Lesson 2 Introduction\nThis lesson explains the content layout used across the LMS. Each lesson can include a video, estimated time, learning focus points, a key question, and structured written content.\n\n## Demo talking points\n- Videos can be uploaded locally or hosted externally\n- Written content supports buyers who need more than video\n- Focus lists make lessons scannable\n- Key questions create a repeatable learning pattern", 'What type of course content would be easiest to sell with this layout?', 30],
            ['module-3', 'Lesson 3', '3', 'Progress Tracking', 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80', 'videos/demo-module-3.mp4', '7 minutes', '7:00', ['Per-video progress', 'Resume playback', 'Overall watched percentage', 'Admin reporting'], 'What proof of learning progress does the buyer need?', "## Lesson 3 Introduction\nThis lesson focuses on progress tracking. The LMS stores each learner video position, watched percentage, and completion state. The learner sees progress inside each lesson and in the profile summary.\n\n## Why it matters\n- Learners can resume where they left off\n- Admins can monitor engagement\n- Sales demos can show measurable course activity\n- Progress data supports follow-up and accountability", 'Which progress metric would help close a buyer conversation?', 40],
            ['module-4', 'Lesson 4', '4', 'Admin and Content Control', 'https://images.unsplash.com/photo-1552664730-d307ca884978?auto=format&fit=crop&w=1200&q=80', 'videos/demo-module-4.mp4', '10 minutes', '10:00', ['Lesson editing', 'Learner approvals', 'Resources', 'Comment moderation'], 'How much control should the client team have without calling a developer?', "## Lesson 4 Introduction\nThis lesson prepares you to show the admin side. The same system supports lesson editing, user approvals, resource management, comment moderation, and progress reporting.\n\n## What to demonstrate\n- Edit course titles and text\n- Approve or reject learners\n- Add downloadable resources\n- Review learner comments\n- View video progress records", 'Which admin feature should be shown first in a live sales demo?', 50],
            ['module-5', 'Lesson 5', '5', 'Launch Checklist', 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1200&q=80', 'videos/demo-module-5.mp4', '6 minutes', '6:00', ['Branding', 'Videos', 'Learners', 'Resources', 'Go-live review'], 'What has to be changed before this demo becomes a client-ready LMS?', "## Lesson 5 Introduction\nThis final lesson turns the demo into a practical checklist. It shows the buyer what would be configured before launch and helps you explain what is already built.\n\n## Client-ready checklist\n- Replace LMS DEMO branding with the client brand\n- Upload final videos\n- Rewrite lesson content for the buyer topic\n- Add real resources and worksheets\n- Create learner groups or approved users\n- Test login, progress, and completion flow", 'What is the first customization you would make for a new buyer?', 60],
        ];

        $stmt = db()->prepare('INSERT INTO modules (slug, module_type, display_number, title, image_url, video_url, time_label, duration_label, focus_json, key_question, content_text, reflection_prompt, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
        foreach ($modules as $module) {
            $stmt->execute([
                $module[0],
                $module[1],
                $module[2],
                $module[3],
                $module[4],
                $module[5],
                $module[6],
                $module[7],
                json_encode($module[8]),
                $module[9],
                $module[10],
                $module[11],
                $module[12],
            ]);
        }
        install_log($messages, 'default lessons inserted');
    } else {
        install_log($messages, 'lessons already exist, seed skipped');
    }

    $resourceCount = (int) db()->query('SELECT COUNT(*) FROM resources')->fetchColumn();
    if ($resourceCount === 0) {
        $stmt = db()->prepare('INSERT INTO resources (title, file_url, file_name, file_type, sort_order) VALUES (?, ?, ?, ?, ?)');
        foreach ([['Demo Setup Checklist', '#', 'demo-setup-checklist.pdf', 'pdf', 10], ['Buyer Walkthrough Script', '#', 'buyer-walkthrough-script.pdf', 'pdf', 20], ['Admin Feature Summary', '#', 'admin-feature-summary.pdf', 'pdf', 30], ['Launch Readiness Worksheet', '#', 'launch-readiness-worksheet.pdf', 'pdf', 40]] as $resource) {
            $stmt->execute($resource);
        }
        install_log($messages, 'default resources inserted');
    } else {
        install_log($messages, 'resources already exist, seed skipped');
    }
} catch (Exception $exception) {
    $errors[] = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install Training Site</title>
    <link rel="stylesheet" href="assets/styles.css">
  </head>
  <body>
    <main class="admin-shell">
      <section class="admin-card">
        <h1>Install Training Site</h1>
        <?php foreach ($messages as $message): ?><p class="admin-success"><?= e($message) ?></p><?php endforeach; ?>
        <?php foreach ($errors as $error): ?><p class="admin-alert"><?= e($error) ?></p><?php endforeach; ?>
        <?php if (!$errors): ?>
          <p>Install completed. Delete <strong>install.php</strong> from the server after confirming the site works.</p>
          <p><a class="btn" href="index.php">Open Site</a> <a class="btn secondary" href="admin/login.php">Open Admin</a></p>
        <?php endif; ?>
      </section>
    </main>
  </body>
</html>



