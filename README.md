# TaskFlow (PHP)

A PHP task app with authentication, persistent JSON storage, and day-based organization.

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
- Add tasks with title + description and selectable category
- Search tasks by title/description
- Filter tasks by category and date range
- Create, edit, and delete reusable categories with stored colors
- Attach up to 10 files per task with per-file delete controls
- Default grouping by day (collapsed sections with expandable day arrows)
- Adjustable per-task progress (single slider control with live percentage)
- Pagination with per-page options (25/50/100/200/custom, default 50)
- File attachment upload/edit for tasks (docx, pdf, txt, md, xlsx/xls, ppt/pptx, zip, php, js, css, html, py)
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
