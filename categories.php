<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/tasks.json';
const CATEGORY_FILE = DATA_DIR . '/categories.json';
const DEFAULT_CATEGORY_COLOR = '#64748b';

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

function sanitizeCategoryName(string $category): string
{
    $category = trim($category);
    $category = preg_replace('/[\x00-\x1F\x7F]/u', '', $category) ?? '';
    if ($category === '') {
        return '';
    }

    return function_exists('mb_substr') ? mb_substr($category, 0, 40) : substr($category, 0, 40);
}

function sanitizeCategoryColor(string $color): string
{
    $color = trim($color);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
        return strtolower($color);
    }

    return DEFAULT_CATEGORY_COLOR;
}

function lowerSafe(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function verifyCsrfToken(?string $submittedToken): bool
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || !is_string($submittedToken)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submittedToken);
}

function loadCategories(): array
{
    if (!file_exists(CATEGORY_FILE)) {
        return [];
    }

    $json = file_get_contents(CATEGORY_FILE);
    if ($json === false) {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $categories = [];
    foreach ($decoded as $category) {
        if (!is_array($category)) {
            continue;
        }

        $id = isset($category['id']) ? (string) $category['id'] : '';
        $name = isset($category['name']) ? sanitizeCategoryName((string) $category['name']) : '';
        $color = isset($category['color']) ? sanitizeCategoryColor((string) $category['color']) : DEFAULT_CATEGORY_COLOR;

        if (preg_match('/^[a-f0-9]{24}$/', $id) !== 1 || $name === '') {
            continue;
        }

        $categories[] = ['id' => $id, 'name' => $name, 'color' => $color];
    }

    return $categories;
}

function saveCategories(array $categories): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('Unable to create data directory.');
    }

    $payload = json_encode(array_values($categories), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tmpFile = CATEGORY_FILE . '.tmp';
    $bytes = file_put_contents($tmpFile, $payload, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('Unable to write temporary category file.');
    }

    @chmod($tmpFile, 0600);

    if (!rename($tmpFile, CATEGORY_FILE)) {
        @unlink($tmpFile);
        throw new RuntimeException('Unable to persist category file.');
    }

    @chmod(CATEGORY_FILE, 0600);
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
    return is_array($decoded) ? $decoded : [];
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

function redirectToCategories(): void
{
    header('Location: ' . appPath('categories.php'), true, 303);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

$error = '';
$categories = loadCategories();

if ($method === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    try {
        if ($action === 'addCategory') {
            $name = sanitizeCategoryName((string) ($_POST['category_name'] ?? ''));
            $color = sanitizeCategoryColor((string) ($_POST['category_color'] ?? ''));

            if ($name !== '') {
                $exists = false;
                foreach ($categories as $category) {
                    if (lowerSafe((string) $category['name']) === lowerSafe($name)) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $categories[] = ['id' => bin2hex(random_bytes(12)), 'name' => $name, 'color' => $color];
                    saveCategories($categories);
                }
            }

            redirectToCategories();
        }

        if ($action === 'editCategory') {
            $id = (string) ($_POST['category_id'] ?? '');
            $name = sanitizeCategoryName((string) ($_POST['category_name'] ?? ''));
            $color = sanitizeCategoryColor((string) ($_POST['category_color'] ?? ''));

            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1 && $name !== '') {
                $tasks = loadTasks();

                foreach ($categories as &$category) {
                    if (($category['id'] ?? '') === $id) {
                        $oldName = (string) ($category['name'] ?? '');
                        $category['name'] = $name;
                        $category['color'] = $color;

                        foreach ($tasks as &$task) {
                            if (($task['category_id'] ?? '') === $id || lowerSafe((string) ($task['category_name'] ?? '')) === lowerSafe($oldName)) {
                                $task['category_id'] = $id;
                                $task['category_name'] = $name;
                                $task['category_color'] = $color;
                            }
                        }
                        unset($task);
                        break;
                    }
                }
                unset($category);

                saveCategories($categories);
                saveTasks($tasks);
            }

            redirectToCategories();
        }

        if ($action === 'deleteCategory') {
            $id = (string) ($_POST['category_id'] ?? '');
            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
                $categories = array_values(array_filter($categories, static fn(array $category): bool => ($category['id'] ?? '') !== $id));
                saveCategories($categories);

                $tasks = loadTasks();
                foreach ($tasks as &$task) {
                    if (($task['category_id'] ?? '') === $id) {
                        $task['category_id'] = '';
                        $task['category_name'] = '';
                        $task['category_color'] = DEFAULT_CATEGORY_COLOR;
                    }
                }
                unset($task);
                saveTasks($tasks);
            }

            redirectToCategories();
        }

        http_response_code(400);
        echo 'Invalid action';
        exit;
    } catch (Throwable $e) {
        $error = 'Could not update categories. Please try again.';
    }
}

usort($categories, static fn(array $a, array $b): int => strcmp(lowerSafe((string) ($a['name'] ?? '')), lowerSafe((string) ($b['name'] ?? ''))));
$csrfToken = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TaskFlow Categories</title>
  <style>
    :root { --bg:#0f172a; --card:#111827; --accent:#22c55e; --muted:#94a3b8; --text:#e2e8f0; --danger:#ef4444; --info:#38bdf8; }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; background:radial-gradient(circle at top,#1e293b,var(--bg)); color:var(--text); font-family:Inter,system-ui,sans-serif; display:grid; place-items:center; padding:24px; }
    .app { width:min(900px,100%); background:color-mix(in srgb,var(--card) 92%,black 8%); border:1px solid #1f2937; border-radius:18px; box-shadow:0 20px 40px rgba(0,0,0,.35); padding:24px; }
    .top-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
    .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .error { border:1px solid #92400e; background:#451a03; color:#fde68a; border-radius:10px; padding:10px 12px; margin-bottom:14px; }
    input[type="text"], input[type="color"] { background:#0b1220; color:var(--text); border:1px solid #334155; border-radius:12px; padding:10px 12px; }
    input[type="text"] { flex:1; min-width:220px; }
    input[type="color"] { width:46px; height:40px; padding:4px; }
    .add-btn,.ghost-btn,.danger-btn { border:0; cursor:pointer; border-radius:12px; padding:10px 12px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
    .add-btn { background:var(--accent); color:#052e16; }
    .ghost-btn { background:#1e293b; color:var(--text); }
    .danger-btn { background:var(--danger); color:#fee2e2; }
    .list { display:grid; gap:10px; margin-top:14px; }
    .item { background:#0b1220; border:1px solid #1f2937; border-radius:12px; padding:12px; }
    .dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
  </style>
</head>
<body>
<main class="app">
  <div class="top-bar">
    <h1 style="margin:0;">Categories</h1>
    <a class="ghost-btn" href="<?= htmlspecialchars(appPath('index.php'), ENT_QUOTES, 'UTF-8'); ?>">Back to tasks</a>
  </div>

  <?php if ($error !== ''): ?>
    <div class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <form method="post" class="row" autocomplete="off">
    <input type="hidden" name="action" value="addCategory">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input name="category_name" type="text" maxlength="40" placeholder="New category name" required>
    <input name="category_color" type="color" value="<?= DEFAULT_CATEGORY_COLOR; ?>" title="Category color">
    <button class="add-btn" type="submit">Add category</button>
  </form>

  <section class="list">
    <?php foreach ($categories as $category): ?>
      <form class="item row" method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="category_id" value="<?= htmlspecialchars((string) $category['id'], ENT_QUOTES, 'UTF-8'); ?>">
        <span class="dot" style="background:<?= htmlspecialchars((string) $category['color'], ENT_QUOTES, 'UTF-8'); ?>"></span>
        <input name="category_name" type="text" maxlength="40" required value="<?= htmlspecialchars((string) $category['name'], ENT_QUOTES, 'UTF-8'); ?>">
        <input name="category_color" type="color" value="<?= htmlspecialchars((string) $category['color'], ENT_QUOTES, 'UTF-8'); ?>" title="Category color">
        <button class="ghost-btn" type="submit" name="action" value="editCategory">Save</button>
        <button class="danger-btn" type="submit" name="action" value="deleteCategory">Delete</button>
      </form>
    <?php endforeach; ?>
  </section>
</main>
</body>
</html>
