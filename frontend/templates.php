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

function assetVersionTemplate(string $relativePath): string
{
    return (string) filemtime(__DIR__ . '/' . ltrim($relativePath, '/'));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Template Editor</title>
    <link rel="stylesheet" href="css/app/app.css?v=<?= htmlspecialchars(assetVersionTemplate('css/app/app.css')) ?>">
    <script defer src="js/app/common.js?v=<?= htmlspecialchars(assetVersionTemplate('js/app/common.js')) ?>"></script>
    <script defer src="js/app/templates.js?v=<?= htmlspecialchars(assetVersionTemplate('js/app/templates.js')) ?>"></script>
</head>
<body data-page="templates">
<div class="app-shell">
    <header class="hero">
        <div>
            <p class="eyebrow">Template Management</p>
            <h1>Templates</h1>
            <p class="lede">Versionierte Templates mit Draft-/Publish-Workflow, gefuehrtem Editor und direkter JSON-Bearbeitung.</p>
        </div>
        <nav class="hero-nav">
            <a href="index.php">Dashboard</a>
            <a href="batch.php">Batch</a>
        </nav>
    </header>

    <section class="status-banner" id="authBanner"></section>

    <main class="dashboard-grid templates-grid">
        <section class="panel">
            <div class="panel-head">
                <h2>Templates</h2>
                <p>Auswahl und schnelle Übersicht.</p>
            </div>
            <div id="templateList" class="template-list"></div>
            <div class="button-row">
                <button type="button" id="newTemplateBtn">Neues Template</button>
            </div>
        </section>

        <section class="panel panel-wide">
            <div class="panel-head">
                <h2>Editor</h2>
                <p>JSON-Schema für Regeln und Publishing.</p>
            </div>
            <form id="templateForm" class="stack-form">
                <input type="hidden" name="id">
                <label>
                    Name
                    <input type="text" name="name" required>
                </label>
                <label>
                    Beschreibung
                    <input type="text" name="description">
                </label>
                <label>
                    Version
                    <select name="version_id" id="templateVersionSelect"></select>
                </label>
                <div class="editor-toolbar">
                    <div class="button-row">
                        <button type="button" class="ghost-button" id="guidedModeBtn">Gefuehrt</button>
                        <button type="button" class="ghost-button" id="jsonModeBtn">JSON</button>
                    </div>
                    <div class="button-row">
                        <button type="button" class="ghost-button" id="guidedToJsonBtn">Form nach JSON</button>
                        <button type="button" class="ghost-button" id="jsonToGuidedBtn">JSON in Form laden</button>
                    </div>
                </div>
                <section class="guided-editor" id="guidedEditor">
                    <div class="section-head vertical">
                        <h3>Gefuehrter Editor</h3>
                        <p>Die haeufigsten Standardfaelle werden als Regeln aufgebaut und koennen danach weiter im JSON-Modus verfeinert werden.</p>
                    </div>
                    <label class="inline-check">
                        <input type="checkbox" id="guidedVideoRuleEnabled">
                        <span>Generische Video-Regel aktivieren</span>
                    </label>
                    <div class="workspace-form-grid">
                        <label>
                            Job-Titel-Template
                            <input type="text" id="guidedJobTitle" placeholder="{{source_base_name}}">
                        </label>
                        <label>
                            Output-Datei-Template
                            <input type="text" id="guidedJobOutputFile" placeholder="{{source_base_name}}.mkv">
                        </label>
                        <label>
                            Audio-Naming
                            <input type="text" id="guidedAudioNaming" placeholder="##LANG:human## (##CHANNELS####LOUDNORM:comma-true##)">
                        </label>
                        <label>
                            Subtitle-Naming
                            <input type="text" id="guidedSubtitleNaming" placeholder="##INDEX## - ##LANG:human[if-no-title]## ##TITLE##">
                        </label>
                        <label>
                            Subtitle-Sprachen
                            <input type="text" id="guidedSubtitleLanguages" placeholder="ger, deu, eng">
                        </label>
                        <label>
                            Video-Codec
                            <select id="guidedVideoCodec"></select>
                        </label>
                        <label>
                            Video-Modus
                            <select id="guidedVideoMode"></select>
                        </label>
                        <label>
                            Video-Wert
                            <input type="number" step="0.1" id="guidedVideoModeValue" placeholder="23">
                        </label>
                        <label>
                            Video-Preset
                            <input type="text" id="guidedVideoPreset" placeholder="slow">
                        </label>
                        <label>
                            Video-Resize
                            <input type="text" id="guidedVideoResize" placeholder="0 / 720 / 1920x1080">
                        </label>
                        <label>
                            Video-Crop
                            <input type="text" id="guidedVideoCrop" placeholder="auto">
                        </label>
                        <label>
                            Video-NLMeans
                            <select id="guidedVideoNlmeans"></select>
                        </label>
                    </div>
                    <div class="guided-rule-box">
                        <label class="inline-check">
                            <input type="checkbox" id="guidedDualAudioEnabled">
                            <span>Deutsche AC3-Spur: Copy + AAC Loudnorm erzeugen</span>
                        </label>
                        <div class="workspace-form-grid">
                            <label>
                                AAC-Codec
                                <select id="guidedDualAudioCodec"></select>
                            </label>
                            <label>
                                AAC-Profil
                                <input type="text" id="guidedDualAudioProfile" placeholder="main">
                            </label>
                            <label>
                                AAC-Bitrate
                                <input type="text" id="guidedDualAudioBitrate" placeholder="256k">
                            </label>
                            <label>
                                AAC-Samplerate
                                <select id="guidedDualAudioSamplerate"></select>
                            </label>
                            <label>
                                AAC-Kanaele
                                <select id="guidedDualAudioChannels"></select>
                            </label>
                            <label>
                                Loudnorm-Profil
                                <select id="guidedDualAudioLoudnorm"></select>
                            </label>
                        </div>
                    </div>
                </section>
                <label id="jsonEditorBlock">
                    Schema JSON
                    <textarea name="schema" class="json-editor" id="templateSchemaEditor" spellcheck="false"></textarea>
                </label>
                <div class="button-row">
                    <button type="submit">Draft speichern</button>
                    <button type="button" id="publishTemplateBtn">Ausgewählte Version publishen</button>
                </div>
            </form>
            <pre class="json-preview" id="templatePreview">Kein Template ausgewählt.</pre>
        </section>
    </main>
</div>
</body>
</html>
