# TaskFlow (PHP)

A small single-file PHP task app with persistent JSON storage.

## Run locally

```bash
php -S 0.0.0.0:8000 -t .
```

Then open http://localhost:8000/index.php.

## Features

- Add tasks
- Mark tasks done / undone
- Delete tasks
- Progress counter
- Data persisted in `data/tasks.json`

## Security hardening

- CSRF protection on all mutating form actions
- Strict input validation and sanitization for task titles and IDs
- Security headers (CSP, frame, referrer, nosniff, permissions)
- Session cookie hardening (`HttpOnly`, `SameSite=Strict`)
- Atomic file writes with file locking and restrictive file permissions
