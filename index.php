<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/tasks.json';
const MAX_TITLE_LENGTH = 120;
const MAX_DESCRIPTION_LENGTH = 1000;

configureSession();
session_start();
applySecurityHeaders();

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isAuthenticated()) {
    header('Location: ' . appPath('login.php'), true, 303);
    exit;
}

function configureSession(): void
{
    $basePath = appBasePath();

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $basePath === '' ? '/' : $basePath . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function appBasePath(): string
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($scriptName));

    if ($dir === '/' || $dir === '.') {
        return '';
    }

    return rtrim($dir, '/');
}

function appPath(string $targetFile): string
{
    $basePath = appBasePath();
    return ($basePath === '' ? '' : $basePath) . '/' . ltrim($targetFile, '/');
}

function applySecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function isAuthenticated(): bool
{
    return !empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function loadTasks(): array
{
    if (!file_exists(DATA_FILE)) {
        return [];
    }

    $json = file_get_contents(DATA_FILE);
    if ($json === false) {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $tasks = [];
    foreach ($decoded as $task) {
        if (!is_array($task)) {
            continue;
        }

        $id = isset($task['id']) ? (string) $task['id'] : '';
        $title = isset($task['title']) ? sanitizeTaskTitle((string) $task['title']) : '';
        $description = isset($task['description']) ? sanitizeTaskDescription((string) $task['description']) : '';
        $done = !empty($task['done']);
        $progress = isset($task['progress']) ? (int) $task['progress'] : ($done ? 100 : 0);
        $createdAt = isset($task['created_at']) ? (string) $task['created_at'] : '';

        if (!preg_match('/^[a-f0-9]{24}$/', $id) || $title === '') {
            continue;
        }

        $progress = max(0, min(100, $progress));
        if ($done && $progress < 100) {
            $progress = 100;
        }

        $tasks[] = [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'done' => $done,
            'progress' => $progress,
            'created_at' => $createdAt,
        ];
    }

    return $tasks;
}

function saveTasks(array $tasks): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('Unable to create data directory.');
    }

    $payload = json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $tmpFile = DATA_FILE . '.tmp';
    $bytes = file_put_contents($tmpFile, $payload, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('Unable to write temporary task file.');
    }

    @chmod($tmpFile, 0600);

    if (!rename($tmpFile, DATA_FILE)) {
        @unlink($tmpFile);
        throw new RuntimeException('Unable to persist task file.');
    }

    @chmod(DATA_FILE, 0600);
}

function redirectToIndex(?string $search = null): void
{
    $path = appPath('index.php');
    if ($search !== null && $search !== '') {
        $path .= '?q=' . rawurlencode($search);
    }

    header('Location: ' . $path, true, 303);
    exit;
}

function sanitizeTaskTitle(string $title): string
{
    $title = trim($title);
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title) ?? '';

    if ($title === '') {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($title, 0, MAX_TITLE_LENGTH) : substr($title, 0, MAX_TITLE_LENGTH);
}

function sanitizeTaskDescription(string $description): string
{
    $description = trim($description);
    $description = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $description) ?? '';

    return function_exists('mb_substr') ? mb_substr($description, 0, MAX_DESCRIPTION_LENGTH) : substr($description, 0, MAX_DESCRIPTION_LENGTH);
}

function verifyCsrfToken(?string $submittedToken): bool
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || !is_string($submittedToken)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $submittedToken);
}


function lowerSafe(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function containsSafe(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    if (function_exists('str_contains')) {
        return str_contains($haystack, $needle);
    }

    return strpos($haystack, $needle) !== false;
}

function groupedByDay(array $tasks): array
{
    $groups = [];

    foreach ($tasks as $task) {
        $timestamp = strtotime((string) ($task['created_at'] ?? ''));
        $key = $timestamp !== false ? gmdate('Y-m-d', $timestamp) : 'Unknown day';
        $label = $timestamp !== false ? gmdate('l, M d, Y', $timestamp) : 'Unknown day';

        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $label, 'tasks' => []];
        }

        $groups[$key]['tasks'][] = $task;
    }

    uksort($groups, static function (string $a, string $b): int {
        if ($a === 'Unknown day') {
            return 1;
        }
        if ($b === 'Unknown day') {
            return -1;
        }

        return strcmp($b, $a);
    });

    return $groups;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

$tasks = loadTasks();
$error = '';
$searchQuery = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
$editId = (string) ($_GET['edit'] ?? $_POST['edit'] ?? '');
if (preg_match('/^[a-f0-9]{24}$/', $editId) !== 1) {
    $editId = '';
}

if ($method === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        header('Location: ' . appPath('login.php'), true, 303);
        exit;
    }

    try {
        if ($action === 'add') {
            $title = sanitizeTaskTitle((string) ($_POST['title'] ?? ''));
            $description = sanitizeTaskDescription((string) ($_POST['description'] ?? ''));

            if ($title !== '') {
                $tasks[] = [
                    'id' => bin2hex(random_bytes(12)),
                    'title' => $title,
                    'description' => $description,
                    'done' => false,
                    'progress' => 0,
                    'created_at' => gmdate('c'),
                ];
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery);
        }

        if ($action === 'toggle') {
            $id = (string) ($_POST['id'] ?? '');
            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
                foreach ($tasks as &$task) {
                    if (($task['id'] ?? '') === $id) {
                        $task['done'] = !($task['done'] ?? false);
                        break;
                    }
                }
                unset($task);
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery);
        }

        if ($action === 'updateProgress') {
            $id = (string) ($_POST['id'] ?? '');
            $progress = isset($_POST['progress']) ? (int) $_POST['progress'] : 0;
            $progress = max(0, min(100, $progress));

            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
                foreach ($tasks as &$task) {
                    if (($task['id'] ?? '') === $id) {
                        $task['progress'] = $progress;
                        $task['done'] = $progress >= 100;
                        break;
                    }
                }
                unset($task);
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery);
        }

        if ($action === 'editTask') {
            $id = (string) ($_POST['id'] ?? '');
            $title = sanitizeTaskTitle((string) ($_POST['title'] ?? ''));
            $description = sanitizeTaskDescription((string) ($_POST['description'] ?? ''));
            $progress = isset($_POST['progress']) ? (int) $_POST['progress'] : null;
            if ($progress !== null) {
                $progress = max(0, min(100, $progress));
            }

            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1 && $title !== '') {
                foreach ($tasks as &$task) {
                    if (($task['id'] ?? '') === $id) {
                        $task['title'] = $title;
                        $task['description'] = $description;
                        if ($progress !== null) {
                            $task['progress'] = $progress;
                            $task['done'] = $progress >= 100;
                        }
                        break;
                    }
                }
                unset($task);
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery);
        }

        if ($action === 'delete') {
            $id = (string) ($_POST['id'] ?? '');
            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
                $tasks = array_values(array_filter($tasks, static fn(array $task): bool => ($task['id'] ?? '') !== $id));
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery);
        }

        http_response_code(400);
        echo 'Invalid action';
        exit;
    } catch (Throwable $e) {
        $error = 'Could not update tasks. Please try again.';
    }
}

$filteredTasks = array_values(array_filter($tasks, static function (array $task) use ($searchQuery): bool {
    if ($searchQuery === '') {
        return true;
    }

    $haystack = lowerSafe((string) (($task['title'] ?? '') . ' ' . ($task['description'] ?? '')));
    return containsSafe($haystack, lowerSafe($searchQuery));
}));

$completedCount = count(array_filter($tasks, static fn(array $task): bool => (bool) ($task['done'] ?? false)));
$totalCount = count($tasks);
$groups = groupedByDay($filteredTasks);
$csrfToken = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TaskFlow</title>
  <style>
    :root { --bg:#0f172a; --card:#111827; --accent:#22c55e; --muted:#94a3b8; --text:#e2e8f0; --danger:#ef4444; --info:#38bdf8; }
    * { box-sizing: border-box; }
    body { margin:0; min-height:100vh; background:radial-gradient(circle at top,#1e293b,var(--bg)); color:var(--text); font-family:Inter,system-ui,sans-serif; display:grid; place-items:center; padding:24px; }
    .app { width:min(900px,100%); background:color-mix(in srgb,var(--card) 92%,black 8%); border:1px solid #1f2937; border-radius:18px; box-shadow:0 20px 40px rgba(0,0,0,.35); padding:24px; }
    .top-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px; }
    h1 { margin:0; font-size:clamp(1.5rem,1.25rem + 1.5vw,2.2rem); }
    .meta { color:var(--muted); margin-bottom:12px; }
    .error { border:1px solid #92400e; background:#451a03; color:#fde68a; border-radius:10px; padding:10px 12px; margin-bottom:14px; }
    .search-row { display:flex; gap:10px; margin-bottom:12px; }
    .search-row input, .search-row button, .task-form input, .task-form textarea, .task-form button { font: inherit; }
    .search-row input { flex:1; }
    .task-form { display:grid; gap:10px; margin-bottom:20px; }
    .task-form-row { display:flex; gap:10px; }
    input[type="text"], textarea { width:100%; background:#0b1220; color:var(--text); border:1px solid #334155; border-radius:12px; padding:12px 14px; }
    textarea { min-height:88px; resize:vertical; }
    button { border:0; cursor:pointer; }
    .add-btn, .ghost-btn, .danger-btn, .logout-btn {
      border-radius:12px;
      padding:11px 14px;
      font-weight:600;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
    }
    .add-btn { background:var(--accent); color:#052e16; }
    .ghost-btn { background:#1e293b; color:var(--text); }
    .danger-btn { background:var(--danger); color:#fee2e2; }
    .logout-btn { background:var(--info); color:#082f49; }
    .day-group { margin-top:16px; }
    .day-heading { margin:0 0 8px; color:#cbd5e1; font-size:1rem; }
    ul { list-style:none; padding:0; margin:0; display:grid; gap:10px; }
    li { background:#0b1220; border:1px solid #1f2937; border-radius:12px; padding:12px; }
    .task-line { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
    .accordion-toggle { min-width:42px; padding:8px 10px; font-size:14px; line-height:1; }
    .task-details { margin-top:4px; display:none; }
    .task-details.is-open { display:block; }
    .task-title { font-size:1rem; line-height:1.35; margin-right:0; word-break:break-word; }
    .title-group { display:flex; align-items:center; gap:8px; margin-right:auto; min-width:0; }
    .task-percent { color:#cbd5e1; font-size:.9rem; min-width:48px; text-align:right; }
    .desc { color:#cbd5e1; margin:8px 0; white-space:pre-wrap; }
    .done { text-decoration:line-through; color:var(--muted); }
    .progress-wrap { display:flex; align-items:center; gap:10px; margin:8px 0; }
    progress { width:100%; height:12px; }
    .slider-form { display:flex; align-items:center; gap:8px; margin-top:8px; }
    .slider-form input[type="range"] { flex:1; }
    .empty { color:var(--muted); text-align:center; padding:22px; border:1px dashed #334155; border-radius:12px; }
  </style>
</head>
<body>
  <main class="app">
    <div class="top-bar">
      <h1>TaskFlow</h1>
      <form method="post">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <button class="logout-btn" type="submit">Log out</button>
      </form>
    </div>

    <p class="meta">Completed <?= $completedCount; ?> / <?= $totalCount; ?> tasks.</p>

    <?php if ($error !== ''): ?>
      <div class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form class="search-row" method="get" autocomplete="off">
      <input name="q" type="text" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by title or description">
      <button class="ghost-btn" type="submit">Search</button>
      <?php if ($searchQuery !== ''): ?>
        <a class="ghost-btn" style="text-decoration:none;display:inline-flex;align-items:center;" href="<?= htmlspecialchars(appPath('index.php'), ENT_QUOTES, 'UTF-8'); ?>">Clear</a>
      <?php endif; ?>
    </form>

    <form class="task-form" method="post" autocomplete="off">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input name="title" type="text" maxlength="120" placeholder="Task title" required>
      <textarea name="description" maxlength="1000" placeholder="Task description (optional)"></textarea>
      <div class="task-form-row"><button class="add-btn" type="submit">Add Task</button></div>
    </form>

    <?php if (count($filteredTasks) === 0): ?>
      <div class="empty"><?= $searchQuery === '' ? 'No tasks yet — add one above.' : 'No tasks match your search.'; ?></div>
    <?php else: ?>
      <?php foreach ($groups as $group): ?>
        <section class="day-group">
          <h2 class="day-heading"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'); ?></h2>
          <ul>
            <?php foreach ($group['tasks'] as $task): ?>
              <?php $isEditing = $editId !== '' && $editId === (string) ($task['id'] ?? ''); ?>
              <li class="task-item">
                <div class="task-line">
                  <span class="title-group">
                    <span class="task-title <?= !empty($task['done']) ? 'done' : ''; ?>"><?= htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    <button class="ghost-btn accordion-toggle js-details-toggle" type="button" aria-expanded="<?= $isEditing ? 'true' : 'false'; ?>">Details <?= $isEditing ? '▴' : '▾'; ?></button>
                  </span>
                  <span class="task-percent"><?= (int) ($task['progress'] ?? 0); ?>%</span>
                  <form method="get">
                    <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <input type="hidden" name="edit" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <button class="ghost-btn" type="submit">Edit</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <button class="ghost-btn" type="submit"><?= !empty($task['done']) ? 'Undo' : 'Done'; ?></button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <button class="danger-btn" type="submit">Delete</button>
                  </form>
                </div>

                <?php if ($isEditing): ?>
                  <form class="task-form js-edit-form" method="post" autocomplete="off" data-cancel-url="<?= htmlspecialchars($searchQuery === '' ? appPath('index.php') : appPath('index.php') . '?q=' . rawurlencode($searchQuery), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="editTask">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <input name="title" type="text" maxlength="120" required value="<?= htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <textarea name="description" maxlength="1000" placeholder="Task description (optional)"><?= htmlspecialchars((string) ($task['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>

                    <div class="task-details js-task-details is-open">
                      <?php if (($task['description'] ?? '') !== ''): ?>
                        <p class="desc"><?= htmlspecialchars((string) $task['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <?php endif; ?>
                      <div class="slider-form">
                        <input class="js-progress-slider" type="range" name="progress" min="0" max="100" step="1" value="<?= (int) ($task['progress'] ?? 0); ?>">
                        <strong class="js-progress-value"><?= (int) ($task['progress'] ?? 0); ?>%</strong>
                      </div>
                    </div>

                    <div class="task-form-row">
                      <button class="add-btn" type="submit">Save changes</button>
                      <a class="danger-btn js-cancel-edit" href="<?= htmlspecialchars($searchQuery === '' ? appPath('index.php') : appPath('index.php') . '?q=' . rawurlencode($searchQuery), ENT_QUOTES, 'UTF-8'); ?>">Cancel</a>
                    </div>
                  </form>
                <?php else: ?>
                  <div class="task-details js-task-details">
                    <?php if (($task['description'] ?? '') !== ''): ?>
                      <p class="desc"><?= htmlspecialchars((string) $task['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>

                    <form class="slider-form" method="post">
                      <input type="hidden" name="action" value="updateProgress">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                      <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                      <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                      <input class="js-progress-slider" type="range" name="progress" min="0" max="100" step="1" value="<?= (int) ($task['progress'] ?? 0); ?>">
                      <strong class="js-progress-value"><?= (int) ($task['progress'] ?? 0); ?>%</strong>
                      <button class="ghost-btn" type="submit">Set progress</button>
                    </form>
                  </div>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <script src="<?= htmlspecialchars(appPath('app.js') . '?v=' . (string) @filemtime(__DIR__ . '/app.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
