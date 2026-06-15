<?php

require_once __DIR__ . '/../includes.php';
require_admin();

$action = isset($_POST['action']) ? $_POST['action'] : '';
$message = '';
$error = '';

function upload_resource_file($field)
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || (int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return array(null, null, null);
    }

    $originalName = basename((string) $_FILES[$field]['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, array('pdf', 'doc', 'docx'), true)) {
        throw new Exception('Only PDF, DOC, and DOCX files are allowed.');
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'resources';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new Exception('Unable to create upload directory.');
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
    $storedName = $safeName . '-' . time() . '.' . $extension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        throw new Exception('Upload failed.');
    }

    return array('uploads/resources/' . $storedName, $originalName, $extension);
}

function upload_module_image_file($field)
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || (int) $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Image upload failed. Please try again.');
    }

    $originalName = basename((string) $_FILES[$field]['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true)) {
        throw new Exception('Only JPG, PNG, WEBP, and GIF images are allowed.');
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'module-images';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new Exception('Unable to create image upload directory.');
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
    $safeName = trim($safeName, '-');
    if ($safeName === '') {
        $safeName = 'lesson-image';
    }
    $storedName = $safeName . '-' . time() . '.' . $extension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        throw new Exception('Image upload failed.');
    }

    return 'uploads/module-images/' . $storedName;
}
function unique_module_slug($slug, $ignoreId = 0)
{
    $base = $slug !== '' ? $slug : 'module';
    $candidate = $base;
    $suffix = 2;

    while (true) {
        $stmt = db()->prepare('SELECT id FROM modules WHERE slug = ? AND id <> ? LIMIT 1');
        $stmt->execute([$candidate, (int) $ignoreId]);
        if (!$stmt->fetch()) {
            return $candidate;
        }

        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'Security check failed. Please try again.';
    } elseif ($action === 'create_admin') {
        if (!is_master_admin()) {
            $error = 'Only the master admin can create admins.';
        } elseif (!admin_users_available()) {
            $error = 'Admin user table is not installed yet. Run install.php once.';
        } else {
            $username = trim((string) (isset($_POST['admin_username']) ? $_POST['admin_username'] : ''));
            $email = strtolower(trim((string) (isset($_POST['admin_email']) ? $_POST['admin_email'] : '')));
            $password = (string) (isset($_POST['admin_password']) ? $_POST['admin_password'] : '');
            $confirm = (string) (isset($_POST['admin_password_confirm']) ? $_POST['admin_password_confirm'] : '');

            if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)) {
                $error = 'Admin username must be 3-80 characters and use letters, numbers, dots, hyphens, or underscores.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid administrator email.';
            } elseif (strlen($password) < 8) {
                $error = 'Admin password must be at least 8 characters.';
            } elseif ($password !== $confirm) {
                $error = 'Admin passwords do not match.';
            } else {
                try {
                    $stmt = db()->prepare('INSERT INTO admin_users (username, email, password_hash, is_active) VALUES (?, ?, ?, 1)');
                    $stmt->execute([$username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT)]);
                    $message = 'Admin user added.';
                } catch (Exception $exception) {
                    $error = 'Could not add admin user. The username or email may already exist.';
                }
            }
        }
    } elseif ($action === 'delete_admin') {
        if (!is_master_admin()) {
            $error = 'Only the master admin can delete admins.';
        } elseif (!admin_users_available()) {
            $error = 'Admin user table is not installed yet.';
        } else {
            $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
            $currentId = (int) (isset($_SESSION['admin_user_id']) ? $_SESSION['admin_user_id'] : 0);

            if ($id <= 0) {
                $error = 'Invalid admin user.';
            } elseif ($id === $currentId && isset($_SESSION['admin_source']) && $_SESSION['admin_source'] === 'database') {
                $error = 'You cannot delete the admin account you are currently using.';
            } else {
                $activeCount = (int) db()->query('SELECT COUNT(*) FROM admin_users WHERE is_active = 1')->fetchColumn();
                if ($activeCount <= 1) {
                    $error = 'Keep at least one database admin user active.';
                } else {
                    $stmt = db()->prepare('DELETE FROM admin_users WHERE id = ?');
                    $stmt->execute([$id]);
                    $message = 'Admin user deleted.';
                }
            }
        }
    } elseif ($action === 'create_course_user') {
        if (!course_users_available()) {
            $error = 'Course user tables are not installed yet. Run install.php once.';
        } else {
            $fullName = trim((string) (isset($_POST['user_full_name']) ? $_POST['user_full_name'] : ''));
            $email = strtolower(trim((string) (isset($_POST['user_email']) ? $_POST['user_email'] : '')));
            $organization = trim((string) (isset($_POST['user_organization']) ? $_POST['user_organization'] : ''));
            $roleTitle = trim((string) (isset($_POST['user_role_title']) ? $_POST['user_role_title'] : ''));
            $phone = trim((string) (isset($_POST['user_phone']) ? $_POST['user_phone'] : ''));
            $adminNotes = trim((string) (isset($_POST['user_admin_notes']) ? $_POST['user_admin_notes'] : ''));
            $password = (string) (isset($_POST['user_password']) ? $_POST['user_password'] : '');
            if ($password === '') {
                $password = random_password();
            }

            if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'User name and valid email are required.';
            } elseif (strlen($password) < 8) {
                $error = 'User password must be at least 8 characters.';
            } else {
                try {
                    $stmt = db()->prepare('INSERT INTO course_users (full_name, organization, role_title, email, phone, admin_notes, password_hash, status, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                    $stmt->execute([$fullName, $organization ?: null, $roleTitle ?: null, $email, $phone ?: null, $adminNotes ?: null, password_hash($password, PASSWORD_DEFAULT), 'approved']);
                    $sent = send_course_password_email($email, $password);
                    $message = $sent ? 'Course user added and login details were sent by email.' : 'Course user added, but email could not be sent. Temporary password: ' . $password;
                } catch (Exception $exception) {
                    $error = 'Could not add user. The email may already exist.';
                }
            }
        }
    } elseif ($action === 'approve_course_user') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        $password = random_password();
        $stmt = db()->prepare('SELECT email FROM course_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if ($user) {
            db()->prepare('UPDATE course_users SET status = ?, password_hash = ?, approved_at = NOW() WHERE id = ?')->execute(['approved', password_hash($password, PASSWORD_DEFAULT), $id]);
            $sent = send_course_password_email($user['email'], $password);
            $message = $sent ? 'User approved and login details were sent by email.' : 'User approved, but email could not be sent. Temporary password: ' . $password;
        }
    } elseif ($action === 'reject_course_user') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        db()->prepare('UPDATE course_users SET status = ? WHERE id = ?')->execute(['rejected', $id]);
        $message = 'User rejected.';
    } elseif ($action === 'delete_course_user') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        db()->prepare('DELETE FROM course_users WHERE id = ?')->execute([$id]);
        $message = 'User deleted.';
    } elseif ($action === 'add_authorized_email') {
        $email = strtolower(trim((string) (isset($_POST['authorized_email']) ? $_POST['authorized_email'] : '')));
        $organization = trim((string) (isset($_POST['authorized_organization']) ? $_POST['authorized_organization'] : ''));
        $notes = trim((string) (isset($_POST['authorized_notes']) ? $_POST['authorized_notes'] : ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Valid authorized email is required.';
        } else {
            try {
                db()->prepare('INSERT INTO authorized_user_emails (email, organization, notes) VALUES (?, ?, ?)')->execute([$email, $organization ?: null, $notes ?: null]);
                $message = 'Authorized course user email added.';
            } catch (Exception $exception) {
                $error = 'Authorized course user email may already exist.';
            }
        }
    } elseif ($action === 'delete_authorized_email') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        db()->prepare('DELETE FROM authorized_user_emails WHERE id = ?')->execute([$id]);
        $message = 'Authorized course user email removed.';
    } elseif ($action === 'save_site_content') {
        $settingsPayload = array(
            'landing_title' => trim((string) (isset($_POST['landing_title']) ? $_POST['landing_title'] : '')),
            'landing_intro' => trim((string) (isset($_POST['landing_intro']) ? $_POST['landing_intro'] : '')),
            'landing_button' => trim((string) (isset($_POST['landing_button']) ? $_POST['landing_button'] : '')),
            'welcome_title' => trim((string) (isset($_POST['welcome_title']) ? $_POST['welcome_title'] : '')),
            'welcome_intro' => trim((string) (isset($_POST['welcome_intro']) ? $_POST['welcome_intro'] : '')),
            'pathway_title' => trim((string) (isset($_POST['pathway_title']) ? $_POST['pathway_title'] : '')),
            'training_tips_title' => trim((string) (isset($_POST['training_tips_title']) ? $_POST['training_tips_title'] : '')),
            'training_tips' => trim((string) (isset($_POST['training_tips']) ? $_POST['training_tips'] : '')),
            'training_widget_2_title' => trim((string) (isset($_POST['training_widget_2_title']) ? $_POST['training_widget_2_title'] : '')),
            'training_widget_2_text' => trim((string) (isset($_POST['training_widget_2_text']) ? $_POST['training_widget_2_text'] : '')),
            'training_widget_3_title' => trim((string) (isset($_POST['training_widget_3_title']) ? $_POST['training_widget_3_title'] : '')),
            'training_widget_3_text' => trim((string) (isset($_POST['training_widget_3_text']) ? $_POST['training_widget_3_text'] : '')),
        );

        if ($settingsPayload['landing_title'] === '' || $settingsPayload['welcome_title'] === '') {
            $error = 'Landing title and welcome title are required.';
        } elseif (save_site_settings($settingsPayload)) {
            $message = 'Landing and welcome page content updated.';
        } else {
            $error = 'Site settings table is not installed yet. Run install.php once.';
        }
    } elseif ($action === 'save_module') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        $title = trim((string) (isset($_POST['title']) ? $_POST['title'] : ''));
        $slugSource = isset($_POST['slug']) && $_POST['slug'] !== '' ? $_POST['slug'] : $title;
        $slug = slugify((string) $slugSource);
        $slug = unique_module_slug($slug, $id);
        $moduleType = trim((string) (isset($_POST['module_type']) ? $_POST['module_type'] : 'Lesson'));
        $displayNumber = trim((string) (isset($_POST['display_number']) ? $_POST['display_number'] : ''));
        $imageUrl = trim((string) (isset($_POST['image_url']) ? $_POST['image_url'] : ''));
        $videoUrl = trim((string) (isset($_POST['video_url']) ? $_POST['video_url'] : ''));
        $timeLabel = trim((string) (isset($_POST['time_label']) ? $_POST['time_label'] : ''));
        $durationLabel = trim((string) (isset($_POST['duration_label']) ? $_POST['duration_label'] : ''));
        $keyQuestion = trim((string) (isset($_POST['key_question']) ? $_POST['key_question'] : ''));
        $contentText = trim((string) (isset($_POST['content_text']) ? $_POST['content_text'] : ''));
        $reflectionPrompt = trim((string) (isset($_POST['reflection_prompt']) ? $_POST['reflection_prompt'] : ''));
        $sortOrder = (int) (isset($_POST['sort_order']) ? $_POST['sort_order'] : next_sort_order());
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $focusLines = preg_split('/\r\n|\r|\n/', (string) (isset($_POST['focus']) ? $_POST['focus'] : ''), -1, PREG_SPLIT_NO_EMPTY);
        $focus = array_values(array_map('trim', $focusLines ? $focusLines : array()));

        try {
            $uploadedImageUrl = upload_module_image_file('image_file');
            if ($uploadedImageUrl !== null) {
                $imageUrl = $uploadedImageUrl;
            }
        } catch (Exception $exception) {
            $error = $exception->getMessage();
        }

        if ($error === '' && ($title === '' || ($videoUrl === '' && $imageUrl === ''))) {
            $error = 'Title and either a video URL or image URL are required.';
        } elseif ($error === '') {
            $payload = array(
                $slug,
                $moduleType,
                $displayNumber,
                $title,
                $imageUrl,
                $videoUrl,
                $timeLabel,
                $durationLabel,
                json_encode($focus),
                $keyQuestion !== '' ? $keyQuestion : null,
                $contentText !== '' ? $contentText : null,
                $reflectionPrompt !== '' ? $reflectionPrompt : null,
                $sortOrder,
                $isActive,
            );

            if ($id > 0) {
                $payload[] = $id;
                $stmt = db()->prepare('UPDATE modules SET slug=?, module_type=?, display_number=?, title=?, image_url=?, video_url=?, time_label=?, duration_label=?, focus_json=?, key_question=?, content_text=?, reflection_prompt=?, sort_order=?, is_active=? WHERE id=?');
                try {
                    $stmt->execute($payload);
                    $message = 'Lesson updated.';
                } catch (Exception $exception) {
                    $error = 'Could not update lesson. Please check the lesson details and try again.';
                }
            } else {
                $stmt = db()->prepare('INSERT INTO modules (slug, module_type, display_number, title, image_url, video_url, time_label, duration_label, focus_json, key_question, content_text, reflection_prompt, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                try {
                    $stmt->execute($payload);
                    $message = 'Lesson added.';
                } catch (Exception $exception) {
                    $error = 'Could not add lesson. Please check the lesson details and try again.';
                }
            }
        }
    } elseif ($action === 'delete_module') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM modules WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'Lesson deleted.';
        }
    } elseif ($action === 'delete_reflection') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM reflections WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'Comment deleted.';
        }
    } elseif ($action === 'approve_reflection') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id > 0 && reflection_status_available()) {
            $stmt = db()->prepare('UPDATE reflections SET status = ? WHERE id = ?');
            $stmt->execute(['approved', $id]);
            $message = 'Comment approved.';
        }
    } elseif ($action === 'reject_reflection') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id > 0 && reflection_status_available()) {
            $stmt = db()->prepare('UPDATE reflections SET status = ? WHERE id = ?');
            $stmt->execute(['rejected', $id]);
            $message = 'Comment rejected.';
        }
    } elseif ($action === 'save_reflection') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        $moduleId = (int) (isset($_POST['reflection_module_id']) ? $_POST['reflection_module_id'] : 0);
        $reflectionText = trim((string) (isset($_POST['reflection_text']) ? $_POST['reflection_text'] : ''));

        if ($id <= 0 || $moduleId <= 0 || $reflectionText === '') {
            $error = 'Comment lesson and text are required.';
        } else {
            $stmt = db()->prepare('SELECT id FROM modules WHERE id = ? LIMIT 1');
            $stmt->execute([$moduleId]);
            if (!$stmt->fetch()) {
                $error = 'Selected lesson does not exist.';
            } else {
                $stmt = db()->prepare('UPDATE reflections SET module_id = ?, reflection_text = ? WHERE id = ?');
                $stmt->execute([$moduleId, $reflectionText, $id]);
                $message = 'Comment updated.';
            }
        }
    } elseif ($action === 'save_resource') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        $title = trim((string) (isset($_POST['resource_title']) ? $_POST['resource_title'] : ''));
        $sortOrder = (int) (isset($_POST['resource_sort_order']) ? $_POST['resource_sort_order'] : 0);
        $existingUrl = isset($_POST['existing_file_url']) ? trim((string) $_POST['existing_file_url']) : '';
        $existingName = isset($_POST['existing_file_name']) ? trim((string) $_POST['existing_file_name']) : '';
        $existingType = isset($_POST['existing_file_type']) ? trim((string) $_POST['existing_file_type']) : '';

        if ($title === '') {
            $error = 'Resource title is required.';
        } else {
            $uploaded = array(null, null, null);
            if (isset($_FILES['resource_file']) && (int) $_FILES['resource_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploaded = upload_resource_file('resource_file');
            }

            $fileUrl = $uploaded[0] ? $uploaded[0] : $existingUrl;
            $fileName = $uploaded[1] ? $uploaded[1] : $existingName;
            $fileType = $uploaded[2] ? $uploaded[2] : $existingType;
            $hasMetaColumns = resource_meta_columns_present();

            if ($fileUrl === '') {
                $error = 'Please upload a PDF or DOCX file.';
            } else {
                if ($hasMetaColumns) {
                    $payload = array($title, $fileUrl, $fileName, $fileType, $sortOrder);
                    if ($id > 0) {
                        $payload[] = $id;
                        $stmt = db()->prepare('UPDATE resources SET title=?, file_url=?, file_name=?, file_type=?, sort_order=? WHERE id=?');
                        $stmt->execute($payload);
                        $message = 'Resource updated.';
                    } else {
                        $stmt = db()->prepare('INSERT INTO resources (title, file_url, file_name, file_type, sort_order) VALUES (?, ?, ?, ?, ?)');
                        $stmt->execute($payload);
                        $message = 'Resource added.';
                    }
                } else {
                    $payload = array($title, $fileUrl, $sortOrder);
                    if ($id > 0) {
                        $payload[] = $id;
                        $stmt = db()->prepare('UPDATE resources SET title=?, file_url=?, sort_order=? WHERE id=?');
                        $stmt->execute($payload);
                        $message = 'Resource updated.';
                    } else {
                        $stmt = db()->prepare('INSERT INTO resources (title, file_url, sort_order) VALUES (?, ?, ?)');
                        $stmt->execute($payload);
                        $message = 'Resource added.';
                    }
                }
            }
        }
    } elseif ($action === 'delete_resource') {
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM resources WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'Resource deleted.';
        }
    }
}

$modules = get_modules(true);
$reflectionModules = $modules;
$editingId = (int) (isset($_GET['edit']) ? $_GET['edit'] : 0);
$editing = null;
foreach ($modules as $module) {
    if ((int) $module['id'] === $editingId) {
        $editing = $module;
        break;
    }
}

$reflectionHasStatus = reflection_status_available();
$reflectionStatusSql = $reflectionHasStatus ? 'reflections.status AS reflection_status,' : '"approved" AS reflection_status,';
$reflectionOrderSql = $reflectionHasStatus ? 'FIELD(reflections.status, "pending", "approved", "rejected"), ' : '';
$reflections = db()->query('SELECT reflections.*, ' . $reflectionStatusSql . ' modules.title AS module_title, modules.module_type AS module_type, modules.display_number AS display_number, modules.slug AS module_slug FROM reflections JOIN modules ON modules.id = reflections.module_id ORDER BY ' . $reflectionOrderSql . 'modules.sort_order ASC, reflections.created_at DESC LIMIT 80')->fetchAll();
$reflectionsByModule = array();
foreach ($reflections as $reflection) {
    $moduleId = (int) $reflection['module_id'];
    if (!isset($reflectionsByModule[$moduleId])) {
        $displayNumber = trim((string) (isset($reflection['display_number']) ? $reflection['display_number'] : ''));
        $moduleType = trim((string) (isset($reflection['module_type']) ? $reflection['module_type'] : 'Module'));
        $moduleLabel = $moduleType;
        if ($displayNumber !== '' && !preg_match('/\b' . preg_quote($displayNumber, '/') . '$/', $moduleType)) {
            $moduleLabel .= ' ' . $displayNumber;
        }

        $reflectionsByModule[$moduleId] = array(
            'label' => $moduleLabel,
            'title' => $reflection['module_title'],
            'slug' => $reflection['module_slug'],
            'items' => array(),
        );
    }

    $reflectionsByModule[$moduleId]['items'][] = $reflection;
}
$reflectionEditingId = (int) (isset($_GET['reflection_edit']) ? $_GET['reflection_edit'] : 0);
$reflectionEditing = null;
if ($reflectionEditingId > 0) {
    $reflectionStatusSql = reflection_status_available() ? 'reflections.status AS reflection_status,' : '"approved" AS reflection_status,';
    $stmt = db()->prepare('SELECT reflections.*, ' . $reflectionStatusSql . ' modules.title AS module_title, modules.module_type AS module_type, modules.display_number AS display_number, modules.slug AS module_slug FROM reflections JOIN modules ON modules.id = reflections.module_id WHERE reflections.id = ? LIMIT 1');
    $stmt->execute([$reflectionEditingId]);
    $reflectionEditing = $stmt->fetch();
}
$resources = get_training_resources();
$admins = get_admin_users();
$courseUsers = get_course_users();
$authorizedEmails = get_authorized_user_emails();
$videoProgressRows = get_admin_video_progress_rows();
$siteSettings = get_site_settings();

$form = $editing ? $editing : array(
    'id' => 0,
    'slug' => '',
    'module_type' => 'Lesson',
    'display_number' => '',
    'title' => '',
    'image_url' => '',
    'video_url' => 'https://youtu.be/ACoOxcK8y6I?si=4vLtdb34ndr2evOV',
    'time_label' => '',
    'duration_label' => '',
    'focus' => array(),
    'key_question' => '',
    'content_text' => '',
    'reflection_prompt' => '',
    'sort_order' => next_sort_order(),
    'is_active' => 1,
);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LMS DEMO Admin</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles.css?v=20260514-layout-profile">
  </head>
  <body>
    <main class="admin-shell">
      <header class="admin-header">
        <div>
          <h1>LMS DEMO Admin</h1>
          <p>Manage lessons, videos, and submitted comments.</p>
        </div>
        <nav>
          <a class="btn secondary" href="../index.php">View Site</a>
          <a class="btn secondary" href="logout.php">Logout</a>
        </nav>
      </header>

      <?php if ($message): ?><p class="admin-success"><?= e($message) ?></p><?php endif; ?>
      <?php if ($error): ?><p class="admin-alert"><?= e($error) ?></p><?php endif; ?>

      <section class="admin-grid">
        <form class="admin-card admin-form admin-accent-module-form" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_module">
          <input type="hidden" name="id" value="<?= e((string) $form['id']) ?>">
          <h2>Edit Lesson</h2>
          <?php if (!$editing): ?><p class="admin-muted">Choose a lesson from the list to edit, or use Add Lesson below the list.</p><?php endif; ?>
          <label>Title <input name="title" value="<?= e($form['title']) ?>" required></label>
          <label>Slug <input name="slug" value="<?= e($form['slug']) ?>" placeholder="lesson-6"></label>
          <div class="admin-two">
            <label>Lesson Label <input name="module_type" value="<?= e($form['module_type']) ?>" required></label>
            <label>Display Number <input name="display_number" value="<?= e($form['display_number']) ?>"></label>
          </div>
          <div class="admin-media-picker"><label>Upload Lesson Image <input type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.gif,image/*"></label><label>Image URL <input name="image_url" value="<?= e($form['image_url']) ?>" placeholder="Paste an image URL or upload an image"></label><?php if (!empty($form['image_url'])): ?><div class="admin-image-preview"><img src="<?= e($form['image_url']) ?>" alt="Current lesson image"></div><?php endif; ?></div>
          <label>Video URL <input name="video_url" value="<?= e($form['video_url']) ?>" placeholder="When filled, this shows instead of the image"></label>
          <div class="admin-two">
            <label>Estimated Time <input name="time_label" value="<?= e($form['time_label']) ?>" placeholder="12 minutes"></label>
            <label>Video Duration <input name="duration_label" value="<?= e($form['duration_label']) ?>" placeholder="12:30"></label>
          </div>
          <label>Learning Focus <textarea name="focus" rows="5" placeholder="One bullet per line"><?= e(implode("\n", $form['focus'])) ?></textarea></label>
          <label>Key Question <textarea name="key_question" rows="3"><?= e((string) $form['key_question']) ?></textarea></label>
          <label class="admin-editor">Lesson Text
            <span class="editor-toolbar" aria-label="Lesson text editor tools">
              <button type="button" data-editor-mode="visual">Visual</button>
              <button type="button" data-editor-mode="html">HTML</button>
              <button type="button" data-editor-mode="preview">Preview</button>
              <button type="button" data-editor-action="heading">Heading</button>
              <button type="button" data-editor-action="paragraph">Paragraph</button>
              <button type="button" data-editor-action="list">List</button>
              <button type="button" data-editor-action="bold">Bold</button>
              <button type="button" data-editor-action="italic">Italic</button>
              <button type="button" data-editor-action="image">Image</button>
            </span>
            <textarea name="content_text" rows="10" data-content-editor placeholder="Add lesson text, simple HTML, lists, or images shown between the video and Comments"><?= e((string) (isset($form['content_text']) ? $form['content_text'] : '')) ?></textarea>
            <div class="editor-preview" data-editor-preview hidden></div>
          </label>
          <label>Comment Prompt <textarea name="reflection_prompt" rows="3"><?= e((string) $form['reflection_prompt']) ?></textarea></label>
          <div class="admin-two">
            <label>Sort Order <input type="number" name="sort_order" value="<?= e((string) $form['sort_order']) ?>"></label>
            <label class="admin-check"><input type="checkbox" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>> Active</label>
          </div>
          <?php if ($editing): ?>
            <button class="btn" type="submit">Save Changes</button>
            <a class="btn secondary" href="index.php">Cancel Edit</a>
          <?php else: ?>
            <button class="btn" type="submit" disabled>Choose Edit From List</button>
          <?php endif; ?>
        </form>

        <div class="admin-stack">
          <section class="admin-card admin-accent-modules">
            <h2>Lessons</h2>
            <div class="admin-list">
              <?php foreach ($modules as $module): ?>
                <article class="admin-row">
                  <div>
                    <strong><?= e($module['module_type']) ?>: <?= e($module['title']) ?></strong>
                    <span><?= e($module['slug']) ?> | <?= e($module['is_active'] ? 'Active' : 'Hidden') ?></span>
                  </div>
                  <div class="admin-actions">
                    <a class="btn secondary" href="index.php?edit=<?= e((string) $module['id']) ?>">Edit</a>
                    <form method="post" onsubmit="return confirm('Delete this lesson and its comments?');">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete_module">
                      <input type="hidden" name="id" value="<?= e((string) $module['id']) ?>">
                      <button class="btn secondary danger" type="submit">Delete</button>
                    </form>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>

          <details class="admin-card admin-accent-module-form admin-accordion">
            <summary>
              <span>
                <strong>Add Lesson</strong>
                <small>Click to add a new course lesson.</small>
              </span>
            </summary>
            <form class="admin-form admin-accordion-body" method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="save_module">
              <input type="hidden" name="id" value="0">
              <label>Title <input name="title" value="" required></label>
              <label>Slug <input name="slug" value="" placeholder="lesson-6"></label>
              <div class="admin-two">
                <label>Lesson Label <input name="module_type" value="Lesson" required></label>
                <label>Display Number <input name="display_number" value=""></label>
              </div>
              <div class="admin-media-picker"><label>Upload Lesson Image <input type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.gif,image/*"></label><label>Image URL <input name="image_url" value="" placeholder="Paste an image URL or upload an image"></label></div>
              <label>Video URL <input name="video_url" value="" placeholder="When filled, this shows instead of the image"></label>
              <div class="admin-two">
                <label>Estimated Time <input name="time_label" value="" placeholder="12 minutes"></label>
                <label>Video Duration <input name="duration_label" value="" placeholder="12:30"></label>
              </div>
              <label>Learning Focus <textarea name="focus" rows="5" placeholder="One bullet per line"></textarea></label>
              <label>Key Question <textarea name="key_question" rows="3"></textarea></label>
              <label class="admin-editor">Lesson Text
                <span class="editor-toolbar" aria-label="Lesson text editor tools">
                  <button type="button" data-editor-mode="visual">Visual</button>
                  <button type="button" data-editor-mode="html">HTML</button>
                  <button type="button" data-editor-mode="preview">Preview</button>
                  <button type="button" data-editor-action="heading">Heading</button>
                  <button type="button" data-editor-action="paragraph">Paragraph</button>
                  <button type="button" data-editor-action="list">List</button>
                  <button type="button" data-editor-action="bold">Bold</button>
                  <button type="button" data-editor-action="italic">Italic</button>
                  <button type="button" data-editor-action="image">Image</button>
                </span>
                <textarea name="content_text" rows="10" data-content-editor placeholder="Add lesson text, simple HTML, lists, or images shown between the video and Comments"></textarea>
                <div class="editor-preview" data-editor-preview hidden></div>
              </label>
              <label>Comment Prompt <textarea name="reflection_prompt" rows="3"></textarea></label>
              <div class="admin-two">
                <label>Sort Order <input type="number" name="sort_order" value="<?= e((string) next_sort_order()) ?>"></label>
                <label class="admin-check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
              </div>
              <button class="btn" type="submit">Add Lesson</button>
            </form>
          </details>
        </div>
      </section>

      <section class="admin-grid">
        <section class="admin-card admin-accent-reflections">
          <h2>Comments</h2>
          <p>Open a lesson to view, edit, or delete submitted comments.</p>
          <div class="reflection-module-groups">
            <?php foreach ($reflectionsByModule as $moduleComments): ?>
              <?php $reflectionGroupOpen = $reflectionEditing && (int) $reflectionEditing['module_id'] === (int) $moduleComments['items'][0]['module_id']; ?>
              <details class="reflection-module-group" <?= $reflectionGroupOpen ? 'open' : '' ?>>
                <summary>
                  <span>
                    <strong><?= e($moduleComments['label']) ?>: <?= e($moduleComments['title']) ?></strong>
                    <small><?= e($moduleComments['slug']) ?></small>
                  </span>
                  <span class="reflection-count"><?= e((string) count($moduleComments['items'])) ?> <?= count($moduleComments['items']) === 1 ? 'entry' : 'entries' ?></span>
                </summary>
                <div class="reflection-module-items">
                  <?php foreach ($moduleComments['items'] as $reflection): ?>
                    <article class="reflection-admin-entry">
                      <div>
                        <span class="reflection-admin-module"><?= e($moduleComments['label']) ?>: <?= e($moduleComments['title']) ?></span>
                        <strong class="status-pill"><?= e(isset($reflection['reflection_status']) ? $reflection['reflection_status'] : 'approved') ?></strong>
                        <p><?= e($reflection['reflection_text']) ?></p>
                        <span class="reflection-admin-date"><?= e($reflection['created_at']) ?></span>
                      </div>
                      <div class="admin-actions">
                        <?php if (isset($reflection['reflection_status']) && $reflection['reflection_status'] !== 'approved'): ?>
                          <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="approve_reflection">
                            <input type="hidden" name="id" value="<?= e((string) $reflection['id']) ?>">
                            <button class="btn secondary" type="submit">Approve</button>
                          </form>
                        <?php endif; ?>
                        <?php if (isset($reflection['reflection_status']) && $reflection['reflection_status'] === 'pending'): ?>
                          <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="reject_reflection">
                            <input type="hidden" name="id" value="<?= e((string) $reflection['id']) ?>">
                            <button class="btn secondary danger" type="submit">Reject</button>
                          </form>
                        <?php endif; ?>
                        <a class="btn secondary" href="index.php?reflection_edit=<?= e((string) $reflection['id']) ?>#reflection-editor">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete this comment?');">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="action" value="delete_reflection">
                          <input type="hidden" name="id" value="<?= e((string) $reflection['id']) ?>">
                          <button class="btn secondary danger" type="submit">Delete</button>
                        </form>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              </details>
            <?php endforeach; ?>
            <?php if (!$reflectionsByModule): ?>
              <p class="admin-empty">No comments have been submitted yet.</p>
            <?php endif; ?>
          </div>
        </section>

        <form class="admin-card admin-form admin-accent-reflection-editor" method="post" id="reflection-editor">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_reflection">
          <input type="hidden" name="id" value="<?= e((string) ($reflectionEditing ? $reflectionEditing['id'] : '')) ?>">
          <h2><?= $reflectionEditing ? 'Edit Comment' : 'Comment Editor' ?></h2>
          <label>Lesson
            <select name="reflection_module_id" required>
              <option value="">Select lesson</option>
              <?php foreach ($reflectionModules as $module): ?>
                <option value="<?= e((string) $module['id']) ?>" <?= $reflectionEditing && (int) $reflectionEditing['module_id'] === (int) $module['id'] ? 'selected' : '' ?>><?= e($module['module_type']) ?>: <?= e($module['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Comment Text
            <textarea name="reflection_text" rows="10" required><?= e($reflectionEditing ? $reflectionEditing['reflection_text'] : '') ?></textarea>
          </label>
          <?php if ($reflectionEditing): ?>
            <button class="btn" type="submit">Save Comment</button>
          <?php else: ?>
            <button class="btn" type="submit" disabled>Choose Edit From List</button>
          <?php endif; ?>
          <?php if ($reflectionEditing): ?><a class="btn secondary" href="index.php#reflection-editor">Cancel</a><?php endif; ?>
        </form>
      </section>

      <details class="admin-card admin-accent-resources admin-accordion">
        <summary>
          <span>
            <strong>Training Resources</strong>
            <small>Add or replace PDF/DOC/DOCX files for the completion page.</small>
          </span>
        </summary>
        <div class="admin-list admin-accordion-body">
          <?php foreach ($resources as $resource): ?>
            <article class="admin-row admin-resource">
              <form class="admin-resource-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_resource">
                <input type="hidden" name="id" value="<?= e((string) $resource['id']) ?>">
                <input type="hidden" name="existing_file_url" value="<?= e($resource['file_url']) ?>">
                <input type="hidden" name="existing_file_name" value="<?= e(isset($resource['file_name']) ? $resource['file_name'] : '') ?>">
                <input type="hidden" name="existing_file_type" value="<?= e(isset($resource['file_type']) ? $resource['file_type'] : '') ?>">
                <div>
                  <strong><?= e($resource['title']) ?></strong>
                  <span><?= e(isset($resource['file_name']) && $resource['file_name'] ? $resource['file_name'] : $resource['file_url']) ?></span>
                </div>
                <div class="admin-two">
                  <label>Title <input name="resource_title" value="<?= e($resource['title']) ?>" required></label>
                  <label>Sort <input type="number" name="resource_sort_order" value="<?= e((string) $resource['sort_order']) ?>"></label>
                </div>
                <div class="admin-two">
                  <label>Replace File <input type="file" name="resource_file" accept=".pdf,.doc,.docx"></label>
                  <div class="admin-actions">
                    <button class="btn" type="submit">Save Resource</button>
                  </div>
                </div>
              </form>
              <form class="admin-actions admin-resource-delete" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_resource">
                <input type="hidden" name="id" value="<?= e((string) $resource['id']) ?>">
                <button class="btn secondary danger" type="submit" onclick="return confirm('Delete this resource?');">Delete</button>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      </details>

      <details class="admin-card admin-accent-site-content admin-accordion">
        <summary>
          <span>
            <strong>Landing & Welcome Pages</strong>
            <small>Edit the public landing copy, welcome page text, and welcome widgets.</small>
          </span>
        </summary>
        <form class="admin-form admin-accordion-body" method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_site_content">
          <div class="admin-two">
            <label>Landing Title <input name="landing_title" value="<?= e($siteSettings['landing_title']) ?>" required></label>
            <label>Landing Button <input name="landing_button" value="<?= e($siteSettings['landing_button']) ?>" required></label>
          </div>
          <label>Landing Intro <textarea name="landing_intro" rows="3"><?= e($siteSettings['landing_intro']) ?></textarea></label>
          <div class="admin-two">
            <label>Welcome Title <input name="welcome_title" value="<?= e($siteSettings['welcome_title']) ?>" required></label>
            <label>Pathway Title <input name="pathway_title" value="<?= e($siteSettings['pathway_title']) ?>" required></label>
          </div>
          <label>Welcome Intro <textarea name="welcome_intro" rows="4"><?= e($siteSettings['welcome_intro']) ?></textarea></label>
          <div class="admin-widget-editor">
            <section>
              <h3>Welcome Widget 1</h3>
              <label>Title <input name="training_tips_title" value="<?= e($siteSettings['training_tips_title']) ?>" required></label>
              <label>Text <textarea name="training_tips" rows="5" placeholder="One bullet per line"><?= e($siteSettings['training_tips']) ?></textarea></label>
            </section>
            <section>
              <h3>Welcome Widget 2</h3>
              <label>Title <input name="training_widget_2_title" value="<?= e($siteSettings['training_widget_2_title']) ?>" required></label>
              <label>Text <textarea name="training_widget_2_text" rows="5" placeholder="One bullet per line"><?= e($siteSettings['training_widget_2_text']) ?></textarea></label>
            </section>
            <section>
              <h3>Welcome Widget 3</h3>
              <label>Title <input name="training_widget_3_title" value="<?= e($siteSettings['training_widget_3_title']) ?>" required></label>
              <label>Text <textarea name="training_widget_3_text" rows="5" placeholder="One bullet per line"><?= e($siteSettings['training_widget_3_text']) ?></textarea></label>
            </section>
          </div>
          <button class="btn" type="submit">Save Page Content</button>
        </form>
      </details>

      <details class="admin-card admin-accent-users admin-accordion">
        <summary>
          <span>
            <strong>Learner Video Progress</strong>
            <small>Progress is saved for approved learners while they watch lesson videos.</small>
          </span>
        </summary>
        <div class="admin-list admin-accordion-body">
          <?php if ($videoProgressRows): ?>
            <?php foreach ($videoProgressRows as $row): ?>
              <article class="admin-row admin-progress-row">
                <div>
                  <strong><?= e($row['full_name']) ?> <span class="status-pill"><?= e(progress_percent_label($row)) ?></span></strong>
                  <span><?= e($row['email']) ?> | <?= e($row['module_type']) ?>: <?= e($row['module_title']) ?></span>
                  <div class="admin-progress-track" aria-label="<?= e(progress_percent_label($row)) ?> watched">
                    <span style="width: <?= e(progress_percent_label($row)) ?>"></span>
                  </div>
                  <p><?= e(format_video_time($row['current_seconds'])) ?> watched<?= (int) $row['duration_seconds'] > 0 ? ' of ' . e(format_video_time($row['duration_seconds'])) : '' ?><?= !empty($row['is_completed']) ? ' | Completed' : '' ?> | Updated <?= e($row['updated_at']) ?></p>
                </div>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="admin-empty">No learner video progress has been saved yet.</p>
          <?php endif; ?>
        </div>
      </details>

      <section class="admin-grid">
        <form class="admin-card admin-form admin-accent-users" method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create_course_user">
          <h2>Add Course User</h2>
          <label>Name <input name="user_full_name" required></label>
          <label>Email <input type="email" name="user_email" required></label>
          <div class="admin-two">
            <label>Organization <input name="user_organization"></label>
            <label>Role / Title <input name="user_role_title"></label>
          </div>
          <label>Phone <input name="user_phone"></label>
          <label>Admin Notes <textarea name="user_admin_notes" rows="3" placeholder="Private notes visible only in this admin panel"></textarea></label>
          <label>Password <input type="text" name="user_password" placeholder="Leave blank to generate"></label>
          <button class="btn" type="submit">Create Approved User</button>
        </form>

        <section class="admin-card admin-accent-users">
          <h2>Course Users</h2>
          <p>Approve pending registrations or remove users who should no longer access the course.</p>
          <div class="admin-list">
            <?php foreach ($courseUsers as $user): ?>
              <article class="admin-row">
                <div>
                  <strong><?= e($user['full_name']) ?> <span class="status-pill"><?= e($user['status']) ?></span></strong>
                  <span><?= e($user['email']) ?><?= $user['organization'] ? ' | ' . e($user['organization']) : '' ?></span>
                  <?php if ($user['interests']): ?><p><?= e($user['interests']) ?></p><?php endif; ?>
                  <?php if (!empty($user['admin_notes'])): ?><p><strong>Admin notes:</strong> <?= e($user['admin_notes']) ?></p><?php endif; ?>
                </div>
                <div class="admin-actions">
                  <?php if ($user['status'] !== 'approved'): ?>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="approve_course_user">
                      <input type="hidden" name="id" value="<?= e((string) $user['id']) ?>">
                      <button class="btn secondary" type="submit">Approve</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($user['status'] === 'pending'): ?>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="reject_course_user">
                      <input type="hidden" name="id" value="<?= e((string) $user['id']) ?>">
                      <button class="btn secondary danger" type="submit">Reject</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" onsubmit="return confirm('Delete this course user?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_course_user">
                    <input type="hidden" name="id" value="<?= e((string) $user['id']) ?>">
                    <button class="btn secondary danger" type="submit">Delete</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      </section>

      <?php if (false): ?>
      <section class="admin-grid">
        <form class="admin-card admin-form admin-accent-users" method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_authorized_email">
          <h2>Authorized Course User</h2>
          <label>Email <input type="email" name="authorized_email" required></label>
          <label>Organization <input name="authorized_organization"></label>
          <label>Notes <textarea name="authorized_notes" rows="3"></textarea></label>
          <button class="btn" type="submit">Add Authorized User Email</button>
        </form>

        <section class="admin-card admin-accent-users">
          <h2>Authorized Course Users List</h2>
          <p>Registrations from these emails are automatically approved and sent login details.</p>
          <div class="admin-list">
            <?php foreach ($authorizedEmails as $entry): ?>
              <article class="admin-row">
                <div>
                  <strong><?= e($entry['email']) ?></strong>
                  <span><?= e($entry['organization'] ? $entry['organization'] : 'No organization') ?></span>
                </div>
                <form class="admin-actions" method="post" onsubmit="return confirm('Remove this authorized email?');">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete_authorized_email">
                  <input type="hidden" name="id" value="<?= e((string) $entry['id']) ?>">
                  <button class="btn secondary danger" type="submit">Remove</button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      </section>
      <?php endif; ?>

      <?php if (is_master_admin()): ?>
      <section class="admin-grid">
        <form class="admin-card admin-form admin-accent-admins" method="post">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create_admin">
          <h2>Administrator</h2>
          <label>Username <input name="admin_username" autocomplete="off" placeholder="new-admin" required></label>
          <label>Email <input type="email" name="admin_email" autocomplete="off" placeholder="admin@example.com"></label>
          <label>Password <input type="password" name="admin_password" autocomplete="new-password" minlength="8" required></label>
          <label>Confirm Password <input type="password" name="admin_password_confirm" autocomplete="new-password" minlength="8" required></label>
          <button class="btn" type="submit">Add Administrator</button>
        </form>

        <section class="admin-card admin-accent-admins">
          <h2>Administrators List</h2>
          <p>Database admins can log in with the math challenge on the login page.</p>
          <div class="admin-list">
            <?php if ($admins): ?>
              <?php foreach ($admins as $admin): ?>
                <article class="admin-row">
                  <div>
                    <strong><?= e($admin['username']) ?></strong>
                    <span><?= e($admin['email'] ? $admin['email'] . ' | ' : '') ?><?= e($admin['is_active'] ? 'Active' : 'Inactive') ?> | Added <?= e($admin['created_at']) ?></span>
                  </div>
                  <form class="admin-actions" method="post" onsubmit="return confirm('Delete this admin user?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_admin">
                    <input type="hidden" name="id" value="<?= e((string) $admin['id']) ?>">
                    <button class="btn secondary danger" type="submit">Delete</button>
                  </form>
                </article>
              <?php endforeach; ?>
            <?php else: ?>
              <p>No database admin users found. Run <strong>install.php</strong> once to create the table.</p>
            <?php endif; ?>
          </div>
        </section>
      </section>
      <?php endif; ?>
    </main>
    <script>
      function renderLessonPreview(value) {
        var html = "";
        var listOpen = false;
        function closeList() {
          if (listOpen) {
            html += "</ul>";
            listOpen = false;
          }
        }
        function inlineFormat(text) {
          return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
            .replace(/\*(.+?)\*/g, "<em>$1</em>");
        }
        value.split(/\r?\n/).forEach(function (line) {
          var trimmed = line.trim();
          var imageMatch;
          if (!trimmed) {
            closeList();
            return;
          }
          imageMatch = trimmed.match(/^!\[(.*?)\]\((.*?)\)$/);
          if (imageMatch) {
            closeList();
            html += '<figure class="module-visual"><img src="' + imageMatch[2].replace(/"/g, "&quot;") + '" alt="' + inlineFormat(imageMatch[1]).replace(/"/g, "&quot;") + '"></figure>';
          } else if (trimmed.indexOf("## ") === 0) {
            closeList();
            html += "<h4>" + inlineFormat(trimmed.slice(3)) + "</h4>";
          } else if (trimmed.indexOf("- ") === 0) {
            if (!listOpen) {
              html += "<ul>";
              listOpen = true;
            }
            html += "<li>" + inlineFormat(trimmed.slice(2)) + "</li>";
          } else if (trimmed.charAt(0) === "<") {
            closeList();
            html += trimmed;
          } else {
            closeList();
            html += "<p>" + inlineFormat(trimmed) + "</p>";
          }
        });
        closeList();
        return html || "<p>No lesson text yet.</p>";
      }

      document.addEventListener("click", function (event) {
        var modeButton = event.target.closest("[data-editor-mode]");
        var actionButton = event.target.closest("[data-editor-action]");
        var editorWrap = event.target.closest(".admin-editor");
        var editor = editorWrap ? editorWrap.querySelector("[data-content-editor]") : document.querySelector("[data-content-editor]");
        var preview = editorWrap ? editorWrap.querySelector("[data-editor-preview]") : null;

        if (modeButton && editor && preview) {
          var mode = modeButton.getAttribute("data-editor-mode");
          preview.innerHTML = renderLessonPreview(editor.value);
          preview.hidden = mode !== "preview";
          editor.hidden = mode === "preview";
          editor.classList.toggle("is-html-mode", mode === "html");
          editorWrap.querySelectorAll("[data-editor-mode]").forEach(function (button) {
            button.classList.toggle("active", button === modeButton);
          });
          if (mode !== "preview") {
            editor.focus();
          }
          return;
        }

        if (!actionButton || !editor) {
          return;
        }

        var action = actionButton.getAttribute("data-editor-action");
        var start = editor.selectionStart || 0;
        var end = editor.selectionEnd || 0;
        var selected = editor.value.slice(start, end);
        var fallback = {
          heading: "Section heading",
          paragraph: "Short lesson paragraph for the learner.",
          list: "First list item\nSecond list item\nThird list item",
          bold: "important text",
          italic: "emphasized text",
          image: "Lesson visual"
        };
        var text = selected || fallback[action] || "";

        if (action === "heading") {
          text = "## " + text.replace(/^#+\s*/, "");
        } else if (action === "list") {
          text = text.split(/\r?\n/).map(function (line) {
            line = line.trim();
            return line ? "- " + line.replace(/^-\s*/, "") : "";
          }).join("\n");
        } else if (action === "bold") {
          text = "**" + text.replace(/^\*\*|\*\*$/g, "") + "**";
        } else if (action === "italic") {
          text = "*" + text.replace(/^\*|\*$/g, "") + "*";
        } else if (action === "image") {
          text = "![" + text.replace(/[\[\]]/g, "") + "](uploads/module-images/example.jpg)";
        }

        var prefix = start > 0 && editor.value.charAt(start - 1) !== "\n" ? "\n\n" : "";
        var suffix = end < editor.value.length && editor.value.charAt(end) !== "\n" ? "\n\n" : "";
        editor.hidden = false;
        if (preview) {
          preview.hidden = true;
        }
        editor.setRangeText(prefix + text + suffix, start, end, "end");
        editor.focus();
      });
    </script>
  </body>
</html>



