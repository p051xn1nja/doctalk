<?php

declare(strict_types=1);

const AUTH_USERNAME = 'p051xn1nja';
const AUTH_PASSWORD_HASH = '$2y$12$NNLt8kcJoWuCvBuHl2B0rOGOemSKshGfXflWUkReyQuRhBheIhp5i';
const SESSION_LIFETIME = 86400; // 24 hours

configureSession();
session_start();
applySecurityHeaders();

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['login_attempts']) || !is_int($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

if (!isset($_SESSION['lock_until']) || !is_int($_SESSION['lock_until'])) {
    $_SESSION['lock_until'] = 0;
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

if (!empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header('Location: ' . appPath('index.php'), true, 303);
    exit;
}

$error = '';
$lockRemaining = max(0, $_SESSION['lock_until'] - time());

if ($method === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    if ($lockRemaining > 0) {
        $error = 'Too many failed attempts. Try again in ' . $lockRemaining . ' seconds.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $validUser = hash_equals(AUTH_USERNAME, $username);
        $validPassword = password_verify($password, AUTH_PASSWORD_HASH);

        if ($validUser && $validPassword) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['login_attempts'] = 0;
            $_SESSION['lock_until'] = 0;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: ' . appPath('index.php'), true, 303);
            exit;
        }

        $_SESSION['login_attempts']++;
        if ($_SESSION['login_attempts'] >= 5) {
            $_SESSION['lock_until'] = time() + 120;
            $_SESSION['login_attempts'] = 0;
            $error = 'Too many failed attempts. Try again in 120 seconds.';
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$csrfToken = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TaskFlow Login</title>
  <style>
    :root {
      --bg: #0f172a;
      --card: #111827;
      --accent: #38bdf8;
      --muted: #94a3b8;
      --text: #e2e8f0;
      --danger: #ef4444;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      background-color: #0f172a;
      background-image: radial-gradient(circle at top, #1e293b, #0f172a);
      color: var(--text);
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      display: grid;
      place-items: center;
      padding: 24px;
    }

    .card {
      width: min(420px, 100%);
      background-color: #111827;
      border: 1px solid #1f2937;
      border-radius: 18px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, .35);
      padding: 24px;
    }

    h1 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 1.8rem;
    }

    p {
      margin-top: 0;
      color: var(--muted);
    }

    label {
      display: block;
      margin-bottom: 6px;
      font-size: .95rem;
    }

    input {
      width: 100%;
      margin-bottom: 14px;
      background: #0b1220;
      color: var(--text);
      border: 1px solid #334155;
      border-radius: 12px;
      padding: 12px 14px;
      font-size: 1rem;
    }

    button {
      width: 100%;
      border: 0;
      border-radius: 12px;
      padding: 11px 14px;
      font-weight: 600;
      cursor: pointer;
      background: var(--accent);
      color: #082f49;
    }

    .error {
      border: 1px solid #7f1d1d;
      background: #450a0a;
      color: #fecaca;
      border-radius: 10px;
      padding: 10px 12px;
      margin-bottom: 14px;
    }
  </style>
</head>
<body>
  <main class="card">
    <h1>TaskFlow Login</h1>
    <p>Please sign in to access your tasks.</p>

    <?php if ($error !== ''): ?>
      <div class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

      <label for="username">Username</label>
      <input id="username" name="username" type="text" maxlength="64" required>

      <label for="password">Password</label>
      <input id="password" name="password" type="password" maxlength="128" required>

      <button type="submit">Sign in</button>
    </form>
  </main>
</body>
</html>
