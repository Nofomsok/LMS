<?php

require_once __DIR__ . '/includes.php';

$modules = get_modules();
$resources = get_training_resources();
$siteSettings = get_site_settings();
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$module = null;

if (!in_array($page, ['home', 'overview', 'complete'], true)) {
    $module = get_module_by_slug($page);
    if (!$module) {
        $page = 'home';
    }
}

if ($page !== 'home') {
    require_course_user();
} elseif (!is_course_user() || !current_course_user()) {
    redirect('login.php');
}

$videoProgressMap = get_video_progress_map();
$currentUser = current_course_user();

$moduleIndex = [];
foreach ($modules as $index => $item) {
    $moduleIndex[$item['slug']] = $index;
}

function is_intro_module($module)
{
    return $module['slug'] === 'introduction';
}

function training_modules($modules)
{
    $items = array();
    foreach ($modules as $module) {
        if (!is_intro_module($module)) {
            $items[] = $module;
        }
    }

    return $items;
}

function training_module_number($modules, $slug)
{
    $number = 1;
    foreach ($modules as $module) {
        if (is_intro_module($module)) {
            continue;
        }
        if ($module['slug'] === $slug) {
            return $number;
        }
        $number++;
    }

    return 0;
}

function first_training_slug($modules)
{
    foreach ($modules as $module) {
        if (!is_intro_module($module)) {
            return $module['slug'];
        }
    }

    return '';
}

function logo_markup($training = false)
{
    $mark = $training ? '#9be4dc' : '#008777';
    $text = $training ? '#ffffff' : 'currentColor';

    return '
        <a class="brand" href="' . e(page_url('home')) . '" aria-label="Return to home">
          <svg width="44" height="44" viewBox="0 0 64 64" aria-hidden="true">
            <rect x="10" y="12" width="44" height="38" rx="6" fill="none" stroke="' . $mark . '" stroke-width="3"/>
            <path d="M18 24h28M18 33h14M18 42h22" fill="none" stroke="' . $mark . '" stroke-width="3" stroke-linecap="round"/>
            <path d="M43 18v-6M32 18v-6M21 18v-6" fill="none" stroke="' . $mark . '" stroke-width="3" stroke-linecap="round"/>
          </svg>
          <span style="color:' . $text . '">LMS DEMO<small>Learning platform</small></span>
        </a>';
}

function topbar_markup($training, $modules, $page)
{
    $nav = '';
    if ($training) {
        $nav .= '<nav class="nav" aria-label="Course navigation">';
        foreach ($modules as $item) {
            $active = $page === $item['slug'] ? ' active' : '';
            $nav .= '<a class="nav-link' . $active . '" href="' . e(page_url($item['slug'])) . '">' . e($item['module_type']) . '</a>';
        }
        $nav .= '</nav>';
    }

    if (is_course_user()) {
        $profile = current_course_user();
        $profileLink = $profile && (int) $profile['id'] > 0 ? '<a class="profile-link" href="' . e(page_url('overview')) . '#profile">Profile</a>' : '';
        $account = $profileLink . '<a class="account-link" href="logout.php">Sign Out</a>';
    } else {
        $account = '<a class="account-link" href="login.php">Sign In</a>';
    }

    return '<header class="topbar ' . ($training ? 'training' : '') . '">' .
        logo_markup($training) . $nav . '<div class="protected">' . $account . '</div></header>';
}

function value_icon($type)
{
    $icons = [
        'conversations' => '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M10 20a8 8 0 1 1 16 0v6H14a4 4 0 0 1-4-4z"/><path d="M24 18h3a8 8 0 0 1 8 8v7a4 4 0 0 1-4 4H18v-7"/><path d="M17 31l-5 5v-8"/><path d="M30 37l5 5v-8"/></svg>',
        'aligned' => '<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="17"/><circle cx="24" cy="24" r="9"/><circle cx="24" cy="24" r="3"/></svg>',
        'care' => '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M10 29c6 0 12 3 14 8 2-5 8-8 14-8"/><path d="M24 36V18"/><path d="M24 18c-5-8-15-3-11 5 2 4 7 6 11 8 4-2 9-4 11-8 4-8-6-13-11-5z"/></svg>',
    ];

    return '<span class="value-icon">' . $icons[$type] . '</span>';
}

function path_badge($module, $modules)
{
    if (!is_intro_module($module)) {
        return e((string) training_module_number($modules, $module['slug']));
    }

    return '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M12 12h9c4 0 7 3 7 7v17c0-4-3-7-7-7h-9z"/><path d="M36 12h-9c-4 0-7 3-7 7v17c0-4 3-7 7-7h9z"/><path d="M20 17h-4M20 22h-4M32 17h-4M32 22h-4"/></svg>';
}

function video_block($module, $progress = null)
{
    $videoUrl = trim((string) (isset($module['video_url']) ? $module['video_url'] : ''));
    $imageUrl = trim((string) (isset($module['image_url']) ? $module['image_url'] : ''));

    if ($videoUrl === '' && $imageUrl !== '') {
        return '
      <div class="video-card module-image-card">
        <img src="' . e($imageUrl) . '" alt="' . e($module['title']) . '">
      </div>';
    }

    if ($videoUrl === '') {
        return '
      <div class="video-card module-empty-media">
        <span>No video or image has been added for this lesson.</span>
      </div>';
    }

    $currentSeconds = $progress && !empty($progress['current_seconds']) ? (int) $progress['current_seconds'] : 0;
    $resumeSeconds = max(0, $currentSeconds - 5);
    $playerId = 'module-video-' . (int) $module['id'];
    $isMp4 = preg_match('~\.mp4(?:\?|#|$)~i', $videoUrl);

    if ($isMp4) {
        return '
      <div class="video-card">
        <video id="' . e($playerId) . '" class="video-frame" src="' . e($videoUrl) . '" controls preload="metadata" playsinline data-html5-video data-module-id="' . e((string) $module['id']) . '" data-csrf-token="' . e(csrf_token()) . '" data-save-url="video_progress_save.php" data-resume-seconds="' . e((string) $resumeSeconds) . '"></video>
      </div>';
    }

    return '
      <div class="video-card">
        <iframe id="' . e($playerId) . '" class="video-frame" src="' . e(youtube_embed_url_with_api($videoUrl, $resumeSeconds)) . '" title="' . e($module['title']) . ' video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen data-video-player data-module-id="' . e((string) $module['id']) . '" data-csrf-token="' . e(csrf_token()) . '" data-save-url="video_progress_save.php" data-resume-seconds="' . e((string) $resumeSeconds) . '"></iframe>
      </div>';
}

function video_progress_summary($module, $progress = null)
{
    $percent = $progress ? min(100, max(0, (int) round((float) $progress['percent_watched']))) : 0;
    $completed = $progress && !empty($progress['is_completed']);

    return '
                <div class="video-progress-panel" data-video-progress-panel="' . e((string) $module['id']) . '">
                  <div class="video-progress-copy">
                    <strong>Video progress</strong>
                    <span data-video-progress-label="' . e((string) $module['id']) . '">' . e((string) $percent) . '% watched' . ($completed ? ' - completed' : '') . '</span>
                  </div>
                  <div class="video-progress-track" aria-hidden="true"><span data-video-progress-fill="' . e((string) $module['id']) . '" style="width:' . e((string) $percent) . '%"></span></div>
                </div>';
}

function module_content_block($module)
{
    $content = trim((string) (isset($module['content_text']) ? $module['content_text'] : ''));
    if ($content === '') {
        return '';
    }

    $heading = trim((string) (isset($module['module_type']) ? $module['module_type'] : 'Lesson'));
    if (!is_intro_module($module) && !empty($module['display_number'])) {
        $heading = 'Lesson ' . trim((string) $module['display_number']);
    }

    $html = '';
    $listOpen = false;
    $lines = preg_split('/\r\n|\r|\n/', $content);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            continue;
        }

        if (preg_match('/^!\[(.*?)\]\((.*?)\)$/', $line, $imageMatch)) {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            $html .= '<figure class="module-visual"><img src="' . e($imageMatch[2]) . '" alt="' . e($imageMatch[1]) . '"></figure>';
            continue;
        }

        if (strpos($line, '## ') === 0) {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            $html .= '<h4>' . content_inline_html(substr($line, 3)) . '</h4>';
            continue;
        }

        if (strpos($line, '- ') === 0) {
            if (!$listOpen) {
                $html .= '<ul>';
                $listOpen = true;
            }
            $html .= '<li>' . content_inline_html(substr($line, 2)) . '</li>';
            continue;
        }

        if ($listOpen) {
            $html .= '</ul>';
            $listOpen = false;
        }

        if (strpos($line, '<') === 0) {
            $html .= content_allowed_html($line);
        } else {
            $html .= '<p>' . content_inline_html($line) . '</p>';
        }
    }

    if ($listOpen) {
        $html .= '</ul>';
    }

    return '
              <section class="module-content">
                <h3>' . e($heading) . ' Overview</h3>
                ' . $html . '
              </section>';
}

function content_inline_html($value)
{
    $escaped = e($value);
    $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
    return preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $escaped);
}

function content_allowed_html($value)
{
    $html = strip_tags($value, '<p><br><strong><b><em><i><u><ul><ol><li><h3><h4><blockquote><a><img><figure><figcaption>');
    $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', ' $1="#"', $html);
    return $html;
}

function private_note_block($module)
{
    $note = get_learner_note((int) $module['id']);
    $status = isset($_GET['note']) ? (string) $_GET['note'] : '';
    $message = '';
    if ($status === 'saved') {
        $message = '<p class="note-status">Private note saved.</p>';
    } elseif ($status === 'blocked' || $status === 'unavailable') {
        $message = '<p class="note-status error">Private note could not be saved.</p>';
    }

    return '
              <section class="private-note-panel">
                <div>
                  <h3>Private Lesson Notes</h3>
                  <p>Only you can see these notes. Use them for reminders, action items, or questions to revisit later.</p>
                </div>
                ' . $message . '
                <form method="post" action="lesson_note_save.php">
                  <textarea name="note_text" rows="6" maxlength="5000" placeholder="Write your private notes for this lesson">' . e($note) . '</textarea>
                  <input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">
                  <input type="hidden" name="module_id" value="' . e((string) $module['id']) . '">
                  <button class="btn secondary" type="submit">Save Private Note</button>
                </form>
              </section>';
}
function side_nav($modules, $activeSlug)
{
    $total = count(training_modules($modules));
    $activeIndex = max(1, training_module_number($modules, $activeSlug));
    $html = '<aside class="side-nav"><h3>Course Lessons</h3>';
    foreach ($modules as $index => $module) {
        if (is_intro_module($module)) {
            $icon = '+';
            $label = 'Introduction';
        } else {
            $icon = (string) training_module_number($modules, $module['slug']);
            $label = $module['title'];
        }
        $active = $module['slug'] === $activeSlug ? ' active' : '';
        $html .= '<a class="side-link' . $active . '" href="' . e(page_url($module['slug'])) . '"><span class="side-number">' . e($icon) . '</span><span>' . e($label) . '</span></a>';
    }
    $percent = $total > 0 ? min(100, max(1, ($activeIndex / $total) * 100)) : 0;
    $html .= '<div class="progress-box">You are on Lesson ' . e((string) $activeIndex) . ' of ' . e((string) $total) . '<div class="progress-track"><div class="progress-fill" style="width:' . e((string) $percent) . '%"></div></div></div></aside>';

    return $html;
}

function focus_list($focus)
{
    $items = array();
    foreach ($focus as $item) {
        $items[] = '<li>' . e($item) . '</li>';
    }

    return '<ul class="focus-list">' . implode('', $items) . '</ul>';
}

function plain_list($text, $class)
{
    $items = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $items[] = '<li>' . e($item) . '</li>';
        }
    }

    return '<ul class="' . e($class) . '">' . implode('', $items) . '</ul>';
}

function learner_video_total_percent($modules, $videoProgressMap)
{
    $total = 0;
    $sum = 0;

    foreach ($modules as $module) {
        if (empty($module['video_url'])) {
            continue;
        }
        $total++;
        $progress = isset($videoProgressMap[(int) $module['id']]) ? $videoProgressMap[(int) $module['id']] : null;
        $sum += $progress && isset($progress['percent_watched']) ? min(100, max(0, (float) $progress['percent_watched'])) : 0;
    }

    return $total > 0 ? (int) round($sum / $total) : 0;
}

function profile_card($user, $modules, $videoProgressMap)
{
    if (!$user || (int) $user['id'] <= 0) {
        return '';
    }

    $organization = !empty($user['organization']) ? (string) $user['organization'] : 'Demo account';
    $roleTitle = !empty($user['role_title']) ? (string) $user['role_title'] : 'Learner';
    $lastLogin = !empty($user['last_login_at']) ? (string) $user['last_login_at'] : 'First session';
    $passwordUrl = 'forgot_password.php?email=' . rawurlencode((string) $user['email']);
    $overallPercent = learner_video_total_percent($modules, $videoProgressMap);

    return '
          <section class="profile-card" id="profile">
            <div class="profile-head">
              <span class="profile-avatar" aria-hidden="true">' . e(strtoupper(substr((string) $user['full_name'], 0, 1))) . '</span>
              <div>
                <h3>Learner Profile</h3>
                <p>Demo learner dashboard</p>
              </div>
            </div>
            <div class="profile-progress">
              <div><strong>' . e((string) $overallPercent) . '%</strong><span>Total videos watched</span></div>
              <div class="profile-progress-track" aria-hidden="true"><span style="width:' . e((string) $overallPercent) . '%"></span></div>
            </div>
            <div class="profile-details compact">
              <div><strong>Name</strong><span>' . e($user['full_name']) . '</span></div>
              <div><strong>Email</strong><span>' . e($user['email']) . '</span></div>
              <div><strong>Organization</strong><span>' . e($organization) . '</span></div>
              <div><strong>Role</strong><span>' . e($roleTitle) . '</span></div>
              <div><strong>Status</strong><span>' . e(ucfirst((string) $user['status'])) . '</span></div>
              <div><strong>Last login</strong><span>' . e($lastLogin) . '</span></div>
            </div>
            <div class="profile-actions">
              <a class="btn secondary" href="' . e($passwordUrl) . '">Edit Password</a>
            </div>
          </section>';
}

function module_position($modules, $slug)
{
    $index = 0;
    foreach ($modules as $key => $module) {
        if ($module['slug'] === $slug) {
            $index = $key;
            break;
        }
    }
    $previous = $index === 0 ? 'overview' : $modules[$index - 1]['slug'];
    $next = $index === count($modules) - 1 ? 'complete' : $modules[$index + 1]['slug'];

    return [$index, $previous, $next];
}

function module_back_label($modules, $index)
{
    if ($index <= 0) {
        return 'Overview';
    }

    $previous = $modules[$index - 1];
    return is_intro_module($previous) ? 'Introduction' : $previous['module_type'];
}

function module_next_label($modules, $index, $next)
{
    if ($next === 'complete') {
        return 'Complete Training';
    }

    return 'Continue to ' . $modules[$index + 1]['module_type'];
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css?v=20260514-no-lock-icon">
  </head>
  <body>
    <div class="site-shell">
      <?php if ($page === 'home'): ?>
        <?= topbar_markup(false, $modules, $page) ?>
        <main class="hero">
          <div class="hero-copy">
            <h1><?= e($siteSettings['landing_title']) ?></h1>
            <p><?= e($siteSettings['landing_intro']) ?></p>
            <a class="btn" href="<?= e(page_url('overview')) ?>"><?= e($siteSettings['landing_button']) ?> &rarr;</a>
          </div>
          <div class="hero-image" role="img" aria-label="Clinician holding a patient's hand"></div>
        </main>
        <section class="value-strip" aria-label="Training values">
          <div class="value-item"><?= value_icon('conversations') ?><div><strong>Better Conversations</strong><span>Stronger Relationships</span></div></div>
          <div class="value-item"><?= value_icon('aligned') ?><div><strong>Aligned Care</strong><span>Better Outcomes</span></div></div>
          <div class="value-item"><?= value_icon('care') ?><div><strong>Compassionate Care</strong><span>That Matters</span></div></div>
        </section>
      <?php elseif ($page === 'overview'): ?>
        <?= topbar_markup(false, $modules, $page) ?>
        <main class="content overview-grid">
          <div class="welcome-profile-row">
            <div>
              <h2><?= e($siteSettings['welcome_title']) ?></h2>
              <p class="intro-copy"><?= e($siteSettings['welcome_intro']) ?></p>
            </div>
            <?= profile_card($currentUser, $modules, $videoProgressMap) ?>
          </div>
          <div>
            <h3><?= e($siteSettings['pathway_title']) ?></h3>
            <div class="pathway">
              <?php foreach ($modules as $item): ?>
                <a class="path-step" href="<?= e(page_url($item['slug'])) ?>">
                  <span class="path-badge"><?= path_badge($item, $modules) ?></span>
                  <span><?= e($item['title']) ?></span>
                  <?php $pathProgress = isset($videoProgressMap[(int) $item['id']]) ? $videoProgressMap[(int) $item['id']] : null; ?>
                  <small class="path-progress"><?= e(progress_percent_label($pathProgress)) ?> watched</small>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="welcome-widgets">
            <div class="hint-box">
              <h3><?= e($siteSettings['training_tips_title']) ?></h3>
              <?= plain_list($siteSettings['training_tips'], 'hint-list') ?>
            </div>
            <div class="hint-box">
              <h3><?= e($siteSettings['training_widget_2_title']) ?></h3>
              <?= plain_list($siteSettings['training_widget_2_text'], 'hint-list') ?>
            </div>
            <div class="hint-box">
              <h3><?= e($siteSettings['training_widget_3_title']) ?></h3>
              <?= plain_list($siteSettings['training_widget_3_text'], 'hint-list') ?>
            </div>
          </div>
          <div class="overview-actions">
            <a class="btn overview-start" href="<?= e(page_url('introduction')) ?>">Start with Introduction &rarr;</a>
          </div>
        </main>
      <?php elseif ($page === 'complete'): ?>
        <main class="complete-grid complete-grid-tall">
          <section class="complete-panel">
            <div class="checkmark">OK</div>
            <h2>Training Complete!</h2>
            <p><strong>Thank you for completing LMS DEMO.</strong></p>
            <p>This demo course shows the complete learner journey: video progress, lesson content, comments, private notes, resources, and completion flow.</p>
            <div class="complete-summary">
              <strong>What you can review next</strong>
              <ul class="completion-list">
                <li>Revisit your private lesson notes</li>
                <li>Check comments and learner engagement</li>
                <li>Review admin controls and reporting with your sales team</li>
              </ul>
            </div>
            <div class="complete-actions">
              <a class="btn" href="<?= e(page_url('overview')) ?>">Review Course</a>
              <form method="post" action="retake_course.php" onsubmit="return confirm('Reset your video progress and retake this course? Your private notes will stay saved.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn secondary" type="submit">Retake Course</button>
              </form>
              <a class="btn ghost" href="<?= e(page_url('home')) ?>">Return to Top</a>
            </div>
          </section>
          <section class="resource-panel">
            <h2>Training Resources</h2>
            <?php foreach ($resources as $resource): ?>
              <a class="resource-card" href="<?= e($resource['file_url']) ?>" target="<?= e(resource_download_target($resource)) ?>" rel="<?= e(resource_download_rel($resource)) ?>">
                <span class="resource-icon">PDF</span>
                <div>
                  <strong><?= e($resource['title']) ?></strong>
                  <span><?= e(isset($resource['file_name']) && $resource['file_name'] ? $resource['file_name'] : 'Download file') ?></span>
                </div>
                <span class="download">DL</span>
              </a>
            <?php endforeach; ?>
          </section>
        </main>
      <?php elseif ($module): ?>
        <?php list($index, $previous, $next) = module_position($modules, $module['slug']); ?>
        <?php $currentProgress = isset($videoProgressMap[(int) $module['id']]) ? $videoProgressMap[(int) $module['id']] : null; ?>
        <?= topbar_markup(true, $modules, $module['slug']) ?>
        <?php if ($module['slug'] === 'introduction'): ?>
          <main class="content training-layout">
            <?= side_nav($modules, $module['slug']) ?>
            <section class="module-main">
              <p class="eyebrow">Introduction</p>
              <div class="module-grid">
                <?= video_block($module, $currentProgress) ?>
                <aside class="info-panel">
                  <strong>Estimated time</strong>
                  <div class="meta-row">Time: <?= e($module['time_label']) ?></div>
                  <?= video_progress_summary($module, $currentProgress) ?>
                  <p><?= e(isset($module['focus'][0]) ? $module['focus'][0] : '') ?></p>
                  <strong>Key Question</strong>
                  <p><?= e(isset($module['key_question']) ? $module['key_question'] : '') ?></p>
                </aside>
              </div>
              <?= module_content_block($module) ?>
              <?= private_note_block($module) ?>
              <div class="button-row intro-button-row">
                <a class="btn secondary" href="<?= e(page_url($previous)) ?>">&larr; Back to Overview</a>
                <a class="btn" href="<?= e(page_url($next)) ?>"><?= e(module_next_label($modules, $index, $next)) ?> &rarr;</a>
              </div>
            </section>
          </main>
      <?php else: ?>
          <?php $moduleReflections = get_module_reflections($module['id']); ?>
          <main class="content training-layout">
            <?= side_nav($modules, $module['slug']) ?>
            <section class="module-main">
              <p class="eyebrow"><?= e($module['module_type']) ?></p>
              <h2><?= e($module['title']) ?></h2>
              <div class="module-grid">
                <?= video_block($module, $currentProgress) ?>
                <aside class="info-panel">
                  <strong>Estimated time</strong>
                  <div class="meta-row">Time: <?= e($module['time_label']) ?></div>
                  <?= video_progress_summary($module, $currentProgress) ?>
                  <strong>Learning Focus</strong>
                  <?= focus_list($module['focus']) ?>
                  <?php if (!empty($module['key_question'])): ?>
                    <strong>Key Question</strong>
                    <p><?= e($module['key_question']) ?></p>
                  <?php endif; ?>
                </aside>
              </div>
              <?= module_content_block($module) ?>
              <?= private_note_block($module) ?>
              <form class="reflection open" action="reflection_submit.php" method="post" data-reflection-form>
                <span class="reflection-icon">...</span>
                <div>
                  <strong>Comments</strong>
                  <p><?= e(isset($module['reflection_prompt']) ? $module['reflection_prompt'] : '') ?></p>
                  <?php if ($moduleReflections): ?>
                    <div class="reflection-list" aria-label="Submitted comments">
                      <?php foreach ($moduleReflections as $reflection): ?>
                        <article class="reflection-item">
                          <strong><?= e($module['module_type']) ?>: <?= e($module['title']) ?></strong>
                          <p><?= e($reflection['reflection_text']) ?></p>
                          <span><?= e($reflection['created_at']) ?></span>
                        </article>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <textarea name="reflection_text" aria-label="Comment for <?= e($module['title']) ?>" placeholder="Add a public comment for this lesson" maxlength="2000"></textarea>
                  <label class="reflection-challenge">
                    <span>Solve this: <?= e(reflection_challenge_for_module($module['id'])) ?></span>
                    <input type="text" name="challenge_answer" inputmode="numeric" autocomplete="off" maxlength="3" required>
                  </label>
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="module_id" value="<?= e((string) $module['id']) ?>">
                  <input type="hidden" name="started_at" value="<?= e((string) time()) ?>">
                  <input class="bot-field" type="text" name="website" value="" tabindex="-1" autocomplete="off">
                </div>
                <button class="btn secondary" type="submit">Publish Comment</button>
              </form>
              <div class="button-row">
                <a class="btn secondary" href="<?= e(page_url($previous)) ?>">&larr; Back to <?= e(module_back_label($modules, $index)) ?></a>
                <a class="btn" href="<?= e(page_url($next)) ?>"><?= e(module_next_label($modules, $index, $next)) ?> &rarr;</a>
              </div>
            </section>
          </main>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <script src="assets/site.js?v=20260514-mp4-local-resume"></script>
  </body>
</html>

