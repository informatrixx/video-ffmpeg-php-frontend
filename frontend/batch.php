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

function assetVersionBatch(string $relativePath): string
{
    return (string) filemtime(__DIR__ . '/' . ltrim($relativePath, '/'));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Processing</title>
    <link rel="stylesheet" href="css/app/app.css?v=<?= htmlspecialchars(assetVersionBatch('css/app/app.css')) ?>">
    <script defer src="js/app/common.js?v=<?= htmlspecialchars(assetVersionBatch('js/app/common.js')) ?>"></script>
    <script defer src="js/app/batch.js?v=<?= htmlspecialchars(assetVersionBatch('js/app/batch.js')) ?>"></script>
</head>
<body data-page="batch">
<div class="app-shell">
    <header class="hero">
        <div>
            <p class="eyebrow">Batch Processing</p>
            <h1>Batch</h1>
            <p class="lede">Ordner auswählen, optional rekursiv einlesen, gemeinsame Einstellungen setzen und danach eine kompakte Batch-Liste als gruppierte Jobs anlegen.</p>
        </div>
        <nav class="hero-nav">
            <a href="index.php">Dashboard</a>
            <a href="templates.php">Templates</a>
        </nav>
    </header>

    <section class="status-strip">
        <div class="status-banner" id="authBanner"></div>
        <div class="worker-banner" id="workerBanner"></div>
    </section>

    <main class="batch-grid">
        <aside class="panel">
            <div class="panel-head">
                <div>
                    <h2>Quellordner</h2>
                    <p>Input-Root wählen, Ordner navigieren und den aktuellen Ordner als Batch-Quelle übernehmen.</p>
                </div>
            </div>
            <div class="root-switcher" id="sourceRootSwitcher"></div>
            <nav class="breadcrumbs" id="sourceBreadcrumbs"></nav>
            <div class="readonly-value batch-source-summary" id="selectedSourceFolder">Noch kein Quellordner gewählt.</div>
            <section class="explorer-section">
                <div class="section-head">
                    <h3>Ordner</h3>
                    <div class="button-row">
                        <button type="button" class="ghost-button" id="refreshSourceBrowserBtn">Neu laden</button>
                        <button type="button" id="useCurrentSourceFolderBtn">Diesen Ordner verwenden</button>
                    </div>
                </div>
                <div class="browser-list" id="sourceFolderList"></div>
            </section>
        </aside>

        <section class="panel batch-main-panel">
            <div class="panel-head">
                <div>
                    <h2>Batch Setup</h2>
                    <p>Gemeinsame Einstellungen definieren, Dateiliste erzeugen und Einträge bei Bedarf pro Datei überschreiben.</p>
                </div>
            </div>
            <form id="batchForm" class="stack-form">
                <div class="workspace-form-grid">
                    <label>
                        Gewählter Quellordner
                        <input type="text" name="source_folder_display" id="sourceFolderDisplay" readonly>
                    </label>
                    <label class="inline-check batch-inline-check">
                        <input type="checkbox" name="recursive" id="recursiveInput">
                        <span>Unterordner einbeziehen</span>
                    </label>
                    <label>
                        Ausgabeordner
                        <div class="field-action-row">
                            <input type="text" name="output_folder" list="outputRoots" placeholder="/DataVolume/serien" required>
                            <button type="button" class="ghost-button" data-action="choose-output-folder" data-target="batch">Wählen</button>
                            <button type="button" class="ghost-button" id="applyOutputFolderToAllBtn">Auf alle anwenden</button>
                        </div>
                    </label>
                    <label>
                        Batch-Label
                        <input type="text" name="label" placeholder="Optional">
                    </label>
                    <label>
                        Template
                        <select name="template_id" id="batchTemplateSelect"></select>
                    </label>
                    <label>
                        Variante
                        <input type="text" name="variant" placeholder="high / low / series1">
                    </label>
                </div>
                <div class="button-row">
                    <button type="button" id="loadBatchBtn">Ordner einlesen</button>
                    <button type="button" class="ghost-button" id="previewBatchBtn">Vorschau aktualisieren</button>
                    <button type="submit">Batch anlegen</button>
                </div>
            </form>

            <div class="batch-table-wrap batch-page-table-wrap">
                <table class="data-table compact-table" id="batchPreviewTable">
                    <thead>
                    <tr>
                        <th>Aktiv</th>
                        <th>Datei</th>
                        <th>Quellpfad</th>
                        <th>Titel</th>
                        <th></th>
                        <th>Zieldatei</th>
                        <th>Ausgabeordner</th>
                        <th>Hinweise</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr><td colspan="8" class="empty-row">Noch keine Batch-Vorschau geladen.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Batches</h2>
                <p>Vorhandene Batch-Läufe überwachen sowie gesammelt abbrechen oder fehlerhafte Jobs neu starten.</p>
            </div>
            <button type="button" class="ghost-button" id="loadOlderBatchesBtn">Ältere laden</button>
        </div>
        <div class="table-scroll">
            <table class="data-table" id="batchesTable">
                <thead>
                <tr>
                    <th>Label</th>
                    <th>Status</th>
                    <th>Quelle</th>
                    <th>Ziel</th>
                    <th>Summary</th>
                    <th>Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <tr><td colspan="6" class="empty-row">Noch keine Batches vorhanden.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

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
