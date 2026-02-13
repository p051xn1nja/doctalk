# TaskFlow (PHP)

A PHP task app with authentication, persistent JSON storage, and day-based organization.

## Run locally

```bash
php -S 0.0.0.0:8000 -t .
```

Then open http://localhost:8000/login.php.

## Login credentials

- Username: `p051xn1nja`
- Password: `...`

## Features

- Login-gated access to the task app
- Add tasks with title + description
- Search tasks by title/description
- Default grouping by day (based on creation date)
- Adjustable per-task progress (slider + progress bar)
- Edit individual tasks (title + description)
- Mark tasks done / undone
- Delete tasks
- Data persisted in `data/tasks.json`

## Security hardening

- CSRF protection on login and all mutating form actions
- Strict input validation and sanitization
- Security headers (CSP, frame, referrer, nosniff, permissions)
- Session cookie hardening (`HttpOnly`, `SameSite=Strict`, subfolder-aware path)
- Atomic file writes with file locking and restrictive file permissions
- Session ID regeneration on successful login and basic login throttling
