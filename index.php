<?php

declare(strict_types=1);

const DATA_FILE = __DIR__ . '/data/tasks.json';

function loadTasks(): array
{
    if (!file_exists(DATA_FILE)) {
        return [];
    }

    $json = file_get_contents(DATA_FILE);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveTasks(array $tasks): void
{
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents(DATA_FILE, json_encode(array_values($tasks), JSON_PRETTY_PRINT));
}

function redirectHome(): void
{
    header('Location: /');
    exit;
}

$tasks = loadTasks();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');

        if ($title !== '') {
            $tasks[] = [
                'id' => bin2hex(random_bytes(6)),
                'title' => $title,
                'done' => false,
                'created_at' => date('c'),
            ];
            saveTasks($tasks);
        }

        redirectHome();
    }

    if ($action === 'toggle') {
        $id = $_POST['id'] ?? '';
        foreach ($tasks as &$task) {
            if (($task['id'] ?? '') === $id) {
                $task['done'] = !($task['done'] ?? false);
                break;
            }
        }
        unset($task);
        saveTasks($tasks);
        redirectHome();
    }

    if ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $tasks = array_values(array_filter($tasks, static fn(array $task): bool => ($task['id'] ?? '') !== $id));
        saveTasks($tasks);
        redirectHome();
    }
}

$completedCount = count(array_filter($tasks, static fn(array $task): bool => (bool) ($task['done'] ?? false)));
$totalCount = count($tasks);
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
    <p class="meta">A tiny PHP to-do app. Completed <?= $completedCount; ?> / <?= $totalCount; ?> tasks.</p>

    <form class="row" method="post">
      <input type="hidden" name="action" value="add">
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
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($task['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <button class="ghost-btn" type="submit"><?= !empty($task['done']) ? 'Undo' : 'Done'; ?></button>
            </form>
            <form method="post">
              <input type="hidden" name="action" value="delete">
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
