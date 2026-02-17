<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/tasks.json';
const CATEGORY_FILE = DATA_DIR . '/categories.json';
const CATEGORY_UPLOAD_DIR = DATA_DIR . '/category_uploads';
const DEFAULT_CATEGORY_COLOR = '#64748b';
const MAX_CATEGORY_FILES = 10;

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

        $files = [];
        if (isset($category['files']) && is_array($category['files'])) {
            foreach ($category['files'] as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $originalName = isset($file['name']) ? basename((string) $file['name']) : '';
                $stored = isset($file['stored']) ? (string) $file['stored'] : '';
                if ($originalName === '' || preg_match('/^cat_[a-f0-9]{24}_[a-f0-9]{12}\.[a-z0-9]+$/', $stored) !== 1) {
                    continue;
                }
                $files[] = [
                    'name' => $originalName,
                    'stored' => $stored,
                    'size' => isset($file['size']) ? (int) $file['size'] : 0,
                ];
            }
        }

        $categories[] = ['id' => $id, 'name' => $name, 'color' => $color, 'files' => array_slice($files, 0, MAX_CATEGORY_FILES)];
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

function deleteCategoryStoredFile(string $stored): void
{
    if (preg_match('/^cat_[a-f0-9]{24}_[a-f0-9]{12}\.[a-z0-9]+$/', $stored) !== 1) {
        return;
    }

    $path = CATEGORY_UPLOAD_DIR . '/' . $stored;
    if (is_file($path)) {
        @unlink($path);
    }
}

function storeCategoryUploads(array $fileInput, string $categoryId, int $slotsAvailable): array
{
    if ($slotsAvailable <= 0) {
        return [];
    }

    $errors = $fileInput['error'] ?? null;
    $tmpNames = $fileInput['tmp_name'] ?? null;
    $names = $fileInput['name'] ?? null;
    $sizes = $fileInput['size'] ?? null;

    if (!is_array($errors) || !is_array($tmpNames) || !is_array($names)) {
        return [];
    }

    if (!is_dir(CATEGORY_UPLOAD_DIR) && !mkdir(CATEGORY_UPLOAD_DIR, 0700, true) && !is_dir(CATEGORY_UPLOAD_DIR)) {
        throw new RuntimeException('Unable to create category upload directory.');
    }

    $allowedExtensions = ['docx', 'pdf', 'txt', 'md', 'xlsx', 'xls', 'ppt', 'pptx', 'zip', 'php', 'js', 'css', 'html', 'py'];
    $storedFiles = [];

    $count = min(count($errors), count($tmpNames), count($names));
    for ($i = 0; $i < $count; $i += 1) {
        if (count($storedFiles) >= $slotsAvailable) {
            break;
        }

        $error = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the uploaded files failed to upload.');
        }

        $tmp = (string) ($tmpNames[$i] ?? '');
        $originalName = basename((string) ($names[$i] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($tmp === '' || $originalName === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('One of the uploaded files has an unsupported type.');
        }

        $storedName = 'cat_' . $categoryId . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $destination = CATEGORY_UPLOAD_DIR . '/' . $storedName;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('Unable to store one of the uploaded files.');
        }

        @chmod($destination, 0600);

        $storedFiles[] = [
            'name' => $originalName,
            'stored' => $storedName,
            'size' => is_array($sizes) ? (int) ($sizes[$i] ?? 0) : 0,
        ];
    }

    return $storedFiles;
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

if (isset($_GET['download']) && is_string($_GET['download'])) {
    $requested = basename((string) $_GET['download']);
    foreach ($categories as $category) {
        $files = $category['files'] ?? [];
        if (!is_array($files)) {
            continue;
        }

        foreach ($files as $file) {
            if (!is_array($file) || ($file['stored'] ?? '') !== $requested) {
                continue;
            }

            $filePath = CATEGORY_UPLOAD_DIR . '/' . $requested;
            if (is_file($filePath)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . rawurlencode((string) ($file['name'] ?? 'attachment')) . '"');
                header('Content-Length: ' . (string) filesize($filePath));
                readfile($filePath);
                exit;
            }
        }
    }

    http_response_code(404);
    echo 'File not found';
    exit;
}

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
                    $newId = bin2hex(random_bytes(12));
                    $newFiles = [];
                    if (isset($_FILES['category_files']) && is_array($_FILES['category_files'])) {
                        $newFiles = storeCategoryUploads($_FILES['category_files'], $newId, MAX_CATEGORY_FILES);
                    }

                    $categories[] = ['id' => $newId, 'name' => $name, 'color' => $color, 'files' => $newFiles];
                    saveCategories($categories);
                }
            }

            redirectToCategories();
        }

        if ($action === 'editCategory') {
            $id = (string) ($_POST['category_id'] ?? '');
            $name = sanitizeCategoryName((string) ($_POST['category_name'] ?? ''));
            $color = sanitizeCategoryColor((string) ($_POST['category_color'] ?? ''));
            $deleteFiles = isset($_POST['delete_files']) && is_array($_POST['delete_files']) ? array_map('strval', $_POST['delete_files']) : [];

            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1 && $name !== '') {
                $tasks = loadTasks();

                foreach ($categories as &$category) {
                    if (($category['id'] ?? '') !== $id) {
                        continue;
                    }

                    $oldName = (string) ($category['name'] ?? '');
                    $category['name'] = $name;
                    $category['color'] = $color;

                    $existingFiles = isset($category['files']) && is_array($category['files']) ? $category['files'] : [];
                    $keptFiles = [];
                    foreach ($existingFiles as $file) {
                        if (!is_array($file)) {
                            continue;
                        }
                        $stored = (string) ($file['stored'] ?? '');
                        if ($stored !== '' && in_array($stored, $deleteFiles, true)) {
                            deleteCategoryStoredFile($stored);
                            continue;
                        }
                        $keptFiles[] = $file;
                    }

                    $availableSlots = MAX_CATEGORY_FILES - count($keptFiles);
                    if ($availableSlots > 0 && isset($_FILES['category_files']) && is_array($_FILES['category_files'])) {
                        $newFiles = storeCategoryUploads($_FILES['category_files'], $id, $availableSlots);
                        $keptFiles = array_merge($keptFiles, $newFiles);
                    }
                    $category['files'] = array_slice($keptFiles, 0, MAX_CATEGORY_FILES);

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
                unset($category);

                saveCategories($categories);
                saveTasks($tasks);
            }

            redirectToCategories();
        }

        if ($action === 'deleteCategory') {
            $id = (string) ($_POST['category_id'] ?? '');
            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
                foreach ($categories as $category) {
                    if (($category['id'] ?? '') !== $id) {
                        continue;
                    }

                    $files = $category['files'] ?? [];
                    if (is_array($files)) {
                        foreach ($files as $file) {
                            if (is_array($file)) {
                                deleteCategoryStoredFile((string) ($file['stored'] ?? ''));
                            }
                        }
                    }
                }

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
    .app { width:min(980px,100%); background:color-mix(in srgb,var(--card) 92%,black 8%); border:1px solid #1f2937; border-radius:18px; box-shadow:0 20px 40px rgba(0,0,0,.35); padding:24px; }
    .top-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
    .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .error { border:1px solid #92400e; background:#451a03; color:#fde68a; border-radius:10px; padding:10px 12px; margin-bottom:14px; }
    input[type="text"], input[type="color"], input[type="file"] { background:#0b1220; color:var(--text); border:1px solid #334155; border-radius:12px; padding:10px 12px; }
    input[type="text"] { flex:1; min-width:220px; }
    input[type="color"] { width:46px; height:40px; padding:4px; }
    input[type="file"]::file-selector-button { background:#1e293b; color:var(--text); border:0; border-radius:8px; padding:8px 10px; margin-right:10px; }
    .add-btn,.ghost-btn,.danger-btn { border:0; cursor:pointer; border-radius:12px; padding:10px 12px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
    .add-btn { background:var(--accent); color:#052e16; }
    .ghost-btn { background:#1e293b; color:var(--text); }
    .danger-btn { background:var(--danger); color:#fee2e2; }
    .list { display:grid; gap:10px; margin-top:14px; }
    .item { background:#0b1220; border:1px solid #1f2937; border-radius:12px; padding:12px; }
    .dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
    .file-list { margin:8px 0 0; padding:0; list-style:none; display:grid; gap:6px; }
    .file-list a { color:#7dd3fc; }
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

  <form method="post" class="row" autocomplete="off" enctype="multipart/form-data">
    <input type="hidden" name="action" value="addCategory">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input name="category_name" type="text" maxlength="40" placeholder="New category name" required>
    <input name="category_color" type="color" value="<?= DEFAULT_CATEGORY_COLOR; ?>" title="Category color">
    <input name="category_files[]" type="file" multiple accept=".docx,.pdf,.txt,.md,.xlsx,.xls,.ppt,.pptx,.zip,.php,.js,.css,.html,.py">
    <button class="add-btn" type="submit">Add category</button>
  </form>
  <p style="margin:8px 0 0;color:#94a3b8;">You can attach up to <?= MAX_CATEGORY_FILES; ?> files per category.</p>

  <section class="list">
    <?php foreach ($categories as $category): ?>
      <form class="item" method="post" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="category_id" value="<?= htmlspecialchars((string) $category['id'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="row">
          <span class="dot" style="background:<?= htmlspecialchars((string) $category['color'], ENT_QUOTES, 'UTF-8'); ?>"></span>
          <input name="category_name" type="text" maxlength="40" required value="<?= htmlspecialchars((string) $category['name'], ENT_QUOTES, 'UTF-8'); ?>">
          <input name="category_color" type="color" value="<?= htmlspecialchars((string) $category['color'], ENT_QUOTES, 'UTF-8'); ?>" title="Category color">
          <button class="ghost-btn" type="submit" name="action" value="editCategory">Save</button>
          <button class="danger-btn" type="submit" name="action" value="deleteCategory">Delete</button>
        </div>

        <?php $files = isset($category['files']) && is_array($category['files']) ? $category['files'] : []; ?>
        <?php if (count($files) > 0): ?>
          <ul class="file-list">
            <?php foreach ($files as $file): ?>
              <li>
                <label style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                  <input type="checkbox" name="delete_files[]" value="<?= htmlspecialchars((string) ($file['stored'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                  Delete
                  <a href="<?= htmlspecialchars(appPath('categories.php') . '?download=' . rawurlencode((string) ($file['stored'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($file['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a>
                  <span style="color:#94a3b8;">(<?= (int) (($file['size'] ?? 0) / 1024); ?> KB)</span>
                </label>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (count($files) < MAX_CATEGORY_FILES): ?>
          <div class="row" style="margin-top:8px;">
            <input name="category_files[]" type="file" multiple accept=".docx,.pdf,.txt,.md,.xlsx,.xls,.ppt,.pptx,.zip,.php,.js,.css,.html,.py">
          </div>
        <?php else: ?>
          <p style="margin:8px 0 0;color:#94a3b8;">Maximum of <?= MAX_CATEGORY_FILES; ?> files reached for this category.</p>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
  </section>
</main>
</body>
</html>
