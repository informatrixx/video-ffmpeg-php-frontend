# AGENTS.md blueprint for movie-ffmpeg-php-frontend

## Project Overview
- PHP web frontend for FFMPEG-based video conversion with scan, crop preview, queueing, and status views.
- Current architecture is server-rendered PHP plus page-specific JS/CSS under `frontend/`; backend endpoints live in `frontend/query/`.
- A long-running CLI queue manager in `quma/queue-manager-service.php` reads `quma/queue.json` and communicates over Unix sockets.
- Config is file-based: `config.json` for runtime values, `config/static_config.json` for codec/scan metadata, and `config/decision_template.json` for presets.
- README explicitly marks the project as early development, not production-safe, with weak data-integrity and path-safety concerns.
- Confirmed dev dependencies in README: PHP 8.2, nginx, ffmpeg, x264/x265, libfdk_aac, unrar, ImageMagick, and a JS-capable browser.
- Workspace: `/var/www/movie-ffmpeg-php-frontend`

## Directory Map
- `/var/www/omikron/movie-ffmpeg-php-frontend/frontend/`: Main web UI: PHP entrypoints plus page-specific JS, CSS, templates, and query endpoints.
- `/var/www/omikron/movie-ffmpeg-php-frontend/frontend/query/`: AJAX/SSE-style backend handlers for scan, explore, crop preview, queue actions, and status events.
- `/var/www/omikron/movie-ffmpeg-php-frontend/frontend/static/`: Generated static assets with hashed filenames; likely build output or cache artifacts, so avoid hand-editing unless necessary.
- `/var/www/omikron/movie-ffmpeg-php-frontend/quma/`: Queue manager service and persisted queue state in `queue.json`.
- `/var/www/omikron/movie-ffmpeg-php-frontend/shared/`: Shared PHP helpers, path constants, cache helpers, IPTC helpers, and queue status codes.
- `/var/www/omikron/movie-ffmpeg-php-frontend/config/`: Runtime metadata such as static codec config, presets, ID file, and history/state JSON files.

## Coding Rules
- Ground changes in the current PHP plus vanilla JS structure; do not assume a framework or package manager exists.
- Prefer editing source files in `frontend/js`, `frontend/css`, `frontend/query`, `shared`, and `quma`; treat `frontend/static` as generated output unless the repo proves otherwise.
- Preserve file-based config and queue flows unless the task explicitly redesigns them.
- Be careful with path handling and filesystem writes; README and `shared/common.inc.php` show incomplete safety checks.
- When touching queue or status behavior, account for the CLI service, `quma/queue.json`, Unix sockets, and SSE endpoint coupling.
- Keep bilingual legacy context in mind; README says the project mixes German and English.
- Do not claim commands, tests, or build steps that are not present in the repo snapshot.

## Validation / Review Expectations
- If PHP files change, run targeted syntax checks such as `php -l` only if the environment allows it; no confirmed automated test suite exists.
- For queue/status changes, validate both the web endpoint side and the CLI service contract because they communicate through socket files in `run/`.
- For scan/config changes, verify behavior against `config/static_config.json` and the relevant `frontend/query/scan*.php` flow.
- For frontend changes, verify the affected PHP page still loads its expected JS/CSS assets and query endpoints; no confirmed bundler workflow is documented.
- Call out unverified areas explicitly when validation cannot be completed.

## Worker Guidance
- Start by reading `README.md`, `shared/common.inc.php`, and the exact page/endpoint files involved before proposing changes.
- Assume the core seams are: page PHP -> `frontend/js/*` -> `frontend/query/*` -> shared helpers / queue service.
- Use `frontend/index.php`, `frontend/scan.php`, and `frontend/status.php` as the main user-facing entrypoints unless the task is narrower.
- Check `config/static_config.json` before changing codec, scan-module, language, or queue-limit behavior.
- Expect hidden operational dependencies on `config.json`, `config/ID`, `run/`, and `quma/queue.json`; note uncertainty if those files are not fully inspectable.
- Prefer incremental modernization plans that reduce stringly coupled PHP/JS behavior without breaking the existing queue-manager contract.

## Notes
- No confirmed test runner, asset build command, or local start command is documented in the snapshot.
- `frontend/query/statuseventsream.php` appears to be the status SSE endpoint; keep the filename as-is unless a rename is explicitly intended.
- `shared/common.inc.php` defines global path constants such as `ROOT`, `RUN_DIR`, and `LOG_DIR`; many scripts depend on them.
- README warns not to run as root and says the app is not production-ready; reviewers should prioritize safety regressions first.
