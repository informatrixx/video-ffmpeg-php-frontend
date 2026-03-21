<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/app/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$runtime = app_runtime();

if ($runtime->config()->debug()) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

function assetVersion(string $relativePath): string
{
    return (string) filemtime(__DIR__ . '/' . ltrim($relativePath, '/'));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie FFMPEG Frontend</title>
    <link rel="stylesheet" href="css/app/app.css?v=<?= htmlspecialchars(assetVersion('css/app/app.css')) ?>">
    <script defer src="js/app/common.js?v=<?= htmlspecialchars(assetVersion('js/app/common.js')) ?>"></script>
    <script defer src="js/app/dashboard.js?v=<?= htmlspecialchars(assetVersion('js/app/dashboard.js')) ?>"></script>
</head>
<body data-page="dashboard">
<div class="app-shell">
    <header class="hero">
        <div>
            <p class="eyebrow">Explorer, Scan, Queue</p>
            <h1>Movie FFMPEG Frontend</h1>
            <p class="lede">Ein gemeinsamer Workspace fuer Ordneranzeige, Scan, zusaetzliche Inputs, RAR-Extract und die neue SQLite-Queue.</p>
        </div>
        <nav class="hero-nav">
            <a href="batch.php">Batch</a>
            <a href="templates.php">Templates</a>
        </nav>
    </header>

    <section class="status-strip">
        <div class="status-banner" id="authBanner"></div>
        <div class="worker-banner" id="workerBanner"></div>
    </section>

    <main class="workspace-grid">
        <aside class="panel explorer-panel">
            <div class="panel-head">
                <div>
                    <h2>Explorer</h2>
                    <p>Ordner anzeigen, Dateien direkt oeffnen und zusaetzliche Inputs ergaenzen.</p>
                </div>
            </div>
            <div class="root-switcher" id="rootSwitcher"></div>
            <nav class="breadcrumbs" id="breadcrumbs"></nav>
            <section class="explorer-section">
                <div class="section-head">
                    <h3>Ordner</h3>
                    <button type="button" class="ghost-button" id="refreshBrowserBtn">Neu laden</button>
                </div>
                <div class="browser-list" id="folderList"></div>
            </section>
            <section class="explorer-section">
                <div class="section-head">
                    <h3>Dateien</h3>
                    <input type="search" id="browserFilterInput" placeholder="Dateien und Ordner filtern">
                </div>
                <div class="browser-list browser-file-list" id="fileList"></div>
            </section>
        </aside>

        <section class="panel workspace-panel">
            <div class="panel-head">
                <div>
                    <h2>Workspace</h2>
                    <p id="workspaceSubtitle">Datei im Explorer öffnen, Scan laden und den finalen Plan bearbeiten.</p>
                </div>
                <div class="button-row">
                    <button type="button" id="reloadWorkspaceBtn">Template neu anwenden</button>
                    <button type="button" id="queueWorkspaceBtn">In Queue legen</button>
                </div>
            </div>
            <div class="empty-state" id="workspaceEmpty">
                <h3>Kein aktiver Scan</h3>
                <p>Wähle links eine Video- oder RAR-Datei. Video-Dateien laden den Scan-Editor, RAR-Dateien den Extract-Editor.</p>
            </div>
            <div id="workspaceView"></div>
        </section>

        <aside class="sidebar-stack">
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Queue</h2>
                        <p>Laufende Jobs abbrechen, pausieren, neu starten und wartende Jobs umsortieren.</p>
                    </div>
                </div>
                <div class="queue-list" id="jobsList">
                    <div class="queue-empty">Queue ist leer.</div>
                </div>
            </section>
        </aside>
    </main>

    <datalist id="outputRoots"></datalist>
    <datalist id="outputHistory"></datalist>
</div>
<div class="modal-backdrop hidden" id="outputFolderModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="outputFolderModalTitle">
        <div class="panel-head">
            <div>
                <h2 id="outputFolderModalTitle">Ausgabeordner wählen</h2>
                <p>Ordner unter den erlaubten Output-Roots wählen oder im aktuellen Pfad neu anlegen.</p>
            </div>
            <button type="button" class="ghost-button" id="closeOutputFolderModalBtn">Schließen</button>
        </div>
        <div class="readonly-value" id="outputFolderCurrentPath"></div>
        <div class="root-switcher" id="outputRootSwitcher"></div>
        <nav class="breadcrumbs" id="outputBreadcrumbs"></nav>
        <section class="explorer-section">
            <div class="section-head">
                <h3>Ordner</h3>
                <div class="button-row">
                    <button type="button" class="ghost-button" id="refreshOutputBrowserBtn">Neu laden</button>
                    <button type="button" id="selectOutputFolderBtn">Diesen Ordner verwenden</button>
                </div>
            </div>
            <div class="browser-list" id="outputFolderList"></div>
        </section>
        <form id="outputFolderCreateForm" class="stack-form modal-create-form">
            <label>
                Neuer Unterordner
                <div class="field-action-row">
                    <input type="text" name="folder_name" placeholder="Neuer Ordnername" autocomplete="off">
                    <button type="submit">Ordner anlegen</button>
                </div>
            </label>
        </form>
    </div>
</div>
</body>
</html>
