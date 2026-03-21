!!! Make sure this directory is not accessible in your Web-Server config !!!

This directory now also contains the SQLite runtime store for the new queue/template system.

Files:

static_config.json
Static codec/language metadata and scan module definitions.

decision_template.json
Legacy decision template. The new runtime seeds its first editable template from this file.

runtime.sqlite
SQLite database for jobs, batches, templates, worker state and events.

worker-feedback.jsonl
Append-only worker feedback stream. Each line is a JSON object with the current worker state.

ID
Simple unique ID for the installation.

Recommended permissions:
- Web server user needs read access to `static_config.json`, `decision_template.json`, `ID`.
- Web server user and queue worker user need read/write access to `runtime.sqlite` and `worker-feedback.jsonl`.
- Do not expose this directory via HTTP.
