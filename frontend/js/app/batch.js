(function () {
    const state = {
        csrfToken: null,
        authConfigured: false,
        authenticated: false,
        worker: null,
        roots: { input: {}, output: {} },
        editorOptions: {},
        templates: [],
        batches: [],
        sourceBrowser: null,
        currentSourceFolder: '',
        batchSettings: {
            sourceFolder: '',
            recursive: false,
            outputFolder: '',
            label: '',
            templateId: '',
            variant: '',
        },
        batchPreviewItems: [],
        lastLoadedBatchFolder: '',
        outputFolderModal: {
            open: false,
            target: null,
            folder: '',
            browser: null,
        },
        eventSource: null,
        lastEventId: 0,
        liveRenderTimer: null,
        streamReconnectTimer: null,
        liveRenderFlags: {
            worker: false,
            batches: false,
        },
        nextBatchBeforeId: null,
        lastStateRefreshAt: 0,
    };

    const els = {};

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        cacheElements();
        bindEvents();

        try {
            await refreshState();
        } catch (error) {
            renderAuthBanner(error.message || 'Batch-Frontend konnte nicht initialisiert werden.');
            renderSourceBrowser();
        }
    }

    function cacheElements() {
        els.authBanner = document.getElementById('authBanner');
        els.workerBanner = document.getElementById('workerBanner');
        els.sourceRootSwitcher = document.getElementById('sourceRootSwitcher');
        els.sourceBreadcrumbs = document.getElementById('sourceBreadcrumbs');
        els.sourceFolderList = document.getElementById('sourceFolderList');
        els.selectedSourceFolder = document.getElementById('selectedSourceFolder');
        els.refreshSourceBrowserBtn = document.getElementById('refreshSourceBrowserBtn');
        els.useCurrentSourceFolderBtn = document.getElementById('useCurrentSourceFolderBtn');
        els.batchForm = document.getElementById('batchForm');
        els.sourceFolderDisplay = document.getElementById('sourceFolderDisplay');
        els.loadBatchBtn = document.getElementById('loadBatchBtn');
        els.previewBatchBtn = document.getElementById('previewBatchBtn');
        els.applyOutputFolderToAllBtn = document.getElementById('applyOutputFolderToAllBtn');
        els.batchTemplateSelect = document.getElementById('batchTemplateSelect');
        els.batchPreviewTableBody = document.querySelector('#batchPreviewTable tbody');
        els.batchesTableBody = document.querySelector('#batchesTable tbody');
        els.loadOlderBatchesBtn = document.getElementById('loadOlderBatchesBtn');
        els.outputRoots = document.getElementById('outputRoots');
        els.outputHistory = document.getElementById('outputHistory');
        els.outputFolderModal = document.getElementById('outputFolderModal');
        els.closeOutputFolderModalBtn = document.getElementById('closeOutputFolderModalBtn');
        els.outputFolderCurrentPath = document.getElementById('outputFolderCurrentPath');
        els.outputRootSwitcher = document.getElementById('outputRootSwitcher');
        els.outputBreadcrumbs = document.getElementById('outputBreadcrumbs');
        els.outputFolderList = document.getElementById('outputFolderList');
        els.refreshOutputBrowserBtn = document.getElementById('refreshOutputBrowserBtn');
        els.selectOutputFolderBtn = document.getElementById('selectOutputFolderBtn');
        els.outputFolderCreateForm = document.getElementById('outputFolderCreateForm');
    }

    function bindEvents() {
        els.authBanner.addEventListener('submit', handleAuthSubmit);
        els.authBanner.addEventListener('click', handleAuthClick);
        els.sourceRootSwitcher.addEventListener('click', handleSourceRootClick);
        els.sourceBreadcrumbs.addEventListener('click', handleSourceBreadcrumbClick);
        els.sourceFolderList.addEventListener('click', handleSourceFolderClick);
        els.refreshSourceBrowserBtn.addEventListener('click', async () => {
            try {
                await loadSourceBrowser(state.currentSourceFolder || firstInputRoot(), { force: true });
            } catch (error) {
                renderAuthBanner(error.message);
            }
        });
        els.useCurrentSourceFolderBtn.addEventListener('click', useCurrentSourceFolder);
        els.batchForm.addEventListener('input', syncBatchSettingsFromForm);
        els.batchForm.addEventListener('change', syncBatchSettingsFromForm);
        els.batchForm.addEventListener('click', handleBatchFormClick);
        els.loadBatchBtn.addEventListener('click', () => loadBatchPreview(false));
        els.previewBatchBtn.addEventListener('click', () => loadBatchPreview(true));
        els.applyOutputFolderToAllBtn.addEventListener('click', applyCurrentOutputFolderToAll);
        els.batchForm.addEventListener('submit', handleBatchCreate);
        els.batchPreviewTableBody.addEventListener('input', handleBatchPreviewChange);
        els.batchPreviewTableBody.addEventListener('change', handleBatchPreviewChange);
        els.batchPreviewTableBody.addEventListener('click', handleBatchPreviewClick);
        els.batchesTableBody.addEventListener('click', handleBatchAction);
        if (els.loadOlderBatchesBtn) {
            els.loadOlderBatchesBtn.addEventListener('click', handleLoadOlderBatches);
        }
        els.outputRootSwitcher.addEventListener('click', handleOutputRootClick);
        els.outputBreadcrumbs.addEventListener('click', handleOutputBreadcrumbClick);
        els.outputFolderList.addEventListener('click', handleOutputFolderClick);
        els.refreshOutputBrowserBtn.addEventListener('click', async () => {
            try {
                await loadOutputFolderBrowser(state.outputFolderModal.folder || firstOutputRoot(), { force: true });
            } catch (error) {
                renderAuthBanner(error.message);
            }
        });
        els.selectOutputFolderBtn.addEventListener('click', applySelectedOutputFolder);
        els.closeOutputFolderModalBtn.addEventListener('click', closeOutputFolderModal);
        els.outputFolderCreateForm.addEventListener('submit', handleOutputFolderCreate);
        els.outputFolderModal.addEventListener('click', handleOutputModalBackdropClick);
        document.addEventListener('keydown', handleGlobalKeydown);
        document.addEventListener('visibilitychange', handleVisibilityChange);
    }

    async function refreshState(message = '', options = {}) {
        await refreshBootstrap();
        await refreshBatchSnapshot();

        ensureBatchDefaults();
        renderAuthBanner(message);
        renderWorkerBanner();
        renderOutputSuggestions();
        renderBatchForm();
        renderBatchPreview();
        renderBatches();
        renderOutputFolderModal();
        syncEventStream();

        if (!canUseBatch()) {
            state.sourceBrowser = null;
            renderSourceBrowser();
            return;
        }

        if (state.sourceBrowser === null) {
            const initialFolder = state.currentSourceFolder || state.batchSettings.sourceFolder || firstInputRoot();
            if (initialFolder !== '') {
                try {
                    await loadSourceBrowser(initialFolder);
                } catch (error) {
                    state.sourceBrowser = null;
                    renderSourceBrowser();
                    renderAuthBanner(error.message);
                }
            } else {
                renderSourceBrowser();
            }
        } else {
            renderSourceBrowser();
        }
    }

    async function refreshBootstrap() {
        const data = await appCommon.fetchJson('query/api/bootstrap.php');
        state.csrfToken = data.auth.csrf_token;
        state.authConfigured = data.auth.configured;
        state.authenticated = data.auth.authenticated;
        state.roots = data.roots || { input: {}, output: {} };
        state.editorOptions = data.editor_options || {};
        state.templates = data.templates || [];
        state.lastEventId = Number(data.last_event_id || state.lastEventId || 0);
    }

    async function refreshBatchSnapshot(options = {}) {
        if (state.authConfigured && !state.authenticated) {
            state.worker = null;
            state.batches = [];
            state.nextBatchBeforeId = null;
            return;
        }

        const params = new URLSearchParams();
        params.set('limit', '20');
        if (options.beforeId) {
            params.set('before_id', String(options.beforeId));
        }

        const data = await appCommon.fetchJson(`query/api/batch-snapshot.php?${params.toString()}`);
        state.worker = data.worker || null;
        state.lastEventId = Number(data.last_event_id || state.lastEventId || 0);
        state.nextBatchBeforeId = data.next_before_id || null;
        state.lastStateRefreshAt = Date.now();

        if (options.append) {
            mergeOlderBatches(data.batches || []);
        } else {
            state.batches = Array.isArray(data.batches) ? data.batches.slice() : [];
            sortBatches();
        }
    }

    function shouldPreserveBatchDraftDom() {
        const active = document.activeElement;
        if (!(active instanceof HTMLElement) || active === document.body) {
            return false;
        }

        return Boolean(
            active.closest('#batchForm') ||
            active.closest('#batchPreviewTable')
        );
    }

    function renderAuthBanner(message = '') {
        clear(els.authBanner);

        const text = createElement('span', {
            className: 'status-copy',
            text: '',
        });

        if (!state.authConfigured) {
            els.authBanner.className = 'status-banner warning';
            text.textContent = message || 'Authentifizierung ist nicht konfiguriert.';
            els.authBanner.appendChild(text);
            return;
        }

        if (state.authenticated) {
            els.authBanner.className = 'status-banner success';
            text.textContent = message || 'Sitzung aktiv.';
            els.authBanner.appendChild(text);
            els.authBanner.appendChild(createButton('Logout', { action: 'logout' }));
            return;
        }

        els.authBanner.className = 'status-banner warning';
        text.textContent = message || 'Nicht angemeldet.';
        els.authBanner.appendChild(text);

        const form = createElement('form', { className: 'auth-inline-form', attrs: { id: 'authLoginForm' } });
        form.appendChild(createElement('input', {
            attrs: {
                type: 'password',
                name: 'password',
                placeholder: 'Admin-Passwort',
                required: true,
            },
        }));
        form.appendChild(createElement('button', {
            text: 'Login',
            attrs: { type: 'submit' },
        }));
        els.authBanner.appendChild(form);
    }

    function renderWorkerBanner() {
        const worker = state.worker || {};
        const stateLabel = worker.state || 'unknown';
        const updatedAt = worker.updated_at ? new Date(worker.updated_at).toLocaleString('de-AT') : 'unbekannt';
        const staleSuffix = worker.stale ? ' · stale' : '';
        const source = worker.source ? ` · ${worker.source}` : '';
        const message = worker.message ? ` · ${worker.message}` : '';
        els.workerBanner.className = 'worker-banner' + ((stateLabel === 'failed' || worker.stale) ? ' warning' : '');
        els.workerBanner.textContent = `Worker: ${stateLabel}${source}${staleSuffix} · Aktualisiert: ${updatedAt}${message}`;
    }

    function syncEventStream() {
        if (document.hidden || (state.authConfigured && !state.authenticated)) {
            closeEventStream();
            return;
        }

        if (state.eventSource) {
            return;
        }

        state.eventSource = new EventSource(`query/api/events/stream.php?scope=batch&after=${state.lastEventId}`);
        ['worker.updated', 'batch.created', 'batch.updated', 'batch.deleted'].forEach((eventName) => {
            state.eventSource.addEventListener(eventName, (event) => {
                applyRuntimeEvent(event);
            });
        });
        state.eventSource.addEventListener('idle', async (event) => {
            if (event.lastEventId) {
                state.lastEventId = Number(event.lastEventId) || state.lastEventId;
            }
            if ((Date.now() - state.lastStateRefreshAt) > 30000 && !document.hidden) {
                await refreshBatchSnapshot();
                renderWorkerBanner();
                renderBatches();
            }
        });
        state.eventSource.addEventListener('error', () => {
            closeEventStream();
            if (state.streamReconnectTimer !== null || document.hidden) {
                return;
            }
            state.streamReconnectTimer = window.setTimeout(async () => {
                state.streamReconnectTimer = null;
                if (document.hidden) {
                    return;
                }
                try {
                    await refreshBatchSnapshot();
                    renderWorkerBanner();
                    renderBatches();
                } catch (error) {
                    renderAuthBanner(error.message);
                }
                syncEventStream();
            }, 2000);
        });
    }

    function closeEventStream() {
        if (state.streamReconnectTimer !== null) {
            window.clearTimeout(state.streamReconnectTimer);
            state.streamReconnectTimer = null;
        }
        if (state.eventSource) {
            state.eventSource.close();
            state.eventSource = null;
        }
    }

    function applyRuntimeEvent(event) {
        if (event.lastEventId) {
            state.lastEventId = Number(event.lastEventId) || state.lastEventId;
        }

        let data = null;
        try {
            data = event.data ? JSON.parse(event.data) : null;
        } catch (error) {
            return;
        }
        if (!data || !data.type) {
            return;
        }

        if (data.type === 'worker.updated') {
            state.worker = {
                ...(data.payload || {}),
                stale: false,
            };
            scheduleLiveRender({ worker: true });
            return;
        }

        if (data.type === 'batch.created' || data.type === 'batch.updated') {
            upsertBatch(data.payload || {});
            scheduleLiveRender({ batches: true });
            return;
        }

        if (data.type === 'batch.deleted') {
            removeBatch(data.entity_id || (data.payload && data.payload.id) || '');
            scheduleLiveRender({ batches: true });
        }
    }

    function scheduleLiveRender(flags = {}) {
        state.liveRenderFlags.worker = state.liveRenderFlags.worker || Boolean(flags.worker);
        state.liveRenderFlags.batches = state.liveRenderFlags.batches || Boolean(flags.batches);

        if (state.liveRenderTimer !== null) {
            return;
        }

        state.liveRenderTimer = window.setTimeout(() => {
            state.liveRenderTimer = null;
            const renderFlags = { ...state.liveRenderFlags };
            state.liveRenderFlags.worker = false;
            state.liveRenderFlags.batches = false;

            if (renderFlags.worker) {
                renderWorkerBanner();
            }
            if (renderFlags.batches) {
                renderBatches();
            }
        }, 250);
    }

    function upsertBatch(batch) {
        if (!batch || batch.id === undefined || batch.id === null) {
            return;
        }

        const index = state.batches.findIndex((entry) => Number(entry.id) === Number(batch.id));
        if (index >= 0) {
            state.batches[index] = { ...state.batches[index], ...batch };
        } else {
            state.batches.push(batch);
        }
        sortBatches();
    }

    function removeBatch(batchId) {
        if (!batchId) {
            return;
        }
        state.batches = state.batches.filter((batch) => String(batch.id) !== String(batchId));
    }

    function mergeOlderBatches(batches) {
        batches.forEach((batch) => {
            if (!state.batches.some((entry) => Number(entry.id) === Number(batch.id))) {
                state.batches.push(batch);
            }
        });
        sortBatches();
    }

    function sortBatches() {
        state.batches.sort((left, right) => Number(right.id || 0) - Number(left.id || 0));
    }

    function renderOutputSuggestions() {
        clear(els.outputRoots);
        clear(els.outputHistory);

        Object.values(state.roots.output || {}).forEach((root) => {
            els.outputRoots.appendChild(createElement('option', { attrs: { value: root } }));
        });

        (state.editorOptions.output_history || []).forEach((folder) => {
            els.outputHistory.appendChild(createElement('option', { attrs: { value: folder } }));
        });
    }

    function renderSourceBrowser() {
        clear(els.sourceRootSwitcher);
        clear(els.sourceBreadcrumbs);
        clear(els.sourceFolderList);

        const activeFolder = state.batchSettings.sourceFolder || state.currentSourceFolder || '';
        els.selectedSourceFolder.textContent = activeFolder || 'Noch kein Quellordner gewählt.';
        els.sourceFolderDisplay.value = state.batchSettings.sourceFolder || '';

        const roots = Object.entries(state.roots.input || {});
        if (roots.length === 0) {
            els.sourceRootSwitcher.appendChild(createEmptyState('Keine Input-Roots konfiguriert.'));
            els.sourceFolderList.appendChild(createEmptyState('Keine Input-Roots verfügbar.'));
            return;
        }

        roots.forEach(([name, path]) => {
            els.sourceRootSwitcher.appendChild(createElement('button', {
                className: 'root-pill' + ((state.sourceBrowser && state.sourceBrowser.root_path === path) ? ' active' : ''),
                text: name,
                attrs: { type: 'button' },
                dataset: { rootPath: path },
            }));
        });

        if (state.sourceBrowser === null) {
            els.sourceFolderList.appendChild(createEmptyState(canUseBatch()
                ? 'Ordner werden geladen.'
                : 'Bitte anmelden, um Batch-Verarbeitung zu verwenden.'));
            return;
        }

        (state.sourceBrowser.breadcrumbs || []).forEach((crumb, index) => {
            els.sourceBreadcrumbs.appendChild(createElement('button', {
                className: 'crumb-button' + (index === state.sourceBrowser.breadcrumbs.length - 1 ? ' active' : ''),
                text: crumb.name,
                attrs: { type: 'button' },
                dataset: { folderPath: crumb.path },
            }));
        });

        const folders = state.sourceBrowser.folders || [];
        if (folders.length === 0) {
            els.sourceFolderList.appendChild(createEmptyState('Keine Unterordner vorhanden.'));
            return;
        }

        const fragment = document.createDocumentFragment();
        folders.forEach((folder) => {
            const row = createElement('div', {
                className: 'browser-entry folder-entry list-row' + (folder.is_parent ? ' subtle' : ''),
                dataset: { folderPath: folder.path },
            });
            row.appendChild(createElement('span', {
                className: 'browser-entry-title',
                text: folder.name,
            }));
            if (folder.is_parent) {
                row.appendChild(createElement('span', {
                    className: 'browser-entry-meta',
                    text: 'Zurück',
                }));
            }
            fragment.appendChild(row);
        });
        els.sourceFolderList.appendChild(fragment);
    }

    function renderBatchForm() {
        syncTemplateSelect(els.batchTemplateSelect, state.batchSettings.templateId);
        els.sourceFolderDisplay.value = state.batchSettings.sourceFolder || '';

        formField('recursive').checked = Boolean(state.batchSettings.recursive);
        formField('output_folder').value = state.batchSettings.outputFolder;
        formField('label').value = state.batchSettings.label;
        formField('variant').value = state.batchSettings.variant;
    }

    function renderBatchPreview() {
        clear(els.batchPreviewTableBody);
        if (state.batchPreviewItems.length === 0) {
            els.batchPreviewTableBody.appendChild(createTableEmptyRow(8, 'Noch keine Batch-Vorschau geladen.'));
            return;
        }

        state.batchPreviewItems.forEach((item, index) => {
            const row = createElement('tr', { dataset: { previewIndex: index } });
            row.className = hasValidationLevel(item.validation_messages, 'error') ? 'row-warning' : '';
            row.appendChild(createElement('td', {}, [
                createElement('input', {
                    attrs: { type: 'checkbox' },
                    dataset: { previewField: 'enabled' },
                    checked: Boolean(item.enabled),
                }),
            ]));
            row.appendChild(createElement('td', { text: item.analysis.file_name || basename(item.source_path) }));
            row.appendChild(createElement('td', {}, [
                createElement('div', {
                    className: 'path-cell',
                    text: item.relative_source_path || basename(item.source_path),
                }),
            ]));
            row.appendChild(createElement('td', {}, [
                createElement('input', {
                    attrs: { type: 'text' },
                    dataset: { previewField: 'title' },
                    value: item.title || '',
                }),
            ]));
            row.appendChild(createElement('td', { className: 'batch-copy-cell' }, [
                createElement('button', {
                    className: 'ghost-button batch-copy-title-button',
                    text: '->',
                    attrs: {
                        type: 'button',
                        title: 'Titel als Zieldatei uebernehmen',
                        'aria-label': 'Titel als Zieldatei uebernehmen',
                    },
                    dataset: { action: 'copy-title-to-output-file', previewIndex: index },
                }),
            ]));
            row.appendChild(createElement('td', {}, [
                createElement('input', {
                    attrs: { type: 'text' },
                    dataset: { previewField: 'output_file' },
                    value: item.output_file || '',
                }),
            ]));

            const outputFolderCell = createElement('td');
            outputFolderCell.appendChild(createElement('div', { className: 'field-action-row compact-field-action-row' }, [
                createElement('input', {
                    attrs: { type: 'text', list: 'outputHistory' },
                    dataset: { previewField: 'output_folder' },
                    value: item.output_folder || '',
                }),
                createElement('button', {
                    className: 'ghost-button',
                    text: 'Wählen',
                    attrs: { type: 'button' },
                    dataset: { action: 'choose-row-output-folder', previewIndex: index },
                }),
            ]));
            if (item.default_output_folder && item.default_output_folder !== item.output_folder) {
                outputFolderCell.appendChild(createElement('div', {
                    className: 'subtle-row',
                    text: `Default: ${item.default_output_folder}`,
                }));
            }
            row.appendChild(outputFolderCell);
            row.appendChild(createElement('td', {
                className: hasValidationLevel(item.validation_messages, 'error') ? 'warning-copy' : 'muted-copy',
                text: formatValidationMessages(item.validation_messages),
            }));
            els.batchPreviewTableBody.appendChild(row);
        });
    }

    function renderBatches() {
        clear(els.batchesTableBody);
        if (state.batches.length === 0) {
            els.batchesTableBody.appendChild(createTableEmptyRow(6, 'Noch keine Batches vorhanden.'));
            if (els.loadOlderBatchesBtn) {
                els.loadOlderBatchesBtn.disabled = true;
            }
            return;
        }

        state.batches.forEach((batch) => {
            const summary = batch.summary || {};
            const row = createElement('tr', { dataset: { batchId: batch.id } });
            row.appendChild(createElement('td', { text: batch.label || '' }));
            row.appendChild(createElement('td', {}, [createTypeBadge(batch.status || 'queued')]));
            const sourceCell = createElement('td');
            sourceCell.appendChild(buildPathCell(batch.source_folder || '', basename(batch.source_folder || ''), batch.source_folder || ''));
            if (batch.recursive) {
                sourceCell.appendChild(createElement('div', { className: 'subtle-row', text: 'rekursiv' }));
            }
            row.appendChild(sourceCell);
            row.appendChild(createElement('td', {}, [
                buildPathCell(batch.output_folder || '', basename(batch.output_folder || ''), batch.output_folder || ''),
            ]));
            row.appendChild(createElement('td', {
                text: [
                    summary.job_count ? `Jobs ${summary.job_count}` : '',
                    summary.running ? `Running ${summary.running}` : '',
                    summary.queued ? `Queued ${summary.queued}` : '',
                    summary.failed ? `Failed ${summary.failed}` : '',
                    summary.completed ? `Done ${summary.completed}` : '',
                ].filter(Boolean).join(' · '),
            }));
            const actionCell = createElement('td');
            const actions = createElement('div', { className: 'job-actions' });
            actions.appendChild(createButton('Batch abbrechen', { action: 'cancel' }));
            actions.appendChild(createButton('Fehler neu starten', { action: 'retry_failed' }));
            if (!['running', 'cancel_requested'].includes(batch.status || '')) {
                actions.appendChild(createButton('Batch löschen', { action: 'delete' }));
            }
            actionCell.appendChild(actions);
            row.appendChild(actionCell);
            els.batchesTableBody.appendChild(row);
        });

        if (els.loadOlderBatchesBtn) {
            els.loadOlderBatchesBtn.disabled = !state.nextBatchBeforeId;
        }
    }

    function renderOutputFolderModal() {
        const modalState = state.outputFolderModal;
        const browser = modalState.browser;
        const roots = Object.entries(state.roots.output || {});

        els.outputFolderModal.classList.toggle('hidden', !modalState.open);
        els.outputFolderModal.setAttribute('aria-hidden', modalState.open ? 'false' : 'true');

        if (!modalState.open) {
            return;
        }

        els.outputFolderCurrentPath.textContent = modalState.folder || 'Kein Output-Root ausgewählt.';

        clear(els.outputRootSwitcher);
        roots.forEach(([name, path]) => {
            els.outputRootSwitcher.appendChild(createElement('button', {
                className: 'root-pill' + (path === browser?.root_path ? ' active' : ''),
                text: name,
                attrs: { type: 'button' },
                dataset: { outputRoot: path },
            }));
        });

        clear(els.outputBreadcrumbs);
        (browser?.breadcrumbs || []).forEach((crumb) => {
            els.outputBreadcrumbs.appendChild(createElement('button', {
                className: 'crumb-button' + (crumb.path === browser.folder ? ' active' : ''),
                text: crumb.name,
                attrs: { type: 'button' },
                dataset: { outputFolder: crumb.path },
            }));
        });

        clear(els.outputFolderList);
        const folders = browser?.folders || [];
        if (folders.length === 0) {
            els.outputFolderList.appendChild(createEmptyState('Keine Unterordner vorhanden.'));
        } else {
            const fragment = document.createDocumentFragment();
            folders.forEach((folder) => {
                const row = createElement('div', { className: 'browser-entry' });
                row.appendChild(createElement('div', { className: 'browser-entry-content' }, [
                    createElement('div', { className: 'browser-entry-titleline' }, [
                        createElement('a', {
                            className: 'file-open-link browser-entry-title',
                            text: folder.name,
                            attrs: { href: '#' },
                            dataset: { outputFolder: folder.path },
                        }),
                    ]),
                ]));
                fragment.appendChild(row);
            });
            els.outputFolderList.appendChild(fragment);
        }

        els.selectOutputFolderBtn.disabled = !modalState.folder;
    }

    async function loadSourceBrowser(folderPath, options = {}) {
        const params = new URLSearchParams();
        if (folderPath) {
            params.set('folder', folderPath);
        }

        const data = await appCommon.fetchJson(`query/api/workspace/browser.php?${params.toString()}`);
        state.sourceBrowser = data.browser;
        state.currentSourceFolder = data.browser.folder || folderPath || '';
        renderSourceBrowser();
    }

    async function loadBatchPreview(preserveExisting) {
        syncBatchSettingsFromForm();
        if (!state.batchSettings.sourceFolder && state.currentSourceFolder) {
            state.batchSettings.sourceFolder = state.currentSourceFolder;
            renderBatchForm();
        }

        if (!state.batchSettings.sourceFolder) {
            renderAuthBanner('Bitte zuerst einen Quellordner wählen.');
            return;
        }

        try {
            const payload = {
                csrf_token: state.csrfToken,
                source_folder: state.batchSettings.sourceFolder,
                recursive: Boolean(state.batchSettings.recursive),
                output_folder: state.batchSettings.outputFolder,
                template_id: Number(state.batchSettings.templateId || firstTemplateId()),
                variant: state.batchSettings.variant,
            };

            if (
                preserveExisting &&
                state.batchPreviewItems.length > 0 &&
                state.lastLoadedBatchFolder === state.batchSettings.sourceFolder
            ) {
                payload.items = serializePreviewItems();
            }

            const data = await appCommon.fetchJson('query/api/batch-preview.php', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            state.batchPreviewItems = data.items || [];
            state.lastLoadedBatchFolder = state.batchSettings.sourceFolder;
            renderBatchPreview();
            renderAuthBanner('Batch-Vorschau geladen.');
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleBatchCreate(event) {
        event.preventDefault();
        syncBatchSettingsFromForm();

        try {
            await loadBatchPreview(true);
        } catch (error) {
            renderAuthBanner(error.message);
            return;
        }

        if (state.batchPreviewItems.length === 0) {
            renderAuthBanner('Bitte zuerst eine Batch-Vorschau erzeugen.');
            return;
        }

        if (state.batchPreviewItems.some((item) => Boolean(item.enabled) && hasValidationLevel(item.validation_messages, 'error'))) {
            renderAuthBanner('Mindestens ein aktiver Batch-Eintrag enthaelt noch Fehler.');
            return;
        }

        try {
            await appCommon.fetchJson('query/api/batches.php', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    label: state.batchSettings.label || null,
                    source_folder: state.batchSettings.sourceFolder,
                    recursive: Boolean(state.batchSettings.recursive),
                    output_folder: state.batchSettings.outputFolder,
                    template_id: Number(state.batchSettings.templateId || firstTemplateId()),
                    variant: state.batchSettings.variant,
                    items: serializePreviewItems(),
                }),
            });

            renderAuthBanner('Batch angelegt.');
            state.batchPreviewItems = [];
            await refreshBatchSnapshot();
            renderWorkerBanner();
            renderBatches();
            syncEventStream();
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function applyCurrentOutputFolderToAll() {
        syncBatchSettingsFromForm();
        if (!state.batchSettings.outputFolder) {
            renderAuthBanner('Bitte zuerst einen Ausgabeordner wählen.');
            return;
        }

        if (state.batchPreviewItems.length === 0) {
            renderAuthBanner('Der Ausgabeordner ist als globaler Default gespeichert. Nach dem Einlesen kann er auf alle Dateien angewendet werden.');
            return;
        }

        state.batchPreviewItems.forEach((item) => {
            item.output_folder = state.batchSettings.outputFolder;
            item.overrides = item.overrides || {};
            item.overrides.output_folder = true;
        });

        try {
            await loadBatchPreview(true);
            renderAuthBanner('Ausgabeordner auf alle Dateien angewendet.');
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    function handleBatchPreviewChange(event) {
        const row = event.target.closest('tr[data-preview-index]');
        if (!row) {
            return;
        }

        const item = state.batchPreviewItems[Number(row.dataset.previewIndex)];
        if (!item) {
            return;
        }

        const field = event.target.dataset.previewField;
        if (!field) {
            return;
        }

        if (field === 'enabled') {
            item.enabled = Boolean(event.target.checked);
            return;
        }

        const value = event.target.value || '';
        item[field] = value;
        item.overrides = item.overrides || {};
        item.overrides[field] = value.trim() !== '';
    }

    async function handleBatchPreviewClick(event) {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        event.preventDefault();
        if (button.dataset.action === 'copy-title-to-output-file') {
            applyTitleToOutputFile(button);
            return;
        }

        if (button.dataset.action === 'choose-row-output-folder') {
            try {
                await openOutputFolderModal(`row:${button.dataset.previewIndex}`);
            } catch (error) {
                renderAuthBanner(error.message);
            }
        }
    }

    async function handleBatchAction(event) {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const row = button.closest('tr[data-batch-id]');
        if (!row) {
            return;
        }

        try {
            await appCommon.fetchJson('query/api/batch-action.php', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    id: Number(row.dataset.batchId),
                    action: button.dataset.action,
                }),
            });
            await refreshBatchSnapshot();
            renderAuthBanner('Batch aktualisiert.');
            renderWorkerBanner();
            renderBatches();
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    function syncBatchSettingsFromForm() {
        state.batchSettings.recursive = Boolean(formField('recursive').checked);
        state.batchSettings.outputFolder = formField('output_folder').value;
        state.batchSettings.label = formField('label').value;
        state.batchSettings.templateId = formField('template_id').value;
        state.batchSettings.variant = formField('variant').value;
        state.batchSettings.sourceFolder = els.sourceFolderDisplay.value || state.batchSettings.sourceFolder;
    }

    async function handleBatchFormClick(event) {
        const button = event.target.closest('button[data-action="choose-output-folder"]');
        if (!button) {
            return;
        }

        event.preventDefault();
        try {
            await openOutputFolderModal(button.dataset.target || 'batch');
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function openOutputFolderModal(target) {
        state.outputFolderModal.open = true;
        state.outputFolderModal.target = target;
        state.outputFolderModal.folder = outputFolderValueForTarget(target);
        await loadOutputFolderBrowser(state.outputFolderModal.folder || firstOutputRoot(), { force: true });
        renderOutputFolderModal();
    }

    async function loadOutputFolderBrowser(folderPath) {
        const params = new URLSearchParams();
        if (folderPath) {
            params.set('folder', folderPath);
        }

        const data = await appCommon.fetchJson(`query/api/workspace/output-browser.php?${params.toString()}`);
        state.outputFolderModal.browser = data.browser;
        state.outputFolderModal.folder = data.browser.folder || folderPath || '';
        renderOutputFolderModal();
    }

    function applySelectedOutputFolder() {
        const folder = state.outputFolderModal.folder;
        if (!folder) {
            return;
        }

        if (state.outputFolderModal.target === 'batch') {
            state.batchSettings.outputFolder = folder;
            renderBatchForm();
        } else if (String(state.outputFolderModal.target).startsWith('row:')) {
            const index = Number(String(state.outputFolderModal.target).split(':')[1]);
            const item = state.batchPreviewItems[index];
            if (item) {
                item.output_folder = folder;
                item.overrides = item.overrides || {};
                item.overrides.output_folder = true;
                renderBatchPreview();
            }
        }

        closeOutputFolderModal();
    }

    function closeOutputFolderModal() {
        state.outputFolderModal.open = false;
        state.outputFolderModal.target = null;
        state.outputFolderModal.folder = '';
        state.outputFolderModal.browser = null;
        renderOutputFolderModal();
    }

    async function handleOutputFolderCreate(event) {
        event.preventDefault();
        const formData = new FormData(els.outputFolderCreateForm);
        try {
            const data = await appCommon.fetchJson('query/api/workspace/output-folder-create.php', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    parent_folder: state.outputFolderModal.folder || firstOutputRoot(),
                    folder_name: formData.get('folder_name'),
                }),
            });
            els.outputFolderCreateForm.reset();
            state.outputFolderModal.folder = data.folder_path;
            state.outputFolderModal.browser = data.browser;
            renderOutputFolderModal();
            renderAuthBanner('Ausgabeordner angelegt.');
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleAuthSubmit(event) {
        if (event.target.id !== 'authLoginForm') {
            return;
        }

        event.preventDefault();
        try {
            const formData = new FormData(event.target);
            const data = await appCommon.fetchJson('query/api/auth/login.php', {
                method: 'POST',
                body: JSON.stringify({
                    password: formData.get('password'),
                }),
            });
            state.csrfToken = data.csrf_token;
            await refreshState('Angemeldet.');
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleAuthClick(event) {
        const button = event.target.closest('button[data-action="logout"]');
        if (!button) {
            return;
        }

        try {
            await appCommon.fetchJson('query/api/auth/logout.php', {
                method: 'POST',
                body: JSON.stringify({ csrf_token: state.csrfToken }),
            });
            await refreshState('Abgemeldet.');
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleSourceRootClick(event) {
        const button = event.target.closest('button[data-root-path]');
        if (!button) {
            return;
        }

        try {
            await loadSourceBrowser(button.dataset.rootPath);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleSourceBreadcrumbClick(event) {
        const target = event.target.closest('[data-folder-path]');
        if (!target) {
            return;
        }

        try {
            await loadSourceBrowser(target.dataset.folderPath);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleSourceFolderClick(event) {
        const target = event.target.closest('[data-folder-path]');
        if (!target) {
            return;
        }

        try {
            await loadSourceBrowser(target.dataset.folderPath);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleLoadOlderBatches() {
        if (!state.nextBatchBeforeId) {
            return;
        }

        try {
            await refreshBatchSnapshot({ append: true, beforeId: state.nextBatchBeforeId });
            renderBatches();
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleOutputRootClick(event) {
        const target = event.target.closest('[data-output-root]');
        if (!target) {
            return;
        }

        try {
            await loadOutputFolderBrowser(target.dataset.outputRoot);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleOutputBreadcrumbClick(event) {
        const target = event.target.closest('[data-output-folder]');
        if (!target) {
            return;
        }

        event.preventDefault();
        try {
            await loadOutputFolderBrowser(target.dataset.outputFolder);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleOutputFolderClick(event) {
        const target = event.target.closest('[data-output-folder]');
        if (!target) {
            return;
        }

        event.preventDefault();
        try {
            await loadOutputFolderBrowser(target.dataset.outputFolder);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    function handleOutputModalBackdropClick(event) {
        if (event.target === els.outputFolderModal) {
            closeOutputFolderModal();
        }
    }

    function handleGlobalKeydown(event) {
        if (event.key === 'Escape' && state.outputFolderModal.open) {
            closeOutputFolderModal();
        }
    }

    function handleVisibilityChange() {
        if (document.hidden) {
            closeEventStream();
            return;
        }

        if (state.authConfigured && !state.authenticated) {
            return;
        }

        refreshBatchSnapshot()
            .then(() => {
                renderWorkerBanner();
                renderBatches();
            })
            .catch((error) => {
                renderAuthBanner(error.message);
            })
            .finally(() => {
                syncEventStream();
            });
    }

    function useCurrentSourceFolder() {
        const folder = state.currentSourceFolder || (state.sourceBrowser && state.sourceBrowser.root_path) || '';
        if (!folder) {
            renderAuthBanner('Bitte zuerst einen Input-Root oder Ordner wählen.');
            return;
        }

        state.batchSettings.sourceFolder = folder;
        renderBatchForm();
        renderSourceBrowser();
        renderAuthBanner('Quellordner übernommen.');
    }

    function ensureBatchDefaults() {
        if ((!state.batchSettings.templateId || !templateExists(state.batchSettings.templateId)) && state.templates.length > 0) {
            state.batchSettings.templateId = String(state.templates[0].id);
        }

        if (!state.batchSettings.outputFolder) {
            state.batchSettings.outputFolder = firstOutputRoot();
        }
    }

    function serializePreviewItems() {
        return state.batchPreviewItems.map((item) => ({
            enabled: Boolean(item.enabled),
            source_path: item.source_path,
            relative_source_path: item.relative_source_path,
            title: item.title || '',
            output_file: item.output_file || '',
            output_folder: item.output_folder || '',
            overrides: {
                title: Boolean(item.overrides && item.overrides.title),
                output_file: Boolean(item.overrides && item.overrides.output_file),
                output_folder: Boolean(item.overrides && item.overrides.output_folder),
            },
        }));
    }

    function outputFolderValueForTarget(target) {
        if (target === 'batch') {
            return state.batchSettings.outputFolder || firstOutputRoot();
        }

        if (String(target).startsWith('row:')) {
            const index = Number(String(target).split(':')[1]);
            const item = state.batchPreviewItems[index];
            return (item && item.output_folder) || state.batchSettings.outputFolder || firstOutputRoot();
        }

        return firstOutputRoot();
    }

    function syncTemplateSelect(select, value) {
        clear(select);
        if (state.templates.length === 0) {
            select.appendChild(createElement('option', { text: 'Keine Templates', attrs: { value: '' } }));
            return;
        }

        state.templates.forEach((template) => {
            select.appendChild(createElement('option', {
                text: template.name,
                attrs: { value: template.id },
                value: template.id,
            }));
        });
        select.value = value || String(state.templates[0].id);
    }

    function formField(name) {
        return els.batchForm.elements.namedItem(name);
    }

    function templateExists(templateId) {
        return state.templates.some((template) => String(template.id) === String(templateId));
    }

    function firstTemplateId() {
        return state.templates.length > 0 ? String(state.templates[0].id) : '';
    }

    function firstInputRoot() {
        return Object.values(state.roots.input || {})[0] || '';
    }

    function firstOutputRoot() {
        return Object.values(state.roots.output || {})[0] || '';
    }

    function canUseBatch() {
        return !state.authConfigured || state.authenticated;
    }

    function buildPathCell(fullPath, primaryText, secondaryText = '') {
        const wrapper = createElement('div', {
            className: 'path-cell',
            attrs: { title: fullPath || secondaryText || primaryText || '' },
        });

        wrapper.appendChild(createElement('div', {
            className: 'path-main',
            text: primaryText || '–',
        }));

        if (secondaryText && secondaryText !== primaryText) {
            wrapper.appendChild(createElement('div', {
                className: 'path-subtle',
                text: secondaryText,
            }));
        }

        return wrapper;
    }

    function formatValidationMessages(messages) {
        const list = (messages || []).map((message) => message.message).filter(Boolean);
        return list.length > 0 ? list.join(' · ') : '';
    }

    function hasValidationLevel(messages, level) {
        return (messages || []).some((message) => message.level === level);
    }

    function basename(path) {
        return String(path || '').split('/').pop() || path;
    }

    function extensionFromName(fileName) {
        const value = String(fileName || '').trim();
        const match = value.match(/\.([a-z0-9]{2,6})$/i);
        return match ? match[1].toLowerCase() : '';
    }

    function normalizeOutputFileName(baseName, extension) {
        let fileName = String(baseName || '').trim();
        fileName = fileName.replace(/[\\/]/g, '-');
        fileName = fileName.replace(/[\x00-\x1f<>:"|?*]+/g, '-');
        fileName = fileName.replace(/\s+/g, ' ').trim().replace(/^[.\s]+|[.\s]+$/g, '');

        if (fileName === '') {
            fileName = 'output';
        }

        const normalizedExtension = String(extension || '').replace(/^\.+/, '').toLowerCase() || 'mkv';
        if (!extensionFromName(fileName)) {
            fileName += '.' + normalizedExtension;
        }

        return fileName;
    }

    function outputExtensionForItem(item) {
        return (
            extensionFromName(item.output_file) ||
            extensionFromName(item.resolved_names && item.resolved_names.output_file) ||
            extensionFromName(item.analysis && item.analysis.file_name) ||
            extensionFromName(item.source_path) ||
            'mkv'
        );
    }

    function applyTitleToOutputFile(button) {
        const index = Number(button.dataset.previewIndex);
        const item = state.batchPreviewItems[index];
        if (!item) {
            return;
        }

        item.output_file = normalizeOutputFileName(item.title || '', outputExtensionForItem(item));
        item.overrides = item.overrides || {};
        item.overrides.output_file = true;

        const row = button.closest('tr[data-preview-index]');
        const outputInput = row ? row.querySelector('input[data-preview-field="output_file"]') : null;
        if (outputInput instanceof HTMLInputElement) {
            outputInput.value = item.output_file;
        }
    }

    function createTableEmptyRow(colspan, text) {
        const row = createElement('tr');
        row.appendChild(createElement('td', {
            className: 'empty-row',
            text,
            attrs: { colspan: colspan },
        }));
        return row;
    }

    function createEmptyState(text) {
        return createElement('div', {
            className: 'empty-inline',
            text,
        });
    }

    function createTypeBadge(text) {
        return createElement('span', {
            className: 'pill',
            text: String(text || '').replaceAll('_', ' '),
        });
    }

    function createButton(text, dataset = {}) {
        return createElement('button', {
            text,
            attrs: { type: 'button' },
            dataset,
        });
    }

    function createElement(tagName, options = {}, children = []) {
        const element = document.createElement(tagName);

        if (options.className) {
            element.className = options.className;
        }
        if (options.text !== undefined) {
            element.textContent = options.text;
        }
        if (options.value !== undefined) {
            element.value = String(options.value);
        }
        if (options.checked !== undefined) {
            element.checked = Boolean(options.checked);
        }
        if (options.disabled !== undefined) {
            element.disabled = Boolean(options.disabled);
        }
        if (options.dataset) {
            Object.entries(options.dataset).forEach(([key, value]) => {
                if (value !== null && value !== undefined) {
                    element.dataset[key] = String(value);
                }
            });
        }
        if (options.attrs) {
            Object.entries(options.attrs).forEach(([key, value]) => {
                if (value === null || value === undefined || value === false) {
                    return;
                }
                if (value === true) {
                    element.setAttribute(key, '');
                    return;
                }
                element.setAttribute(key, String(value));
            });
        }

        const normalizedChildren = Array.isArray(children) ? children : [children];
        normalizedChildren.forEach((child) => {
            if (child === null || child === undefined) {
                return;
            }
            if (typeof child === 'string') {
                element.appendChild(document.createTextNode(child));
                return;
            }
            element.appendChild(child);
        });

        return element;
    }

    function clear(element) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }
})();
