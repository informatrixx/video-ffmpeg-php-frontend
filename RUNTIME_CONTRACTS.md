# Runtime Contracts

This file documents the stable seams for parallel Codex work on the new runtime.

## Work packages
- Package A: `shared/app/Config.php`, `shared/app/Database.php`, `shared/app/Repository.php`
- Package B: `shared/app/TemplateEngine.php`, `frontend/query/api/template*.php`, `frontend/templates.php`, `frontend/js/app/templates.js`
- Package C: `shared/app/FfmpegCommandBuilder.php`, `shared/app/QueueWorker.php`, `bin/runtime-worker.php`, `quma.service.example`
- Package D: `frontend/query/api/bootstrap.php`, `frontend/query/api/dashboard-snapshot.php`, `frontend/query/api/batch-snapshot.php`, `frontend/query/api/job-action.php`, `frontend/query/api/batch-action.php`, `frontend/query/api/events/stream.php`
- Package E: `frontend/index.php`, `frontend/js/app/dashboard.js`, `frontend/css/app/app.css`

## Feedback format
Forked tasks should report back using this structure:

```json
{
  "source": "runtime-worker",
  "task_id": "job-id-or-subtask-id",
  "package": "C",
  "changed_files": [
    "shared/app/QueueWorker.php",
    "bin/runtime-worker.php"
  ],
  "assumptions": [
    "ffmpeg and ffprobe are reachable via config.json"
  ],
  "risks": [
    "Auto-crop parity with legacy worker is not implemented yet"
  ],
  "tests": [
    "php -l shared/app/QueueWorker.php",
    "php bin/runtime-worker.php --once"
  ],
  "follow_up": [
    "Switch systemd ExecStart to bin/runtime-worker.php"
  ]
}
```

## Stable runtime contracts
- SQLite database path is configured via `Runtime.DatabasePath` and defaults to `config/runtime.sqlite`.
- Worker heartbeat is stored in the `settings` table under `runtime_worker_state`.
- Worker feedback is appended to `config/worker-feedback.jsonl`.
- Worker feedback records should expose `source`, `task_id`, `state`, `message`, `updated_at` and optional `job_id`.
- Runtime live updates are emitted via `runtime_events` and streamed through `frontend/query/api/events/stream.php`.
- `frontend/query/api/bootstrap.php` returns static/session state (`auth`, `roots`, `editor_options`, `templates`, `last_event_id`).
- `frontend/query/api/dashboard-snapshot.php` returns lightweight `worker`, `jobs`, `batches` summaries for the dashboard.
- `frontend/query/api/batch-snapshot.php` returns lightweight `worker` and `batches` summaries for the batch page.
- `job_events` remain available for detailed job-local topics, but the new UI consumes `runtime_events`.
