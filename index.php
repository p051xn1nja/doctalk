<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/tasks.json';
const MAX_TITLE_LENGTH = 120;

configureSession();
session_start();
applySecurityHeaders();

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function configureSession(): void
{
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
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
        $title = isset($task['title']) ? (string) $task['title'] : '';
        $done = !empty($task['done']);
        $createdAt = isset($task['created_at']) ? (string) $task['created_at'] : '';

        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            continue;
        }

        $title = sanitizeTaskTitle($title);
        if ($title === '') {
            continue;
        }

        $tasks[] = [
            'id' => $id,
            'title' => $title,
            'done' => $done,
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

function redirectHome(): void
{
    header('Location: /index.php', true, 303);
    exit;
}

function sanitizeTaskTitle(string $title): string
{
    $title = trim($title);
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title) ?? '';

    if ($title === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($title, 0, MAX_TITLE_LENGTH);
    }

    return substr($title, 0, MAX_TITLE_LENGTH);
}

function verifyCsrfToken(?string $submittedToken): bool
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }

    if (!is_string($submittedToken)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $submittedToken);
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

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    try {
        if ($action === 'add') {
            $title = sanitizeTaskTitle((string) ($_POST['title'] ?? ''));

            if ($title !== '') {
                $tasks[] = [
                    'id' => bin2hex(random_bytes(12)),
                    'title' => $title,
                    'done' => false,
                    'created_at' => gmdate('c'),
                ];
                saveTasks($tasks);
            }

            redirectHome();
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

            redirectHome();
        }

        if ($action === 'delete') {
            $id = (string) ($_POST['id'] ?? '');
            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
                $tasks = array_values(array_filter($tasks, static fn(array $task): bool => ($task['id'] ?? '') !== $id));
                saveTasks($tasks);
            }

            redirectHome();
        }

        http_response_code(400);
        echo 'Invalid action';
        exit;
    } catch (Throwable $e) {
        $error = 'Could not update tasks. Please try again.';
    }
}

$completedCount = count(array_filter($tasks, static fn(array $task): bool => (bool) ($task['done'] ?? false)));
$totalCount = count($tasks);
$csrfToken = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TaskFlow</title>
  <style>
    :root {
      --bg: #0f172a;
      --card: #111827;
      --accent: #22c55e;
      --muted: #94a3b8;
      --text: #e2e8f0;
      --danger: #ef4444;
      --warn: #f59e0b;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      background: radial-gradient(circle at top, #1e293b, var(--bg));
      color: var(--text);
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      display: grid;
      place-items: center;
      padding: 24px;
    }

    .app {
      width: min(760px, 100%);
      background: color-mix(in srgb, var(--card) 92%, black 8%);
      border: 1px solid #1f2937;
      border-radius: 18px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, .35);
      padding: 24px;
    }

    h1 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: clamp(1.5rem, 1.25rem + 1.5vw, 2.2rem);
    }

    .meta {
      color: var(--muted);
      margin-bottom: 20px;
    }

    .error {
      border: 1px solid #92400e;
      background: #451a03;
      color: #fde68a;
      border-radius: 10px;
      padding: 10px 12px;
      margin-bottom: 14px;
    }

    form.row {
      display: flex;
      gap: 10px;
      margin-bottom: 20px;
    }

    input[type="text"] {
      flex: 1;
      background: #0b1220;
      color: var(--text);
      border: 1px solid #334155;
      border-radius: 12px;
      padding: 12px 14px;
      font-size: 1rem;
    }

    button {
      border: 0;
      border-radius: 12px;
      padding: 11px 14px;
      font-weight: 600;
      cursor: pointer;
    }

    .add-btn { background: var(--accent); color: #052e16; }
    .ghost-btn { background: #1e293b; color: var(--text); }
    .danger-btn { background: var(--danger); color: #fee2e2; }

    ul {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      gap: 10px;
    }

    li {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      background: #0b1220;
      border: 1px solid #1f2937;
      border-radius: 12px;
      padding: 10px;
    }

    .task-title {
      font-size: 1rem;
      line-height: 1.35;
      margin-right: auto;
      word-break: break-word;
    }

    .done {
      text-decoration: line-through;
      color: var(--muted);
    }

    .empty {
      color: var(--muted);
      text-align: center;
      padding: 22px;
      border: 1px dashed #334155;
      border-radius: 12px;
    }
  </style>
</head>
<body>
  <main class="app">
    <h1>TaskFlow</h1>
    <p class="meta">A hardened PHP to-do app. Completed <?= $completedCount; ?> / <?= $totalCount; ?> tasks.</p>

    <?php if ($error !== ''): ?>
      <div class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form class="row" method="post" autocomplete="off">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <label for="title" class="sr-only" hidden>New task</label>
      <input id="title" name="title" type="text" maxlength="120" placeholder="What needs to be done?" required>
      <button class="add-btn" type="submit">Add Task</button>
    </form>

    <?php if ($totalCount === 0): ?>
      <div class="empty">No tasks yet — add one above.</div>
    <?php else: ?>
      <ul>
        <?php foreach ($tasks as $task): ?>
          <li>
            <span class="task-title <?= !empty($task['done']) ? 'done' : ''; ?>"><?= htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
            <form method="post">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <button class="ghost-btn" type="submit"><?= !empty($task['done']) ? 'Undo' : 'Done'; ?></button>
            </form>
            <form method="post">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <button class="danger-btn" type="submit">Delete</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </main>
</body>
</html>
