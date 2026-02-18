<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const DATA_FILE = DATA_DIR . '/tasks.json';
const CATEGORY_FILE = DATA_DIR . '/categories.json';
const MAX_TITLE_LENGTH = 120;
const MAX_DESCRIPTION_LENGTH = 1000;
const DEFAULT_PER_PAGE = 50;
const MAX_PER_PAGE = 1000;
const UPLOAD_DIR = DATA_DIR . '/uploads';
const DEFAULT_CATEGORY_COLOR = '#64748b';
const MAX_TASK_FILES = 10;
const MAX_UPLOAD_FILE_SIZE_BYTES = 26214400; // 25 MB per file
const SESSION_LIFETIME = 86400; // 24 hours

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

    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
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
        $categoryId = isset($task['category_id']) && preg_match('/^[a-f0-9]{24}$/', (string) $task['category_id']) === 1 ? (string) $task['category_id'] : '';
        $categoryName = isset($task['category_name']) ? sanitizeCategoryName((string) $task['category_name']) : '';
        $categoryColor = isset($task['category_color']) ? sanitizeCategoryColor((string) $task['category_color']) : DEFAULT_CATEGORY_COLOR;
        $attachments = [];
        if (isset($task['attachments']) && is_array($task['attachments'])) {
            foreach ($task['attachments'] as $attachmentItem) {
                if (!is_array($attachmentItem)) {
                    continue;
                }
                $attachmentName = isset($attachmentItem['name']) ? (string) $attachmentItem['name'] : '';
                $attachmentStored = isset($attachmentItem['stored']) ? (string) $attachmentItem['stored'] : '';
                if ($attachmentName !== '' && preg_match('/^[a-f0-9]{24}_[a-f0-9]{12}\.[a-z0-9]+$/', $attachmentStored) === 1) {
                    $attachments[] = [
                        'name' => $attachmentName,
                        'stored' => $attachmentStored,
                        'size' => isset($attachmentItem['size']) ? (int) $attachmentItem['size'] : 0,
                    ];
                }
            }
        } elseif (isset($task['attachment']) && is_array($task['attachment'])) {
            $attachmentName = isset($task['attachment']['name']) ? (string) $task['attachment']['name'] : '';
            $attachmentStored = isset($task['attachment']['stored']) ? (string) $task['attachment']['stored'] : '';
            if ($attachmentName !== '' && preg_match('/^[a-f0-9]{24}_[a-f0-9]{12}\.[a-z0-9]+$/', $attachmentStored) === 1) {
                $attachments[] = [
                    'name' => $attachmentName,
                    'stored' => $attachmentStored,
                    'size' => isset($task['attachment']['size']) ? (int) $task['attachment']['size'] : 0,
                ];
            }
        }
        $attachments = array_slice($attachments, 0, MAX_TASK_FILES);

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
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'category_color' => $categoryColor,
            'attachments' => $attachments,
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

        $categories[] = [
            'id' => $id,
            'name' => $name,
            'color' => $color,
        ];
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

function findCategoryById(array $categories, string $categoryId): ?array
{
    foreach ($categories as $category) {
        if (($category['id'] ?? '') === $categoryId) {
            return $category;
        }
    }

    return null;
}

function redirectToIndex(string $search = '', int $page = 1, int $perPage = DEFAULT_PER_PAGE, string $editId = '', string $categoryFilter = '', string $fromDate = '', string $toDate = '', string $statusFilter = ''): void
{
    header('Location: ' . buildIndexUrl($search, $page, $perPage, $editId, $categoryFilter, $fromDate, $toDate, $statusFilter), true, 303);
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

function normalizeDateFilter(string $date): string
{
    $date = trim($date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
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

function renderDescriptionHtml(string $description): string
{
    $escaped = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $escaped) ?? $escaped;
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/^##\s+(.+)$/m', '<h4>$1</h4>', $escaped) ?? $escaped;
    $escaped = preg_replace('/^>\s+(.+)$/m', '<blockquote>$1</blockquote>', $escaped) ?? $escaped;

    $escaped = preg_replace_callback('/\[(.*?)\]\((https?:\/\/[^)\s]+)\)/', static function (array $match): string {
        $label = $match[1];
        $url = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $match[0];
        }

        $safeHref = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $safeHref . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
    }, $escaped) ?? $escaped;

    return nl2br($escaped);
}

function groupedByYearMonthDay(array $tasks): array
{
    $groups = [];

    foreach ($tasks as $task) {
        $timestamp = strtotime((string) ($task['created_at'] ?? ''));

        if ($timestamp === false) {
            $yearKey = 'Unknown year';
            $yearLabel = 'Unknown year';
            $monthKey = 'Unknown month';
            $monthLabel = 'Unknown month';
            $dayKey = 'Unknown day';
            $dayLabel = 'Unknown day';
        } else {
            $yearKey = gmdate('Y', $timestamp);
            $yearLabel = $yearKey;
            $monthKey = gmdate('Y-m', $timestamp);
            $monthLabel = gmdate('F Y', $timestamp);
            $dayKey = gmdate('Y-m-d', $timestamp);
            $dayLabel = gmdate('l, M d, Y', $timestamp);
        }

        if (!isset($groups[$yearKey])) {
            $groups[$yearKey] = ['label' => $yearLabel, 'months' => []];
        }

        if (!isset($groups[$yearKey]['months'][$monthKey])) {
            $groups[$yearKey]['months'][$monthKey] = ['label' => $monthLabel, 'days' => []];
        }

        if (!isset($groups[$yearKey]['months'][$monthKey]['days'][$dayKey])) {
            $groups[$yearKey]['months'][$monthKey]['days'][$dayKey] = ['label' => $dayLabel, 'tasks' => []];
        }

        $groups[$yearKey]['months'][$monthKey]['days'][$dayKey]['tasks'][] = $task;
    }

    uksort($groups, static function (string $a, string $b): int {
        if ($a === 'Unknown year') {
            return 1;
        }
        if ($b === 'Unknown year') {
            return -1;
        }

        return strcmp($b, $a);
    });

    foreach ($groups as &$yearGroup) {
        uksort($yearGroup['months'], static function (string $a, string $b): int {
            if ($a === 'Unknown month') {
                return 1;
            }
            if ($b === 'Unknown month') {
                return -1;
            }

            return strcmp($b, $a);
        });

        foreach ($yearGroup['months'] as &$monthGroup) {
            uksort($monthGroup['days'], static function (string $a, string $b): int {
                if ($a === 'Unknown day') {
                    return 1;
                }
                if ($b === 'Unknown day') {
                    return -1;
                }

                return strcmp($b, $a);
            });
        }
        unset($monthGroup);
    }
    unset($yearGroup);

    return $groups;
}

function tasksContainEditId(array $tasks, string $editId): bool
{
    if ($editId === '') {
        return false;
    }

    foreach ($tasks as $task) {
        if ($editId === (string) ($task['id'] ?? '')) {
            return true;
        }
    }

    return false;
}


function parsePerPage(array $source): int
{
    $raw = isset($source['per_page']) ? (string) $source['per_page'] : '';
    $custom = isset($source['per_page_custom']) ? (string) $source['per_page_custom'] : '';

    if ($raw === 'custom') {
        $raw = $custom;
    }

    $allowed = [25, 50, 100, 200];
    $value = (int) $raw;

    if (in_array($value, $allowed, true)) {
        return $value;
    }

    if ($value > 0) {
        return min(MAX_PER_PAGE, $value);
    }

    return DEFAULT_PER_PAGE;
}

function buildDownloadUrl(string $searchQuery, int $page, int $perPage, string $storedName, string $categoryFilter = '', string $fromDate = '', string $toDate = '', string $statusFilter = ''): string
{
    $base = buildIndexUrl($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter);
    $joiner = strpos($base, '?') === false ? '?' : '&';
    return $base . $joiner . 'download=' . rawurlencode($storedName);
}

function buildIndexUrl(string $searchQuery, int $page, int $perPage, string $editId = '', string $categoryFilter = '', string $fromDate = '', string $toDate = '', string $statusFilter = ''): string
{
    $params = [];
    if ($searchQuery !== '') {
        $params['q'] = $searchQuery;
    }
    if ($page > 1) {
        $params['page'] = (string) $page;
    }
    if ($perPage !== DEFAULT_PER_PAGE) {
        $params['per_page'] = (string) $perPage;
    }
    if ($editId !== '') {
        $params['edit'] = $editId;
    }
    if ($categoryFilter !== '') {
        $params['category'] = $categoryFilter;
    }
    if ($fromDate !== '') {
        $params['from'] = $fromDate;
    }
    if ($toDate !== '') {
        $params['to'] = $toDate;
    }
    if ($statusFilter !== '') {
        $params['status'] = $statusFilter;
    }

    $url = appPath('index.php');
    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}


function deleteStoredAttachment(string $stored): void
{
    if (preg_match('/^[a-f0-9]{24}_[a-f0-9]{12}\.[a-z0-9]+$/', $stored) !== 1) {
        return;
    }

    $path = UPLOAD_DIR . '/' . $stored;
    if (is_file($path)) {
        @unlink($path);
    }
}

function storeUploadedAttachments(array $fileInput, string $taskId, int $slotsAvailable): array
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

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0700, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Unable to create upload directory.');
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
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('One of the uploaded files exceeds the 25 MB limit.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the uploaded files failed to upload.');
        }

        $tmp = (string) ($tmpNames[$i] ?? '');
        $originalName = basename((string) ($names[$i] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $fileSize = is_array($sizes) ? (int) ($sizes[$i] ?? 0) : 0;

        if ($tmp === '' || $originalName === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('One of the uploaded files has an unsupported type.');
        }

        if ($fileSize <= 0 || $fileSize > MAX_UPLOAD_FILE_SIZE_BYTES) {
            throw new RuntimeException('One of the uploaded files exceeds the 25 MB limit.');
        }

        $storedName = $taskId . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $destination = UPLOAD_DIR . '/' . $storedName;

        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('Unable to store one of the uploaded files.');
        }

        @chmod($destination, 0600);

        $storedFiles[] = [
            'name' => $originalName,
            'stored' => $storedName,
            'size' => $fileSize,
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

$tasks = loadTasks();
$categories = loadCategories();
$error = '';
$searchQuery = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
$categoryFilter = (string) ($_GET['category'] ?? $_POST['category'] ?? '');
if (preg_match('/^[a-f0-9]{24}$/', $categoryFilter) !== 1) {
    $categoryFilter = '';
}
$fromDate = normalizeDateFilter((string) ($_GET['from'] ?? $_POST['from'] ?? ''));
$toDate = normalizeDateFilter((string) ($_GET['to'] ?? $_POST['to'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? $_POST['status'] ?? '');
if (!in_array($statusFilter, ['', 'in_progress', 'completed'], true)) {
    $statusFilter = '';
}
$perPage = parsePerPage($_GET + $_POST);
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : (isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1);
$editId = (string) ($_GET['edit'] ?? $_POST['edit'] ?? '');
if (preg_match('/^[a-f0-9]{24}$/', $editId) !== 1) {
    $editId = '';
}
if (isset($_GET['download']) && is_string($_GET['download'])) {
    $requested = basename((string) $_GET['download']);
    foreach ($tasks as $task) {
        $attachments = isset($task['attachments']) && is_array($task['attachments']) ? $task['attachments'] : [];
        if ($attachments === [] && isset($task['attachment']) && is_array($task['attachment'])) {
            $attachments = [$task['attachment']];
        }

        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || ($attachment['stored'] ?? '') !== $requested) {
                continue;
            }

            $filePath = UPLOAD_DIR . '/' . $requested;
            if (is_file($filePath)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . rawurlencode((string) ($attachment['name'] ?? 'attachment')) . '"');
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



$categoryMapById = [];
$categoryMapByName = [];
foreach ($categories as $category) {
    $categoryMapById[(string) $category['id']] = $category;
    $categoryMapByName[lowerSafe((string) $category['name'])] = $category;
}

$categoriesChanged = false;
$tasksChangedForCategory = false;
foreach ($tasks as &$task) {
    $taskCategoryId = isset($task['category_id']) ? (string) $task['category_id'] : '';
    $taskCategoryName = sanitizeCategoryName((string) ($task['category_name'] ?? ''));
    $taskCategoryColor = sanitizeCategoryColor((string) ($task['category_color'] ?? DEFAULT_CATEGORY_COLOR));

    if ($taskCategoryId !== '' && isset($categoryMapById[$taskCategoryId])) {
        $ref = $categoryMapById[$taskCategoryId];
        if (($task['category_name'] ?? '') !== $ref['name'] || ($task['category_color'] ?? '') !== $ref['color']) {
            $task['category_name'] = $ref['name'];
            $task['category_color'] = $ref['color'];
            $tasksChangedForCategory = true;
        }
        continue;
    }

    if ($taskCategoryName === '') {
        if (($task['category_id'] ?? '') !== '') {
            $task['category_id'] = '';
            $tasksChangedForCategory = true;
        }
        continue;
    }

    $key = lowerSafe($taskCategoryName);
    if (!isset($categoryMapByName[$key])) {
        $newCategory = [
            'id' => bin2hex(random_bytes(12)),
            'name' => $taskCategoryName,
            'color' => $taskCategoryColor,
        ];
        $categories[] = $newCategory;
        $categoryMapById[$newCategory['id']] = $newCategory;
        $categoryMapByName[$key] = $newCategory;
        $categoriesChanged = true;
    }

    $resolved = $categoryMapByName[$key];
    $task['category_id'] = $resolved['id'];
    $task['category_name'] = $resolved['name'];
    $task['category_color'] = $resolved['color'];
    $tasksChangedForCategory = true;
}
unset($task);

if ($categoriesChanged) {
    saveCategories($categories);
}
if ($tasksChangedForCategory) {
    saveTasks($tasks);
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
            $categoryId = (string) ($_POST['category_id'] ?? '');
            $selectedCategory = preg_match('/^[a-f0-9]{24}$/', $categoryId) === 1 ? findCategoryById($categories, $categoryId) : null;

            if ($title !== '') {
                $taskId = bin2hex(random_bytes(12));
                $attachments = [];
                if (isset($_FILES['attachment']) && is_array($_FILES['attachment'])) {
                    $attachments = storeUploadedAttachments($_FILES['attachment'], $taskId, MAX_TASK_FILES);
                }

                $tasks[] = [
                    'id' => $taskId,
                    'title' => $title,
                    'description' => $description,
                    'done' => false,
                    'progress' => 0,
                    'created_at' => gmdate('c'),
                    'category_id' => $selectedCategory['id'] ?? '',
                    'category_name' => $selectedCategory['name'] ?? '',
                    'category_color' => $selectedCategory['color'] ?? DEFAULT_CATEGORY_COLOR,
                    'attachments' => $attachments,
                ];
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery, 1, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter);
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

            redirectToIndex($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter);
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

            redirectToIndex($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter);
        }

        if ($action === 'deleteAttachment') {
            $id = (string) ($_POST['id'] ?? '');
            $stored = (string) ($_POST['stored'] ?? '');

            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1 && $stored !== '') {
                foreach ($tasks as &$task) {
                    if (($task['id'] ?? '') !== $id) {
                        continue;
                    }

                    $attachments = isset($task['attachments']) && is_array($task['attachments']) ? $task['attachments'] : [];
                    if ($attachments === [] && is_array($task['attachment'] ?? null)) {
                        $attachments = [$task['attachment']];
                    }

                    $kept = [];
                    foreach ($attachments as $attachmentItem) {
                        if (!is_array($attachmentItem)) {
                            continue;
                        }

                        if ((string) ($attachmentItem['stored'] ?? '') === $stored) {
                            deleteStoredAttachment($stored);
                            continue;
                        }

                        $kept[] = $attachmentItem;
                    }

                    $task['attachments'] = $kept;
                    $task['attachment'] = null;
                    break;
                }
                unset($task);
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter);
        }

        if ($action === 'addAttachment') {
            $id = (string) ($_POST['id'] ?? '');

            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
                foreach ($tasks as &$task) {
                    if (($task['id'] ?? '') !== $id) {
                        continue;
                    }

                    $currentAttachments = isset($task['attachments']) && is_array($task['attachments']) ? $task['attachments'] : [];
                    if ($currentAttachments === [] && isset($task['attachment']) && is_array($task['attachment'])) {
                        $currentAttachments = [$task['attachment']];
                    }

                    $slotsAvailable = MAX_TASK_FILES - count($currentAttachments);
                    if ($slotsAvailable > 0 && isset($_FILES['attachment']) && is_array($_FILES['attachment'])) {
                        $newAttachments = storeUploadedAttachments($_FILES['attachment'], (string) ($task['id'] ?? $id), $slotsAvailable);
                        $currentAttachments = array_merge($currentAttachments, $newAttachments);
                    }

                    $task['attachments'] = array_slice($currentAttachments, 0, MAX_TASK_FILES);
                    $task['attachment'] = null;
                    break;
                }
                unset($task);
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter);
        }

        if ($action === 'editTask') {
            $id = (string) ($_POST['id'] ?? '');
            $title = sanitizeTaskTitle((string) ($_POST['title'] ?? ''));
            $description = sanitizeTaskDescription((string) ($_POST['description'] ?? ''));
            $categoryId = (string) ($_POST['category_id'] ?? '');
            $selectedCategory = preg_match('/^[a-f0-9]{24}$/', $categoryId) === 1 ? findCategoryById($categories, $categoryId) : null;
            $deleteAttachments = isset($_POST['delete_attachments']) && is_array($_POST['delete_attachments']) ? array_map('strval', $_POST['delete_attachments']) : [];
            $progress = isset($_POST['progress']) ? (int) $_POST['progress'] : null;
            if ($progress !== null) {
                $progress = max(0, min(100, $progress));
            }

            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1 && $title !== '') {
                foreach ($tasks as &$task) {
                    if (($task['id'] ?? '') !== $id) {
                        continue;
                    }

                    $task['title'] = $title;
                    $task['description'] = $description;
                    $task['category_id'] = $selectedCategory['id'] ?? '';
                    $task['category_name'] = $selectedCategory['name'] ?? '';
                    $task['category_color'] = $selectedCategory['color'] ?? DEFAULT_CATEGORY_COLOR;

                    if ($progress !== null) {
                        $task['progress'] = $progress;
                        $task['done'] = $progress >= 100;
                    }

                    $currentAttachments = isset($task['attachments']) && is_array($task['attachments']) ? $task['attachments'] : [];
                    if ($currentAttachments === [] && isset($task['attachment']) && is_array($task['attachment'])) {
                        $currentAttachments = [$task['attachment']];
                    }

                    $keptAttachments = [];
                    foreach ($currentAttachments as $attachmentItem) {
                        if (!is_array($attachmentItem)) {
                            continue;
                        }
                        $stored = (string) ($attachmentItem['stored'] ?? '');
                        if ($stored !== '' && in_array($stored, $deleteAttachments, true)) {
                            deleteStoredAttachment($stored);
                            continue;
                        }
                        $keptAttachments[] = $attachmentItem;
                    }

                    $slotsAvailable = MAX_TASK_FILES - count($keptAttachments);
                    if ($slotsAvailable > 0 && isset($_FILES['attachment']) && is_array($_FILES['attachment'])) {
                        $newAttachments = storeUploadedAttachments($_FILES['attachment'], (string) ($task['id'] ?? $id), $slotsAvailable);
                        $keptAttachments = array_merge($keptAttachments, $newAttachments);
                    }

                    $task['attachments'] = array_slice($keptAttachments, 0, MAX_TASK_FILES);
                    $task['attachment'] = null;

                    break;
                }
                unset($task);
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter);
        }


        if ($action === 'delete') {
            $id = (string) ($_POST['id'] ?? '');
            if (preg_match('/^[a-f0-9]{24}$/', $id) === 1) {
                foreach ($tasks as $task) {
                    if (($task['id'] ?? '') !== $id) {
                        continue;
                    }

                    $attachments = isset($task['attachments']) && is_array($task['attachments']) ? $task['attachments'] : [];
                    if ($attachments === [] && is_array($task['attachment'] ?? null)) {
                        $attachments = [$task['attachment']];
                    }

                    foreach ($attachments as $attachmentItem) {
                        if (is_array($attachmentItem)) {
                            deleteStoredAttachment((string) ($attachmentItem['stored'] ?? ''));
                        }
                    }
                }
                $tasks = array_values(array_filter($tasks, static fn(array $task): bool => ($task['id'] ?? '') !== $id));
                saveTasks($tasks);
            }

            redirectToIndex($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter);
        }

        http_response_code(400);
        echo 'Invalid action';
        exit;
    } catch (Throwable $e) {
        $error = 'Could not update tasks. Please try again.';
    }
}

$filteredTasks = array_values(array_filter($tasks, static function (array $task) use ($searchQuery, $categoryFilter, $fromDate, $toDate, $statusFilter): bool {
    if ($categoryFilter !== '' && (string) ($task['category_id'] ?? '') !== $categoryFilter) {
        return false;
    }

    $createdDate = '';
    $timestamp = strtotime((string) ($task['created_at'] ?? ''));
    if ($timestamp !== false) {
        $createdDate = gmdate('Y-m-d', $timestamp);
    }

    if ($fromDate !== '' && ($createdDate === '' || $createdDate < $fromDate)) {
        return false;
    }

    if ($toDate !== '' && ($createdDate === '' || $createdDate > $toDate)) {
        return false;
    }

    $isDone = (bool) ($task['done'] ?? false);
    if ($statusFilter === 'completed' && !$isDone) {
        return false;
    }
    if ($statusFilter === 'in_progress' && $isDone) {
        return false;
    }

    if ($searchQuery === '') {
        return true;
    }

    $haystack = lowerSafe((string) (($task['title'] ?? '') . ' ' . ($task['description'] ?? '')));
    return containsSafe($haystack, lowerSafe($searchQuery));
}));

$completedCount = count(array_filter($tasks, static fn(array $task): bool => (bool) ($task['done'] ?? false)));
$totalCount = count($tasks);
$totalFiltered = count($filteredTasks);
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pagedTasks = array_slice($filteredTasks, $offset, $perPage);
$groups = groupedByYearMonthDay($pagedTasks);
$isCustomPerPage = !in_array($perPage, [25, 50, 100, 200], true);
$categoryOptions = [];
foreach ($categories as $category) {
    $categoryOptions[] = [
        'id' => (string) ($category['id'] ?? ''),
        'name' => (string) ($category['name'] ?? ''),
        'color' => (string) ($category['color'] ?? DEFAULT_CATEGORY_COLOR),
    ];
}
usort($categoryOptions, static fn(array $a, array $b): int => strcmp(lowerSafe((string) ($a['name'] ?? '')), lowerSafe((string) ($b['name'] ?? ''))));
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
      --bg:#0b1220;
      --bg-soft:#0f172a;
      --card:#111827;
      --card-soft:#0b1220;
      --accent:#22c55e;
      --muted:#94a3b8;
      --text:#e2e8f0;
      --danger:#ef4444;
      --info:#38bdf8;
      --border:#273449;
      --focus:#7dd3fc;
      --radius:14px;
    }
    * { box-sizing: border-box; }
    body {
      margin:0;
      min-height:100vh;
      background:
        radial-gradient(1000px 500px at 8% -10%, rgba(56,189,248,.12), transparent 60%),
        radial-gradient(900px 460px at 92% -20%, rgba(34,197,94,.09), transparent 60%),
        linear-gradient(180deg, #0f172a, var(--bg));
      color:var(--text);
      font-family:Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
      padding:22px;
    }
    .app {
      width:min(1060px,100%);
      margin-inline:auto;
      background:linear-gradient(180deg, rgba(17,24,39,.96), rgba(11,18,32,.96));
      border:1px solid var(--border);
      border-radius:20px;
      box-shadow:0 18px 45px rgba(0,0,0,.32);
      padding:22px;
    }
    .top-bar {
      position:sticky;
      top:0;
      z-index:2;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
      margin:-4px -4px 14px;
      padding:10px;
      border-radius:12px;
      background:rgba(11,18,32,.8);
      backdrop-filter: blur(8px);
    }
    h1 { margin:0; font-size:clamp(1.45rem,1.22rem + 1.1vw,2rem); letter-spacing:.2px; }
    .meta { color:var(--muted); margin:0 0 14px; font-size:.96rem; }
    .error { border:1px solid #92400e; background:#451a03; color:#fde68a; border-radius:12px; padding:11px 13px; margin-bottom:14px; }

    .search-row,
    .task-form,
    .pager {
      background:rgba(15,23,42,.52);
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:12px;
    }

    .search-row { display:flex; gap:10px; margin-bottom:12px; flex-wrap:wrap; }
    .search-row input, .search-row button, .search-row select, .task-form input, .task-form select, .task-form textarea, .task-form button { font: inherit; }
    .search-row input { flex:1; min-width:220px; }
    .search-filter-pair { display:flex; gap:10px; align-items:center; flex:0 0 auto; min-width:360px; }
    .search-actions { display:flex; gap:10px; }
    .search-filter-pair select { flex:1; min-width:0; }
    .search-row-break { flex-basis:100%; height:0; }
    .date-field { position:relative; min-width:170px; flex:1; }
    .date-field input[type="date"] { padding-right:42px; }
    .date-open-btn { position:absolute; right:7px; top:50%; transform:translateY(-50%); border:0; background:transparent; color:#e2e8f0; cursor:pointer; font-size:18px; line-height:1; padding:2px 4px; }

    .task-form { display:grid; gap:10px; margin-bottom:20px; }
    .task-form-row { display:flex; gap:10px; flex-wrap:wrap; }

    input[type="text"], input[type="date"], select, input[type="file"], textarea {
      width:100%;
      background:var(--card-soft);
      color:var(--text);
      border:1px solid #334155;
      border-radius:12px;
      padding:10px 12px;
      transition:border-color .18s ease, box-shadow .18s ease, background-color .18s ease;
    }
    input[type="text"]:focus, input[type="date"]:focus, select:focus, textarea:focus {
      outline:none;
      border-color:var(--focus);
      box-shadow:0 0 0 3px rgba(56,189,248,.18);
      background:#0a1426;
    }
    input[type="date"] { color-scheme: dark; cursor: pointer; }
    input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(0.95) brightness(1.25); opacity: 1; cursor: pointer; }
    input[type="date"]::-webkit-datetime-edit { color: var(--text); }
    input[type="date"][value=""]::-webkit-datetime-edit { color: var(--muted); }
    input[type="file"]::file-selector-button { background:#1e293b; color:var(--text); border:0; border-radius:8px; padding:8px 10px; margin-right:10px; }
    input[type="color"] { width:44px; height:40px; border:1px solid #334155; border-radius:10px; background:#0b1220; padding:4px; }
    textarea { min-height:88px; resize:vertical; line-height:1.45; }
    .desc-editor { display:grid; gap:8px; }
    .desc-toolbar { display:flex; flex-wrap:wrap; gap:6px; }
    .desc-tool-btn { background:#1e293b; color:var(--text); border:1px solid #334155; border-radius:8px; padding:6px 10px; font-size:.85rem; }
    .task-row-action-btn { height:42px; min-height:42px; padding:0 14px; line-height:1; display:inline-flex; align-items:center; justify-content:center; }

    button { border:0; cursor:pointer; transition:transform .12s ease, filter .2s ease, background-color .2s ease; }
    button:hover { filter:brightness(1.05); }
    button:active { transform:translateY(1px); }
    .add-btn, .ghost-btn, .danger-btn, .logout-btn {
      border-radius:11px;
      padding:10px 14px;
      font-weight:600;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      white-space:nowrap;
    }
    .add-btn { background:var(--accent); color:#052e16; }
    .ghost-btn { background:#1e293b; color:var(--text); border:1px solid #334155; }
    .danger-btn { background:var(--danger); color:#fee2e2; }
    .logout-btn { background:var(--info); color:#082f49; }

    .year-group { margin-top:16px; }
    .year-heading, .month-heading, .day-heading { margin:0; color:#cbd5e1; font-size:1rem; }
    .month-heading { font-size:.98rem; }
    .day-heading { font-size:.95rem; }
    .year-header, .month-header, .day-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
    .year-toggle, .month-toggle, .day-toggle { background:#1e293b; color:var(--text); border-radius:10px; padding:6px 10px; border:1px solid #334155; }
    .year-months, .month-days, .day-tasks { display:none; }
    .year-months.is-open, .month-days.is-open, .day-tasks.is-open { display:grid; }
    .month-group { margin-top:10px; padding-left:10px; border-left:1px solid #334155; }
    .day-group { margin-top:8px; padding-left:10px; border-left:1px dashed #334155; }

    ul { list-style:none; padding:0; margin:0; display:grid; gap:10px; }
    li { background:linear-gradient(180deg, #0b1220, #0a1426); border:1px solid #24344a; border-radius:12px; padding:12px; }
    .task-line { display:flex; align-items:center; gap:8px; margin-bottom:8px; flex-wrap:wrap; }
    .accordion-toggle { min-width:42px; padding:8px 10px; font-size:14px; line-height:1; }
    .task-details { margin-top:4px; display:none; }
    .task-details.is-open { display:block; }
    .task-title { font-size:1rem; line-height:1.35; margin-right:0; word-break:break-word; }
    .title-group { display:flex; align-items:center; gap:8px; margin-right:auto; min-width:0; }
    .task-percent { color:#cbd5e1; font-size:.9rem; min-width:48px; text-align:right; }
    .category-badge { display:inline-flex; align-items:center; gap:6px; font-size:.78rem; padding:4px 8px; border-radius:999px; color:#e2e8f0; border:1px solid #334155; }
    .desc { color:#cbd5e1; margin:8px 0; white-space:pre-wrap; }
    .done { text-decoration:line-through; color:var(--muted); }
    .progress-wrap { display:flex; align-items:center; gap:10px; margin:8px 0; }
    progress { width:100%; height:12px; }
    .slider-form { display:flex; align-items:center; gap:8px; margin-top:8px; }
    .slider-form input[type="range"] { flex:1; }
    .empty { color:var(--muted); text-align:center; padding:22px; border:1px dashed #334155; border-radius:12px; background:rgba(15,23,42,.45); }

    .pager { display:flex; flex-direction:column; align-items:flex-start; gap:8px; margin-bottom:14px; }
    .pager-summary { font-weight:500; color:#cbd5e1; }
    .pager form { display:flex; align-items:center; gap:8px; flex-wrap:nowrap; }
    .pager input[type="number"], .pager select { background:#0b1220; color:var(--text); border:1px solid #334155; border-radius:10px; padding:8px 10px; }

    .task-attachment { margin-top:8px; color:#cbd5e1; font-size:.9rem; }
    .task-attachment a { color:#7dd3fc; }
    .selected-files { display:grid; gap:6px; margin-top:6px; }
    .selected-file { display:flex; align-items:center; justify-content:space-between; gap:8px; background:#0b1220; border:1px solid #334155; border-radius:10px; padding:8px 10px; color:#cbd5e1; font-size:.9rem; }
    .group-actions { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 12px; }

    a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
      outline:2px solid var(--focus);
      outline-offset:2px;
    }

    @media (max-width: 780px) {
      body { padding:14px; }
      .app { padding:14px; border-radius:16px; overflow:hidden; }
      .top-bar { position:static; margin:0 0 12px; padding:0; background:transparent; backdrop-filter:none; flex-wrap:wrap; }
      .top-bar > h1 { width:100%; }

      .search-row > input[type="text"],
      .search-filter-pair,
      .date-field,
      .search-actions,
      .search-actions .ghost-btn {
        width:100%;
      }
      .search-row input { min-width:0; }
      .search-filter-pair { min-width:0; display:grid; grid-template-columns:1fr 1fr; }
      .date-field { min-width:0; }
      .search-row-break { display:none; }

      .pager form, .task-form-row { width:100%; }
      .pager form { flex-wrap:wrap; }
      .task-line { align-items:flex-start; }
      .title-group { width:100%; }
    }
  </style>
</head>
<body>
  <main class="app">
    <div class="top-bar">
      <h1>TaskFlow</h1>
      <div style="display:flex;gap:10px;align-items:center;">
        <a class="ghost-btn" href="<?= htmlspecialchars(appPath('categories.php'), ENT_QUOTES, 'UTF-8'); ?>" style="text-decoration:none;">Categories</a>
      <form method="post">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <button class="logout-btn" type="submit">Log out</button>
      </form>
      </div>
    </div>

    <p class="meta">Completed <?= $completedCount; ?> / <?= $totalCount; ?> tasks.</p>

    <?php if ($error !== ''): ?>
      <div class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form class="search-row" method="get" autocomplete="off">
      <input name="q" type="text" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by title or description">
      <div class="search-filter-pair">
        <select name="category">
          <option value="">All categories</option>
          <?php foreach ($categoryOptions as $category): ?>
            <option value="<?= htmlspecialchars((string) $category['id'], ENT_QUOTES, 'UTF-8'); ?>" <?= $categoryFilter === (string) $category['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string) $category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status">
          <option value="" <?= $statusFilter === '' ? 'selected' : ''; ?>>All statuses</option>
          <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In progress</option>
          <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
        </select>
      </div>
      <span class="search-row-break" aria-hidden="true"></span>
      <label class="date-field">
        <input class="js-date-picker" name="from" type="date" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>">
        <button class="js-date-open date-open-btn" type="button" aria-label="Open from date calendar">📅</button>
      </label>
      <label class="date-field">
        <input class="js-date-picker" name="to" type="date" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>">
        <button class="js-date-open date-open-btn" type="button" aria-label="Open to date calendar">📅</button>
      </label>
      <input type="hidden" name="per_page" value="<?= (int) $perPage; ?>">
      <div class="search-actions">
        <button class="ghost-btn search-submit-btn" type="submit">Search</button>
        <?php if ($searchQuery !== '' || $categoryFilter !== '' || $statusFilter !== '' || $fromDate !== '' || $toDate !== ''): ?>
          <a class="ghost-btn search-clear-btn" style="text-decoration:none;display:inline-flex;align-items:center;" href="<?= htmlspecialchars(buildIndexUrl('', 1, $perPage), ENT_QUOTES, 'UTF-8'); ?>">Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="pager">
      <span class="pager-summary">Page <?= $page; ?> of <?= $totalPages; ?> (<?= $totalFiltered; ?> task<?= $totalFiltered === 1 ? '' : 's'; ?>)</span>
      <form method="get" autocomplete="off">
        <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        <?php if ($categoryFilter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        <?php if ($fromDate !== ''): ?><input type="hidden" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        <?php if ($toDate !== ''): ?><input type="hidden" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        <label for="per_page">Per page</label>
        <select id="per_page" name="per_page">
          <?php foreach ([25, 50, 100, 200] as $size): ?>
            <option value="<?= $size; ?>" <?= $perPage === $size ? 'selected' : ''; ?>><?= $size; ?></option>
          <?php endforeach; ?>
          <option value="custom" <?= $isCustomPerPage ? 'selected' : ''; ?>>Custom</option>
        </select>
        <input type="number" name="per_page_custom" min="1" max="<?= MAX_PER_PAGE; ?>" placeholder="Custom" value="<?= $isCustomPerPage ? (int) $perPage : 50; ?>">
        <button class="ghost-btn" type="submit">Apply</button>
        <?php if ($page > 1): ?>
          <a class="ghost-btn" style="text-decoration:none;" href="<?= htmlspecialchars(buildIndexUrl($searchQuery, $page - 1, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter), ENT_QUOTES, 'UTF-8'); ?>">Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="ghost-btn" style="text-decoration:none;" href="<?= htmlspecialchars(buildIndexUrl($searchQuery, $page + 1, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
        <?php endif; ?>
      </form>
    </div>

    <form class="task-form" method="post" autocomplete="off" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="page" value="<?= (int) $page; ?>">
      <input type="hidden" name="per_page" value="<?= (int) $perPage; ?>">
      <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
      <?php if ($categoryFilter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
      <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
      <?php if ($fromDate !== ''): ?><input type="hidden" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
      <?php if ($toDate !== ''): ?><input type="hidden" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
      <input name="title" type="text" maxlength="120" placeholder="Task title" required>
      <div class="desc-editor">
        <div class="desc-toolbar" role="toolbar" aria-label="Description formatting tools">
          <button class="desc-tool-btn" type="button" data-format-action="bold"><strong>B</strong></button>
          <button class="desc-tool-btn" type="button" data-format-action="italic"><em>I</em></button>
          <button class="desc-tool-btn" type="button" data-format-action="h2">H2</button>
          <button class="desc-tool-btn" type="button" data-format-action="ul">• List</button>
          <button class="desc-tool-btn" type="button" data-format-action="ol">1. List</button>
          <button class="desc-tool-btn" type="button" data-format-action="quote">Quote</button>
          <button class="desc-tool-btn" type="button" data-format-action="code">Code</button>
          <button class="desc-tool-btn" type="button" data-format-action="link">Link</button>
        </div>
        <textarea name="description" maxlength="1000" placeholder="Task description (optional)" class="js-format-description"></textarea>
      </div>
      <div class="task-form-row">
        <select name="category_id">
          <option value="">No category</option>
          <?php foreach ($categoryOptions as $category): ?>
            <option value="<?= htmlspecialchars((string) $category['id'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) $category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="task-form-row" style="gap:8px; align-items:center;">
        <button class="ghost-btn js-new-task-add-files" type="button">+ Add files</button>
      </div>
      <input id="new-task-attachments" class="js-new-task-attachments" name="attachment[]" type="file" multiple accept=".docx,.pdf,.txt,.md,.xlsx,.xls,.ppt,.pptx,.zip,.php,.js,.css,.html,.py" style="display:none;">
      <div id="new-task-selected-files" class="selected-files" aria-live="polite"></div>
      <small style="color:#94a3b8;">Optional: upload up to 10 files, each up to 25 MB.</small>
      <div class="task-form-row"><button class="add-btn" type="submit">Add Task</button></div>
    </form>

    <?php if (count($pagedTasks) > 0): ?>
      <div class="group-actions">
        <button class="ghost-btn js-expand-all-groups" type="button">Expand all</button>
        <button class="ghost-btn js-expand-current-month" type="button">Expand current month</button>
      </div>
    <?php endif; ?>

    <?php if (count($pagedTasks) === 0): ?>
      <div class="empty"><?= $searchQuery === '' ? 'No tasks yet — add one above.' : 'No tasks match your search.'; ?></div>
    <?php else: ?>
      <?php foreach ($groups as $yearKey => $yearGroup): ?>
        <?php $yearHasEditing = false; foreach ($yearGroup['months'] as $yearMonthForEdit) { foreach ($yearMonthForEdit['days'] as $yearDayForEdit) { if (tasksContainEditId($yearDayForEdit['tasks'], $editId)) { $yearHasEditing = true; break 2; } } } ?>
        <section class="year-group" data-year-key="<?= htmlspecialchars((string) $yearKey, ENT_QUOTES, "UTF-8"); ?>">
          <div class="year-header">
            <h2 class="year-heading"><?= htmlspecialchars($yearGroup['label'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <button class="year-toggle js-year-toggle" type="button" aria-expanded="<?= $yearHasEditing ? 'true' : 'false'; ?>">Year <?= $yearHasEditing ? '▴' : '▾'; ?></button>
          </div>
          <div class="year-months js-year-months <?= $yearHasEditing ? 'is-open' : ''; ?>">
            <?php foreach ($yearGroup['months'] as $monthKey => $monthGroup): ?>
              <?php $monthHasEditing = false; foreach ($monthGroup['days'] as $monthDayForEdit) { if (tasksContainEditId($monthDayForEdit['tasks'], $editId)) { $monthHasEditing = true; break; } } ?>
              <section class="month-group" data-month-key="<?= htmlspecialchars((string) $monthKey, ENT_QUOTES, "UTF-8"); ?>">
                <div class="month-header">
                  <h3 class="month-heading"><?= htmlspecialchars($monthGroup['label'], ENT_QUOTES, 'UTF-8'); ?></h3>
                  <button class="month-toggle js-month-toggle" type="button" aria-expanded="<?= $monthHasEditing ? 'true' : 'false'; ?>">Month <?= $monthHasEditing ? '▴' : '▾'; ?></button>
                </div>
                <div class="month-days js-month-days <?= $monthHasEditing ? 'is-open' : ''; ?>">
                  <?php foreach ($monthGroup['days'] as $dayGroup): ?>
                    <?php $dayHasEditing = tasksContainEditId($dayGroup['tasks'], $editId); ?>
                    <section class="day-group">
                      <div class="day-header">
                        <h4 class="day-heading"><?= htmlspecialchars($dayGroup['label'], ENT_QUOTES, 'UTF-8'); ?></h4>
                        <button class="day-toggle js-day-toggle" type="button" aria-expanded="<?= $dayHasEditing ? 'true' : 'false'; ?>">Day <?= $dayHasEditing ? '▴' : '▾'; ?></button>
                      </div>
                      <ul class="day-tasks js-day-tasks <?= $dayHasEditing ? 'is-open' : ''; ?>">
                        <?php foreach ($dayGroup['tasks'] as $task): ?>
              <?php $isEditing = $editId !== '' && $editId === (string) ($task['id'] ?? ''); ?>
              <li class="task-item">
                <div class="task-line">
                  <span class="title-group">
                    <span class="task-title <?= !empty($task['done']) ? 'done' : ''; ?>"><?= htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ((string) ($task['category_name'] ?? '') !== ''): ?>
                      <span class="category-badge" style="border-color: <?= htmlspecialchars((string) ($task['category_color'] ?? DEFAULT_CATEGORY_COLOR), ENT_QUOTES, 'UTF-8'); ?>;">
                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars((string) ($task['category_color'] ?? DEFAULT_CATEGORY_COLOR), ENT_QUOTES, 'UTF-8'); ?>;"></span>
                        <?= htmlspecialchars((string) $task['category_name'], ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                    <?php endif; ?>
                    <button class="ghost-btn accordion-toggle js-details-toggle" type="button" aria-expanded="<?= $isEditing ? 'true' : 'false'; ?>">Details <?= $isEditing ? '▴' : '▾'; ?></button>
                  </span>
                  <span class="task-percent"><?= (int) ($task['progress'] ?? 0); ?>%</span>
                  <form method="get">
                    <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($categoryFilter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($fromDate !== ''): ?><input type="hidden" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($toDate !== ''): ?><input type="hidden" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <input type="hidden" name="page" value="<?= (int) $page; ?>">
                    <input type="hidden" name="per_page" value="<?= (int) $perPage; ?>">
                    <input type="hidden" name="edit" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <button class="logout-btn task-row-action-btn" type="submit">Edit</button>
                  </form>
                  <form class="js-quick-attach-form" method="post" enctype="multipart/form-data" autocomplete="off" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="addAttachment">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($categoryFilter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($fromDate !== ''): ?><input type="hidden" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($toDate !== ''): ?><input type="hidden" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <input type="hidden" name="page" value="<?= (int) $page; ?>">
                    <input type="hidden" name="per_page" value="<?= (int) $perPage; ?>">
                    <button class="ghost-btn js-quick-add-files task-row-action-btn" type="button">+ Add files</button>
                    <button class="add-btn js-quick-upload-files" type="submit" style="display:none;">Upload files</button>
                    <input class="js-quick-task-attachments" name="attachment[]" type="file" multiple accept=".docx,.pdf,.txt,.md,.xlsx,.xls,.ppt,.pptx,.zip,.php,.js,.css,.html,.py" style="display:none;">
                    <div class="selected-files js-quick-selected-files" aria-live="polite" style="width:100%;"></div>
                  </form>
                  <form method="post">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($categoryFilter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($fromDate !== ''): ?><input type="hidden" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($toDate !== ''): ?><input type="hidden" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <input type="hidden" name="page" value="<?= (int) $page; ?>">
                    <input type="hidden" name="per_page" value="<?= (int) $perPage; ?>">
                    <button class="danger-btn task-row-action-btn" type="submit">Delete</button>
                  </form>
                </div>



                <?php if ($isEditing): ?>
                  <form class="task-form js-edit-form" method="post" autocomplete="off" enctype="multipart/form-data" data-cancel-url="<?= htmlspecialchars(buildIndexUrl($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="editTask">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($categoryFilter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($fromDate !== ''): ?><input type="hidden" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <?php if ($toDate !== ''): ?><input type="hidden" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <input type="hidden" name="page" value="<?= (int) $page; ?>">
                    <input type="hidden" name="per_page" value="<?= (int) $perPage; ?>">
                    <input name="title" type="text" maxlength="120" required value="<?= htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="desc-editor">
                      <div class="desc-toolbar" role="toolbar" aria-label="Description formatting tools">
                        <button class="desc-tool-btn" type="button" data-format-action="bold"><strong>B</strong></button>
                        <button class="desc-tool-btn" type="button" data-format-action="italic"><em>I</em></button>
                        <button class="desc-tool-btn" type="button" data-format-action="h2">H2</button>
                        <button class="desc-tool-btn" type="button" data-format-action="ul">• List</button>
                        <button class="desc-tool-btn" type="button" data-format-action="ol">1. List</button>
                        <button class="desc-tool-btn" type="button" data-format-action="quote">Quote</button>
                        <button class="desc-tool-btn" type="button" data-format-action="code">Code</button>
                        <button class="desc-tool-btn" type="button" data-format-action="link">Link</button>
                      </div>
                      <textarea name="description" maxlength="1000" placeholder="Task description (optional)" class="js-format-description"><?= htmlspecialchars((string) ($task['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="task-form-row">
                      <select name="category_id">
                        <option value="">No category</option>
                        <?php foreach ($categoryOptions as $category): ?>
                          <option value="<?= htmlspecialchars((string) $category['id'], ENT_QUOTES, 'UTF-8'); ?>" <?= ((string) ($task['category_id'] ?? '') === (string) $category['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="task-details js-task-details is-open">
                      <?php if (($task['description'] ?? '') !== ''): ?>
                        <p class="desc"><?= renderDescriptionHtml((string) $task['description']); ?></p>
                      <?php endif; ?>
                      <?php $taskAttachments = isset($task['attachments']) && is_array($task['attachments']) ? $task['attachments'] : (is_array($task['attachment'] ?? null) ? [$task['attachment']] : []); ?>
                      <?php if (count($taskAttachments) > 0): ?>
                        <?php foreach ($taskAttachments as $attachmentItem): ?>
                          <?php if (!is_array($attachmentItem)) { continue; } ?>
                          <label style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <input type="checkbox" name="delete_attachments[]" value="<?= htmlspecialchars((string) ($attachmentItem['stored'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"> Delete
                            <a class="task-attachment" href="<?= htmlspecialchars(buildDownloadUrl($searchQuery, $page, $perPage, (string) ($attachmentItem['stored'] ?? ''), $categoryFilter, $fromDate, $toDate, $statusFilter), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($attachmentItem['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a>
                          </label>
                        <?php endforeach; ?>
                      <?php endif; ?>
                      <div class="task-form-row" style="align-items:center;">
                        <button class="ghost-btn js-edit-add-files" type="button">+ Add files</button>
                      </div>
                      <input class="js-edit-task-attachments" name="attachment[]" type="file" multiple accept=".docx,.pdf,.txt,.md,.xlsx,.xls,.ppt,.pptx,.zip,.php,.js,.css,.html,.py" style="display:none;">
                      <div class="selected-files js-edit-selected-files" aria-live="polite"></div>
                      <div class="slider-form">
                        <input class="js-progress-slider" type="range" name="progress" min="0" max="100" step="1" value="<?= (int) ($task['progress'] ?? 0); ?>">
                        <strong class="js-progress-value"><?= (int) ($task['progress'] ?? 0); ?>%</strong>
                      </div>
                    </div>

                    <div class="task-form-row">
                      <button class="add-btn" type="submit">Save changes</button>
                      <a class="danger-btn js-cancel-edit" href="<?= htmlspecialchars(buildIndexUrl($searchQuery, $page, $perPage, '', $categoryFilter, $fromDate, $toDate, $statusFilter), ENT_QUOTES, 'UTF-8'); ?>">Cancel</a>
                    </div>
                  </form>
                <?php else: ?>
                  <div class="task-details js-task-details">
                    <?php if (($task['description'] ?? '') !== ''): ?>
                      <p class="desc"><?= renderDescriptionHtml((string) $task['description']); ?></p>
                    <?php endif; ?>

                    <?php $taskAttachments = isset($task['attachments']) && is_array($task['attachments']) ? $task['attachments'] : (is_array($task['attachment'] ?? null) ? [$task['attachment']] : []); ?>
                    <?php if (count($taskAttachments) > 0): ?>
                      <?php foreach ($taskAttachments as $attachmentItem): ?>
                        <?php if (!is_array($attachmentItem)) { continue; } ?>
                        <form class="task-attachment" method="post" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                          <input type="hidden" name="action" value="deleteAttachment">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="stored" value="<?= htmlspecialchars((string) ($attachmentItem['stored'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                          <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                          <?php if ($categoryFilter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                          <?php if ($fromDate !== ''): ?><input type="hidden" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                          <?php if ($toDate !== ''): ?><input type="hidden" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                          <input type="hidden" name="page" value="<?= (int) $page; ?>">
                          <input type="hidden" name="per_page" value="<?= (int) $perPage; ?>">
                          <a href="<?= htmlspecialchars(buildDownloadUrl($searchQuery, $page, $perPage, (string) ($attachmentItem['stored'] ?? ''), $categoryFilter, $fromDate, $toDate, $statusFilter), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($attachmentItem['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a>
                          <button class="danger-btn" type="submit" style="padding:6px 10px;">Delete</button>
                        </form>
                      <?php endforeach; ?>
                    <?php endif; ?>
                    <form class="slider-form" method="post">
                      <input type="hidden" name="action" value="updateProgress">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                      <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                      <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                      <?php if ($categoryFilter !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                      <?php if ($fromDate !== ''): ?><input type="hidden" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                      <?php if ($toDate !== ''): ?><input type="hidden" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                      <input type="hidden" name="page" value="<?= (int) $page; ?>">
                      <input type="hidden" name="per_page" value="<?= (int) $perPage; ?>">
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
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <script src="<?= htmlspecialchars(appPath('app.js') . '?v=' . (string) @filemtime(__DIR__ . '/app.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
