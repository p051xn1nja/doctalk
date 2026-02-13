# TaskFlow (PHP)

A small single-file PHP task app with persistent JSON storage and authentication.

## Run locally

```bash
php -S 0.0.0.0:8000 -t .
```

Then open http://localhost:8000/login.php.

## Login credentials

- Username: `p051xn1nja`
- Password: `Pir231411@19781009`

## Features

- Login-gated access to the task app
- Add tasks
- Mark tasks done / undone
- Delete tasks
- Progress counter
- Data persisted in `data/tasks.json`

## Security hardening

- CSRF protection on login and all mutating form actions
- Strict input validation and sanitization for task titles and IDs
- Security headers (CSP, frame, referrer, nosniff, permissions)
- Session cookie hardening (`HttpOnly`, `SameSite=Strict`)
- Atomic file writes with file locking and restrictive file permissions
- Session ID regeneration on successful login and basic login throttling
