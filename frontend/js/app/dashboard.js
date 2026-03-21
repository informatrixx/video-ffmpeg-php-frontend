(function () {
    const state = {
        csrfToken: null,
        authConfigured: false,
        authenticated: false,
        worker: null,
        roots: { input: {}, output: {} },
        editorOptions: {},
        templates: [],
        jobs: [],
        batches: [],
        browser: null,
        browserCache: new Map(),
        currentFolder: null,
        browserFilter: '',
        workspace: null,
        workspaceDraft: {
            templateId: '',
            variant: '',
        },
        workspaceDirty: false,
        cropPreviews: {},
        selectedBatchPaths: new Set(),
        batchSettings: {
            outputFolder: '',
            label: '',
            templateId: '',
            variant: '',
        },
        batchPreviewItems: [],
        eventSource: null,
        lastEventId: 0,
        cloneCounter: 0,
        workspaceTabs: {
            video: '',
            audio: '',
            subtitle: '',
        },
        outputFolderModal: {
            open: false,
            target: null,
            folder: '',
            browser: null,
        },
        lastStateRefreshAt: 0,
        liveRenderTimer: null,
        streamReconnectTimer: null,
        liveRenderFlags: {
            worker: false,
            jobs: false,
            batches: false,
        },
    };

    const els = {};

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        cacheElements();
        bindEvents();
        try {
            await refreshState();
        } catch (error) {
            renderAuthBanner(error.message || 'Frontend konnte nicht initialisiert werden.');
            renderBrowser();
        }
    }

    function cacheElements() {
        els.authBanner = document.getElementById('authBanner');
        els.workerBanner = document.getElementById('workerBanner');
        els.rootSwitcher = document.getElementById('rootSwitcher');
        els.breadcrumbs = document.getElementById('breadcrumbs');
        els.folderList = document.getElementById('folderList');
        els.fileList = document.getElementById('fileList');
        els.browserFilterInput = document.getElementById('browserFilterInput');
        els.refreshBrowserBtn = document.getElementById('refreshBrowserBtn');
        els.clearBatchBtn = document.getElementById('clearBatchBtn');
        els.batchSelectionBar = document.getElementById('batchSelectionBar');
        els.workspaceSubtitle = document.getElementById('workspaceSubtitle');
        els.workspaceEmpty = document.getElementById('workspaceEmpty');
        els.workspaceView = document.getElementById('workspaceView');
        els.reloadWorkspaceBtn = document.getElementById('reloadWorkspaceBtn');
        els.queueWorkspaceBtn = document.getElementById('queueWorkspaceBtn');
        els.batchForm = document.getElementById('batchForm');
        els.previewBatchBtn = document.getElementById('previewBatchBtn');
        els.batchTemplateSelect = document.getElementById('batchTemplateSelect');
        els.batchPreviewTableBody = document.querySelector('#batchPreviewTable tbody');
        els.jobsList = document.getElementById('jobsList');
        els.batchesTableBody = document.querySelector('#batchesTable tbody');
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
        els.rootSwitcher.addEventListener('click', handleRootClick);
        els.breadcrumbs.addEventListener('click', handleBreadcrumbClick);
        els.folderList.addEventListener('click', handleFolderClick);
        els.fileList.addEventListener('click', handleFileClick);
        els.fileList.addEventListener('change', handleFileSelectionChange);
        els.browserFilterInput.addEventListener('input', handleBrowserFilterInput);
        els.refreshBrowserBtn.addEventListener('click', async () => {
            try {
                await loadBrowser(state.currentFolder || firstInputRoot(), { force: true });
            } catch (error) {
                renderAuthBanner(error.message);
            }
        });
        els.workspaceView.addEventListener('input', handleWorkspaceLiveInput);
        els.workspaceView.addEventListener('change', handleWorkspaceInput);
        els.workspaceView.addEventListener('click', handleWorkspaceClick);
        els.reloadWorkspaceBtn.addEventListener('click', async () => {
            try {
                await reloadCurrentWorkspace();
            } catch (error) {
                renderAuthBanner(error.message);
            }
        });
        els.queueWorkspaceBtn.addEventListener('click', async () => {
            try {
                await queueCurrentWorkspace();
            } catch (error) {
                renderAuthBanner(error.message);
            }
        });
        els.jobsList.addEventListener('click', handleJobAction);
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

        if (els.clearBatchBtn) {
            els.clearBatchBtn.addEventListener('click', clearBatchSelection);
        }

        if (els.batchForm) {
            els.batchForm.addEventListener('input', syncBatchSettingsFromForm);
            els.batchForm.addEventListener('change', syncBatchSettingsFromForm);
            els.batchForm.addEventListener('submit', handleBatchCreate);
            els.batchForm.addEventListener('click', handleBatchFormClick);
        }

        if (els.previewBatchBtn) {
            els.previewBatchBtn.addEventListener('click', handleBatchPreview);
        }

        if (els.batchPreviewTableBody) {
            els.batchPreviewTableBody.addEventListener('input', handleBatchPreviewChange);
            els.batchPreviewTableBody.addEventListener('change', handleBatchPreviewChange);
        }

        if (els.batchesTableBody) {
            els.batchesTableBody.addEventListener('click', handleBatchAction);
        }
    }

    async function refreshState(message = '') {
        await refreshBootstrap();
        await refreshLiveSnapshot();

        ensureBatchDefaults();
        renderAuthBanner(message);
        renderWorkerBanner();
        renderOutputSuggestions();
        renderOutputFolderModal();
        renderRootSwitcher();
        renderJobs();
        renderWorkspace();
        syncEventStream();

        if (els.batchForm) {
            renderBatchForm();
        }
        if (els.batchSelectionBar) {
            renderBatchSelectionBar();
        }
        if (els.batchesTableBody) {
            renderBatches();
        }

        if (!canUseWorkspace()) {
            state.browser = null;
            renderBrowser();
            return;
        }

        if (state.browser === null) {
            const initialFolder = state.currentFolder || firstInputRoot();
            if (initialFolder !== '') {
                try {
                await loadBrowser(initialFolder);
                } catch (error) {
                    state.browser = null;
                    renderBrowser();
                    renderAuthBanner(error.message);
                }
            } else {
                renderBrowser();
            }
        } else {
            renderBrowser();
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

    async function refreshLiveSnapshot() {
        if (state.authConfigured && !state.authenticated) {
            state.worker = null;
            state.jobs = [];
            state.batches = [];
            return;
        }

        const data = await appCommon.fetchJson('query/api/dashboard-snapshot.php');
        state.worker = data.worker || null;
        state.jobs = Array.isArray(data.jobs) ? data.jobs.slice() : [];
        state.batches = Array.isArray(data.batches) ? data.batches.slice() : [];
        sortJobs();
        sortBatches();
        state.lastEventId = Number(data.last_event_id || state.lastEventId || 0);
        state.lastStateRefreshAt = Date.now();
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
            els.authBanner.appendChild(createButton('Logout', {
                action: 'logout',
            }));
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
        const jobId = worker.job_id ? ` · Job ${worker.job_id}` : '';
        const staleSuffix = worker.stale ? ' · stale' : '';
        const source = worker.source ? ` · ${worker.source}` : '';
        const message = worker.message ? ` · ${worker.message}` : '';
        els.workerBanner.className = 'worker-banner' + ((stateLabel === 'failed' || worker.stale) ? ' warning' : '');
        els.workerBanner.textContent = `Worker: ${stateLabel}${jobId}${source}${staleSuffix} · Aktualisiert: ${updatedAt}${message}`;
    }

    function renderOutputSuggestions(history = []) {
        clear(els.outputRoots);
        clear(els.outputHistory);

        Object.values(state.roots.output || {}).forEach((root) => {
            els.outputRoots.appendChild(createElement('option', { attrs: { value: root } }));
        });

        history.forEach((folder) => {
            els.outputHistory.appendChild(createElement('option', { attrs: { value: folder } }));
        });
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

    function renderRootSwitcher() {
        clear(els.rootSwitcher);
        const roots = Object.entries(state.roots.input || {});
        if (roots.length === 0) {
            els.rootSwitcher.appendChild(createEmptyState('Keine Input-Roots konfiguriert.'));
            return;
        }

        roots.forEach(([name, path]) => {
            const button = createElement('button', {
                className: 'root-pill' + ((state.browser && state.browser.root_path === path) ? ' active' : ''),
                text: name,
                attrs: { type: 'button' },
                dataset: { rootPath: path },
            });
            els.rootSwitcher.appendChild(button);
        });
    }

    function renderBrowser() {
        clear(els.breadcrumbs);
        clear(els.folderList);
        clear(els.fileList);
        els.browserFilterInput.value = state.browserFilter;

        if (state.browser === null) {
            if (!canUseWorkspace()) {
                els.folderList.appendChild(createEmptyState('Bitte anmelden, um den Explorer und den Workspace zu verwenden.'));
                els.fileList.appendChild(createEmptyState('Nach dem Login wird der Inhalt der Input-Roots geladen.'));
            } else {
                els.folderList.appendChild(createEmptyState('Ordner werden geladen.'));
                els.fileList.appendChild(createEmptyState('Noch keine Dateiliste geladen.'));
            }
            return;
        }

        if ((state.browser.breadcrumbs || []).length > 0) {
            state.browser.breadcrumbs.forEach((crumb, index) => {
                const button = createElement('button', {
                    className: 'crumb-button' + (index === state.browser.breadcrumbs.length - 1 ? ' active' : ''),
                    text: crumb.name,
                    attrs: { type: 'button' },
                    dataset: { folderPath: crumb.path },
                });
                els.breadcrumbs.appendChild(button);
            });
        } else {
            els.breadcrumbs.appendChild(createElement('span', {
                className: 'muted-copy',
                text: 'Wähle eine Input-Root.',
            }));
        }

        const filter = state.browserFilter.trim().toLowerCase();
        const folders = (state.browser.folders || []).filter((folder) => matchesBrowserFilter(filter, folder.name, folder.path));
        if (folders.length === 0) {
            els.folderList.appendChild(createEmptyState(filter ? 'Keine passenden Ordner gefunden.' : 'Keine Unterordner vorhanden.'));
        } else {
            const folderFragment = document.createDocumentFragment();
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
                folderFragment.appendChild(row);
            });
            els.folderList.appendChild(folderFragment);
        }

        const files = (state.browser.files || []).filter((file) => matchesBrowserFilter(filter, file.name, file.path));
        if (files.length === 0) {
            els.fileList.appendChild(createEmptyState(filter ? 'Keine passenden Dateien gefunden.' : 'Keine verarbeitbaren Dateien im aktuellen Ordner.'));
            return;
        }

        const fileFragment = document.createDocumentFragment();
        files.forEach((file) => {
            const row = createElement('div', {
                className: 'browser-entry file-entry list-row' + (isFileActive(file) ? ' active' : ''),
            });

            const content = createElement('div', { className: 'browser-entry-content' });
            const titleLine = createElement('div', { className: 'browser-entry-titleline' });
            const titleControl = isOpenableFile(file)
                ? createElement('a', {
                    className: 'file-open-link browser-entry-title',
                    text: file.name,
                    attrs: { href: '#' },
                    dataset: { openFile: file.path },
                })
                : createElement('span', {
                    className: 'browser-entry-title',
                    text: file.name,
                });
            titleLine.appendChild(titleControl);
            titleLine.appendChild(createTypeBadge(file.scan_type || (file.joinable ? 'join' : 'datei')));
            content.appendChild(titleLine);

            const metaParts = [file.size_human || ''];
            if (file.grouped_files && file.grouped_files.length > 0) {
                metaParts.push(`${file.grouped_files.length} Parts`);
            }
            content.appendChild(createElement('div', {
                className: 'browser-entry-meta',
                text: metaParts.filter(Boolean).join(' · '),
            }));

            if (file.grouped_files && file.grouped_files.length > 0) {
                content.appendChild(createElement('div', {
                    className: 'browser-entry-note',
                    text: `${file.grouped_files.length} verknüpfte Parts`,
                }));
            }

            row.appendChild(content);

            const actions = createElement('div', { className: 'browser-entry-actions' });
            if (isOpenableFile(file)) {
                actions.appendChild(createElement('a', {
                    className: 'action-link',
                    text: file.scan_type === 'rar' ? 'Archiv' : 'Öffnen',
                    attrs: { href: '#' },
                    dataset: { openFile: file.path },
                }));
            }

            if (canJoinFile(file)) {
                actions.appendChild(createElement('a', {
                    className: 'action-link',
                    text: isJoinedPath(file.path) ? 'Joined' : 'Join',
                    attrs: { href: '#' },
                    dataset: { joinFile: file.path },
                    disabled: isJoinedPath(file.path),
                }));
            }

            row.appendChild(actions);
            fileFragment.appendChild(row);
        });
        els.fileList.appendChild(fileFragment);
    }

    function renderBatchSelectionBar() {
        if (!els.batchSelectionBar) {
            return;
        }
        clear(els.batchSelectionBar);
        const count = state.selectedBatchPaths.size;
        if (count === 0) {
            els.batchSelectionBar.className = 'selection-strip subtle';
            els.batchSelectionBar.textContent = 'Noch keine Batch-Dateien markiert.';
            return;
        }

        els.batchSelectionBar.className = 'selection-strip';
        els.batchSelectionBar.appendChild(createElement('span', {
            text: `${count} Datei${count === 1 ? '' : 'en'} für Batch markiert.`,
        }));
    }

    function renderBatchForm() {
        if (!els.batchForm || !els.batchTemplateSelect) {
            return;
        }
        syncTemplateSelect(els.batchTemplateSelect, state.batchSettings.templateId);
        formField('output_folder').value = state.batchSettings.outputFolder;
        formField('label').value = state.batchSettings.label;
        formField('variant').value = state.batchSettings.variant;
        renderBatchPreview();
    }

    function renderBatchPreview() {
        if (!els.batchPreviewTableBody) {
            return;
        }
        clear(els.batchPreviewTableBody);
        if (state.batchPreviewItems.length === 0) {
            els.batchPreviewTableBody.appendChild(createTableEmptyRow(8, state.selectedBatchPaths.size === 0
                ? 'Batch-Dateien direkt in der Ordneransicht markieren.'
                : 'Noch keine Batch-Vorschau geladen.'));
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
                createElement('input', {
                    attrs: { type: 'text' },
                    dataset: { previewField: 'title' },
                    value: item.title || '',
                }),
            ]));
            row.appendChild(createElement('td', {}, [
                createElement('input', {
                    attrs: { type: 'text' },
                    dataset: { previewField: 'output_file' },
                    value: item.output_file || '',
                }),
            ]));
            row.appendChild(createElement('td', {}, [
                createElement('input', {
                    attrs: { type: 'text' },
                    dataset: { previewField: 'output_folder' },
                    value: item.output_folder || '',
                }),
            ]));
            row.appendChild(createElement('td', {
                text: `${item.analysis.video_streams}/${item.analysis.audio_streams}/${item.analysis.subtitle_streams}`,
            }));
            row.appendChild(createElement('td', {
                text: `${item.plan_summary.video_outputs}/${item.plan_summary.audio_outputs}/${item.plan_summary.subtitle_outputs}`,
            }));
            row.appendChild(createElement('td', {
                className: hasValidationLevel(item.validation_messages, 'error') ? 'warning-copy' : 'muted-copy',
                text: formatValidationMessages(item.validation_messages),
            }));
            els.batchPreviewTableBody.appendChild(row);
        });
    }

    function renderWorkspace() {
        clear(els.workspaceView);

        if (!state.workspace) {
            els.workspaceEmpty.hidden = false;
            els.workspaceSubtitle.textContent = 'Datei im Explorer öffnen, Scan laden und den finalen Plan bearbeiten.';
            els.reloadWorkspaceBtn.disabled = true;
            els.queueWorkspaceBtn.disabled = true;
            els.queueWorkspaceBtn.textContent = 'In Queue legen';
            renderOutputSuggestions();
            return;
        }

        syncWorkspacePlanMeta();
        els.workspaceEmpty.hidden = true;
        els.reloadWorkspaceBtn.disabled = false;
        els.queueWorkspaceBtn.disabled = false;

        if (state.workspace.mode === 'rar') {
            els.workspaceSubtitle.textContent = `${state.workspace.analysis.file_name} · Archiv mit ${(state.workspace.analysis.archive_files || []).length} Einträgen`;
            els.queueWorkspaceBtn.textContent = 'Extract in Queue legen';
            renderOutputSuggestions(state.workspace.output_history || []);
            renderArchiveWorkspace();
            return;
        }

        const info = state.workspace.analysis.info || {};
        els.workspaceSubtitle.textContent = [
            state.workspace.analysis.file_name,
            info.duration_human || '00:00:00',
            `${info.video_stream_count || 0}V/${info.audio_stream_count || 0}A/${info.subtitle_stream_count || 0}S`,
            state.workspaceDirty ? 'Template/Variante geändert, Plan neu anwenden.' : '',
        ].filter(Boolean).join(' · ');
        els.queueWorkspaceBtn.textContent = 'Video in Queue legen';
        renderOutputSuggestions(state.workspace.output_history || []);
        renderVideoWorkspace();
    }

    function renderVideoWorkspace() {
        const plan = state.workspace.plan;
        const templateInfo = plan.template || {};
        const options = state.workspace.editor_options || {};
        const wrapper = createElement('div', { className: 'workspace-stack' });

        const planCard = createElement('section', { className: 'workspace-card' });
        planCard.appendChild(createSectionHeading('Plan', 'Template, Variante und globale Jobdaten.'));
        const planGrid = createElement('div', { className: 'workspace-form-grid' });
        planGrid.appendChild(buildLabeledField('Template', buildTemplateSelect(state.workspaceDraft.templateId)));
        planGrid.appendChild(buildLabeledField('Variante', createElement('input', {
            attrs: { type: 'text', list: 'workspaceVariants', placeholder: 'high / low / series1' },
            dataset: { workspaceControl: 'variant' },
            value: state.workspaceDraft.variant || '',
        })));
        planGrid.appendChild(buildLabeledField('Template-Version', createElement('div', {
            className: 'readonly-value',
            text: `${templateInfo.name || 'Template'} · v${templateInfo.version || '?'}`,
        })));
        planGrid.appendChild(buildLabeledField('Ausgabeordner', buildOutputFolderControl('workspace', plan.job.output_folder || '')));
        planGrid.appendChild(buildLabeledField('Titel', createElement('input', {
            attrs: { type: 'text', placeholder: 'Titel' },
            dataset: { jobField: 'title' },
            value: plan.job.title || '',
        })));
        planGrid.appendChild(buildLabeledField('Zieldatei', createElement('input', {
            attrs: { type: 'text', placeholder: 'output.mkv' },
            dataset: { jobField: 'output_file' },
            value: plan.job.output_file || '',
        })));
        planCard.appendChild(planGrid);
        planCard.appendChild(buildVariantDatalist(options.templates && options.templates.variants ? Object.keys(options.templates.variants) : []));
        wrapper.appendChild(planCard);
        wrapper.appendChild(renderPlanPreviewCard(plan, 'video'));

        const scanCard = createElement('section', { className: 'workspace-card' });
        scanCard.appendChild(createSectionHeading('Scan-Ergebnis', 'Streams aller aktiven Eingaben mit Herkunftsdatei. Zusätzliche Inputs lassen sich hier wieder entfernen.'));
        const analyses = workspaceAnalysisMap();
        (plan.inputs || []).forEach((input) => {
            const analysis = analyses[input.input_id];
            if (analysis) {
                scanCard.appendChild(renderInputAnalysis(analysis, input));
            }
        });
        wrapper.appendChild(scanCard);

        const videoCard = createElement('section', { className: 'workspace-card' });
        videoCard.appendChild(createSectionHeading('Video Outputs', 'Genau ein Video-Output bleibt aktiv.'));
        videoCard.appendChild(renderTabbedOutputs(
            'video',
            plan.video_outputs || [],
            (output) => renderVideoOutput(output, options.video || {}),
            'Keine Video-Outputs vorhanden.'
        ));
        wrapper.appendChild(videoCard);

        const audioCard = createElement('section', { className: 'workspace-card' });
        audioCard.appendChild(createSectionHeading('Audio Outputs', 'Audio-Spuren können kopiert, encodiert oder dupliziert werden.'));
        audioCard.appendChild(createElement('div', { className: 'button-row' }, [
            createElement('button', {
                className: 'ghost-button',
                text: 'Auto-Titel für Audio',
                attrs: { type: 'button' },
                dataset: { action: 'auto-title-scope', scope: 'audio' },
            }),
        ]));
        audioCard.appendChild(renderTabbedOutputs(
            'audio',
            plan.audio_outputs || [],
            (output) => renderAudioOutput(output, options.audio || {}),
            'Keine Audio-Outputs vorhanden.'
        ));
        wrapper.appendChild(audioCard);

        const subtitleCard = createElement('section', { className: 'workspace-card' });
        subtitleCard.appendChild(createSectionHeading('Subtitle Outputs', 'Untertitel können pro Stream aktiviert oder deaktiviert werden.'));
        subtitleCard.appendChild(createElement('div', { className: 'button-row' }, [
            createElement('button', {
                className: 'ghost-button',
                text: 'Auto-Titel für Untertitel',
                attrs: { type: 'button' },
                dataset: { action: 'auto-title-scope', scope: 'subtitle' },
            }),
        ]));
        subtitleCard.appendChild(renderTabbedOutputs(
            'subtitle',
            plan.subtitle_outputs || [],
            (output) => renderSubtitleOutput(output),
            'Keine Subtitle-Outputs vorhanden.'
        ));
        wrapper.appendChild(subtitleCard);

        els.workspaceView.appendChild(wrapper);
    }

    function renderArchiveWorkspace() {
        const plan = state.workspace.plan;
        const wrapper = createElement('div', { className: 'workspace-stack' });

        const configCard = createElement('section', { className: 'workspace-card' });
        configCard.appendChild(createSectionHeading('Archiv', 'Zielordner und Extract-Optionen für das aktuelle RAR-Archiv.'));
        const configGrid = createElement('div', { className: 'workspace-form-grid' });
        configGrid.appendChild(buildLabeledField('Ausgabeordner', buildOutputFolderControl('workspace', plan.job.output_folder || '')));
        configGrid.appendChild(buildLabeledField('Titel', createElement('input', {
            attrs: { type: 'text', placeholder: 'Optionaler Titel' },
            dataset: { jobField: 'title' },
            value: plan.job.title || '',
        })));
        configGrid.appendChild(buildLabeledField('Pfadstruktur', createCheckboxField('ignore_paths', 'Unterordner im Archiv ignorieren', Boolean(plan.options.ignore_paths))));
        configGrid.appendChild(buildLabeledField('Überschreiben', createCheckboxField('overwrite', 'Bestehende Dateien überschreiben', Boolean(plan.options.overwrite))));
        configCard.appendChild(configGrid);
        wrapper.appendChild(configCard);
        wrapper.appendChild(renderPlanPreviewCard(plan, 'rar'));

        const fileCard = createElement('section', { className: 'workspace-card' });
        fileCard.appendChild(createSectionHeading('Archivinhalt', 'Einzelne Einträge können direkt für den Extract-Job ausgewählt werden.'));
        const table = createElement('table', { className: 'data-table compact-table' });
        const thead = createElement('thead');
        const headerRow = createElement('tr');
        ['Aktiv', 'Datei', 'Größe', 'Datum'].forEach((label) => headerRow.appendChild(createElement('th', { text: label })));
        thead.appendChild(headerRow);
        table.appendChild(thead);
        const tbody = createElement('tbody');
        (plan.archive_entries || []).forEach((entry, index) => {
            const row = createElement('tr');
            row.appendChild(createElement('td', {}, [
                createElement('input', {
                    attrs: { type: 'checkbox' },
                    dataset: { archiveEntry: String(index) },
                    checked: Boolean(entry.enabled),
                }),
            ]));
            row.appendChild(createElement('td', { text: entry.entry_name || entry.file_name || '' }));
            row.appendChild(createElement('td', { text: entry.size_human || '' }));
            row.appendChild(createElement('td', { text: entry.date || '' }));
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        fileCard.appendChild(table);
        wrapper.appendChild(fileCard);

        els.workspaceView.appendChild(wrapper);
    }

    function renderPlanPreviewCard(plan, mode) {
        const resolved = plan.resolved_names || {};
        const messages = plan.validation_messages || [];
        const card = createElement('section', { className: 'workspace-card' });
        card.appendChild(createSectionHeading('Plan Preview', 'Finaler Titel, Zieldatei und aktuelle Validierung fuer den aktiven Entwurf.'));

        const previewGrid = createElement('div', { className: 'workspace-form-grid' });
        previewGrid.appendChild(buildLabeledField('Finaler Titel', createElement('div', {
            className: 'readonly-value',
            text: resolved.title || '–',
        })));
        previewGrid.appendChild(buildLabeledField('Output-Pfad', createElement('div', {
            className: 'readonly-value path-preview',
            text: resolved.output_path || '–',
        })));
        previewGrid.appendChild(buildLabeledField('Aktive Outputs', createElement('div', {
            className: 'readonly-value',
            text: mode === 'video'
                ? `${enabledOutputs(plan.video_outputs)}V · ${enabledOutputs(plan.audio_outputs)}A · ${enabledOutputs(plan.subtitle_outputs)}S`
                : `${enabledArchiveEntries(plan.archive_entries)} Eintraege`,
        })));
        previewGrid.appendChild(buildLabeledField('Hinweise', createElement('div', {
            className: 'readonly-value',
            text: formatValidationMessages(messages) || 'Keine Hinweise.',
        })));
        card.appendChild(previewGrid);

        if (messages.length > 0) {
            const messageList = createElement('div', { className: 'validation-list' });
            messages.forEach((message) => {
                messageList.appendChild(createElement('div', {
                    className: `validation-pill ${message.level || 'info'}`,
                    text: message.message || '',
                }));
            });
            card.appendChild(messageList);
        }

        return card;
    }

    function syncWorkspacePlanMeta() {
        if (!state.workspace || !state.workspace.plan) {
            return;
        }

        const plan = state.workspace.plan;
        const analysis = state.workspace.analysis || {};
        const titleFallback = (analysis.info && analysis.info.title) || analysis.base_name || basename(analysis.file || '') || 'output';
        const outputFolder = (plan.job && plan.job.output_folder) || firstOutputRoot();
        let outputFile = String((plan.job && plan.job.output_file) || '').trim();
        if (!outputFile) {
            const base = analysis.base_name || basename(analysis.file || '').replace(/\.[^.]+$/, '') || 'output';
            outputFile = `${base}.mkv`;
        } else if (!/\.[^.]+$/.test(outputFile)) {
            outputFile = `${outputFile}.mkv`;
        }

        plan.job.title = String((plan.job && plan.job.title) || titleFallback).trim();
        plan.job.output_file = outputFile;
        plan.job.output_folder = outputFolder;
        plan.resolved_names = {
            title: plan.job.title,
            output_folder: outputFolder,
            output_file: outputFile,
            output_path: outputFolder ? `${outputFolder}/${outputFile}` : outputFile,
        };

        const messages = [];
        if (state.workspace.mode === 'video') {
            const enabledVideo = enabledOutputs(plan.video_outputs);
            if (enabledVideo === 0) {
                messages.push({ level: 'error', message: 'Mindestens ein Video-Output muss aktiv sein.' });
            } else if (enabledVideo > 1) {
                messages.push({ level: 'error', message: 'Es darf nur ein aktiver Video-Output vorhanden sein.' });
            }
            if (enabledOutputs(plan.audio_outputs) === 0) {
                messages.push({ level: 'warning', message: 'Aktuell ist keine Audio-Spur aktiv.' });
            }
        }

        plan.validation_messages = dedupeValidationMessages([
            ...(Array.isArray(plan.validation_messages) ? plan.validation_messages : []),
            ...messages,
        ]);
    }

    function renderInputAnalysis(analysis, input = null) {
        const info = analysis.info || {};
        const block = createElement('article', { className: 'analysis-block' });
        const header = createElement('div', { className: 'analysis-header' });
        const heading = createElement('div', { className: 'analysis-heading' });
        heading.appendChild(createElement('h3', {
            text: `${analysis.file_name} · ${info.duration_human || '00:00:00'} · ${info.size_human || ''}`,
        }));
        if (input && input.role !== 'primary') {
            heading.appendChild(createElement('p', {
                className: 'analysis-meta',
                text: 'Zusätzlicher Input',
            }));
        }
        header.appendChild(heading);
        if (input && input.role !== 'primary') {
            header.appendChild(createElement('button', {
                className: 'ghost-button',
                text: 'Input entfernen',
                attrs: { type: 'button' },
                dataset: { action: 'remove-join', joinPath: input.source_path || analysis.file },
            }));
        }
        block.appendChild(header);
        block.appendChild(createElement('p', {
            className: 'analysis-meta',
            text: `${info.format_name || 'Unbekanntes Format'} · ${info.video_stream_count || 0} Video · ${info.audio_stream_count || 0} Audio · ${info.subtitle_stream_count || 0} Subtitle`,
        }));

        const streamList = createElement('div', { className: 'stream-list' });
        (analysis.streams.video || []).forEach((stream) => {
            streamList.appendChild(renderStreamRow('Video', `${stream.stream_index}`, `${stream.codec.name_uc} · ${stream.width}x${stream.height} · ${stream.display_aspect_ratio}`));
        });
        (analysis.streams.audio || []).forEach((stream) => {
            streamList.appendChild(renderStreamRow('Audio', `${stream.stream_index}`, `${stream.language.human} · ${stream.codec.name_uc} · ${stream.channels.layout}`));
        });
        (analysis.streams.subtitle || []).forEach((stream) => {
            streamList.appendChild(renderStreamRow('Subtitle', `${stream.stream_index}`, `${stream.language.human} · ${stream.codec.name_uc}`));
        });
        block.appendChild(streamList);
        return block;
    }

    function renderTabbedOutputs(scope, outputs, renderPanel, emptyMessage) {
        const tabset = createElement('div', { className: 'output-tabs' });
        if ((outputs || []).length === 0) {
            tabset.appendChild(createEmptyState(emptyMessage));
            return tabset;
        }

        const activeOutputId = ensureActiveOutputTab(scope, outputs);
        const activeOutput = outputs.find((output) => output.output_id === activeOutputId) || outputs[0];
        const tabRow = createElement('div', {
            className: 'output-tab-row',
            attrs: {
                role: 'tablist',
                'aria-label': `${scope}-outputs`,
            },
        });

        outputs.forEach((output, index) => {
            const active = output.output_id === activeOutput.output_id;
            tabRow.appendChild(createElement('button', {
                className: 'output-tab-button' + (active ? ' active' : ''),
                text: outputTabLabel(output, index),
                attrs: {
                    type: 'button',
                    role: 'tab',
                    'aria-selected': active ? 'true' : 'false',
                },
                dataset: {
                    action: 'select-output-tab',
                    scope,
                    outputId: output.output_id,
                },
            }));
        });

        tabset.appendChild(tabRow);
        tabset.appendChild(createElement('div', {
            className: 'output-tab-panel',
            attrs: { role: 'tabpanel' },
        }, [
            renderPanel(activeOutput),
        ]));
        return tabset;
    }

    function renderVideoOutput(output, videoOptions) {
        const codecOptions = videoOptions.codecs || {};
        const modeConfig = ((codecOptions[output.codec] || {}).modes || {});
        const nlmeansOptions = videoOptions.nlmeans || {};
        const card = createElement('article', { className: 'output-card' });
        const header = createElement('div', { className: 'output-card-head' });
        header.appendChild(createElement('label', { className: 'radio-label' }, [
            createElement('input', {
                attrs: { type: 'radio', name: 'activeVideoOutput' },
                dataset: { outputId: output.output_id, outputField: 'enabled' },
                checked: Boolean(output.enabled),
            }),
            createElement('span', { text: output.label || output.source_path || 'Video' }),
        ]));
        card.appendChild(header);

        const grid = createElement('div', { className: 'workspace-form-grid' });
        grid.appendChild(buildLabeledField('Codec', createSelect(output.output_id, 'codec', codecOptions, output.codec)));
        grid.appendChild(buildLabeledField('Mode', createSelect(output.output_id, 'mode', modeConfig, output.mode, 'name')));
        grid.appendChild(buildLabeledField('Wert', createElement('input', {
            attrs: { type: 'number', step: '0.1' },
            dataset: { outputId: output.output_id, outputField: 'mode_value' },
            value: output.mode_value ?? '',
        })));
        grid.appendChild(buildLabeledField('Preset', createSelect(output.output_id, 'preset', ((codecOptions[output.codec] || {}).settings || {}).preset || {}, output.preset)));
        grid.appendChild(buildLabeledField('Resize', createElement('input', {
            attrs: { type: 'text', placeholder: '0 / 720 / 1080 / 1920x1080' },
            dataset: { outputId: output.output_id, outputField: 'resize' },
            value: output.resize ?? '',
        })));
        grid.appendChild(buildLabeledField('Crop', createElement('input', {
            attrs: { type: 'text', placeholder: 'auto oder 1920:800:0:140' },
            dataset: { outputId: output.output_id, outputField: 'crop' },
            value: output.crop ?? '',
        })));
        grid.appendChild(buildLabeledField('NLMeans', createSelect(output.output_id, 'nlmeans', nlmeansOptions, output.nlmeans, 'name')));
        card.appendChild(grid);

        const cropBox = createElement('div', { className: 'crop-box' });
        cropBox.appendChild(createElement('div', {
            className: 'output-note',
            text: 'Crop-Preview lädt nur bei Bedarf. Ein Klick auf eine Zeitmarke holt ein Vorschaubild mit erkannter Crop-Empfehlung.',
        }));
        const buttons = createElement('div', { className: 'button-row crop-buttons' });
        previewSeeksForInput(output.input_id).forEach((seek, index) => {
            buttons.appendChild(createElement('button', {
                className: 'ghost-button',
                text: seek.label,
                attrs: { type: 'button' },
                dataset: {
                    action: 'crop-preview',
                    outputId: output.output_id,
                    seek: String(seek.seconds),
                },
            }));
        });
        cropBox.appendChild(buttons);

        const preview = state.cropPreviews[output.output_id];
        if (preview) {
            const previewWrap = createElement('div', { className: 'crop-preview' });
            if (preview.loading) {
                previewWrap.appendChild(createElement('div', { className: 'output-note', text: 'Crop-Preview wird geladen.' }));
            } else if (preview.error) {
                previewWrap.appendChild(createElement('div', { className: 'output-note warning-copy', text: preview.error }));
            } else {
                previewWrap.appendChild(createElement('img', {
                    className: 'crop-image',
                    attrs: { src: preview.image_url, alt: 'Crop Preview' },
                }));
                const detected = preview.crop && preview.crop.string ? preview.crop.string : 'Kein Crop erkannt';
                previewWrap.appendChild(createElement('div', {
                    className: 'output-note',
                    text: `${preview.seek_human} · ${detected}`,
                }));
                if (preview.crop && preview.crop.string) {
                    previewWrap.appendChild(createElement('button', {
                        className: 'ghost-button',
                        text: 'Erkannten Crop übernehmen',
                        attrs: { type: 'button' },
                        dataset: {
                            action: 'apply-detected-crop',
                            outputId: output.output_id,
                        },
                    }));
                }
            }
            cropBox.appendChild(previewWrap);
        }

        card.appendChild(cropBox);
        return card;
    }

    function renderAudioOutput(output, audioOptions) {
        const codec = output.codec || 'copy';
        const codecConfig = (audioOptions.codecs || {})[codec] || {};
        const profileOptions = codecConfig.profile || {};
        const isCopy = codec === 'copy' || output.action === 'copy';
        const card = createElement('article', { className: 'output-card' });
        const header = createElement('div', { className: 'output-card-head' });
        header.appendChild(createElement('label', { className: 'check-label' }, [
            createElement('input', {
                attrs: { type: 'checkbox' },
                dataset: { outputId: output.output_id, outputField: 'enabled' },
                checked: Boolean(output.enabled),
            }),
            createElement('span', { text: output.label || output.source_path || 'Audio' }),
        ]));
        const actions = createElement('div', { className: 'job-actions' });
        actions.appendChild(createElement('button', {
            className: 'ghost-button',
            text: 'Duplizieren',
            attrs: { type: 'button' },
            dataset: { action: 'duplicate-output', outputId: output.output_id },
        }));
        actions.appendChild(createElement('button', {
            className: 'ghost-button',
            text: 'Entfernen',
            attrs: { type: 'button' },
            dataset: { action: 'remove-output', outputId: output.output_id },
        }));
        header.appendChild(actions);
        card.appendChild(header);

        const grid = createElement('div', { className: 'workspace-form-grid' });
        grid.appendChild(buildLabeledField('Codec', createSelect(output.output_id, 'codec', audioOptions.codecs || {}, codec)));
        grid.appendChild(buildLabeledField('Titel', buildTrackTitleControl(output, 'Track-Titel')));
        if (!isCopy) {
            if (Object.keys(profileOptions).length > 0) {
                grid.appendChild(buildLabeledField('Profil', createSelect(
                    output.output_id,
                    'profile',
                    profileOptions,
                    output.profile || ''
                )));
            }
            grid.appendChild(buildLabeledField('Bitrate', createElement('input', {
                attrs: { type: 'text', placeholder: '256k' },
                dataset: { outputId: output.output_id, outputField: 'bitrate' },
                value: output.bitrate || '',
            })));
            grid.appendChild(buildLabeledField('Samplerate', createSelect(output.output_id, 'samplerate', listToMap(audioOptions.samplerate || []), output.samplerate || '')));
            grid.appendChild(buildLabeledField('Kanäle', createSelect(output.output_id, 'channels', audioOptions.channels || {}, output.channels || '')));
            grid.appendChild(buildLabeledField('Loudnorm', createSelect(output.output_id, 'loudnorm', audioOptions.loudnorm || {}, output.loudnorm || '0', 'name')));
        }
        grid.appendChild(buildLabeledField('Default', createCheckboxField('disposition_default', 'Default', Boolean(output.disposition_default), output.output_id)));
        grid.appendChild(buildLabeledField('Forced', createCheckboxField('disposition_forced', 'Forced', Boolean(output.disposition_forced), output.output_id)));
        card.appendChild(grid);
        return card;
    }

    function renderSubtitleOutput(output) {
        const card = createElement('article', { className: 'output-card' });
        const header = createElement('div', { className: 'output-card-head' });
        header.appendChild(createElement('label', { className: 'check-label' }, [
            createElement('input', {
                attrs: { type: 'checkbox' },
                dataset: { outputId: output.output_id, outputField: 'enabled' },
                checked: Boolean(output.enabled),
            }),
            createElement('span', { text: output.label || output.source_path || 'Subtitle' }),
        ]));
        card.appendChild(header);

        const grid = createElement('div', { className: 'workspace-form-grid' });
        grid.appendChild(buildLabeledField('Codec', createElement('input', {
            attrs: { type: 'text', readonly: true },
            value: output.codec || 'copy',
        })));
        grid.appendChild(buildLabeledField('Titel', buildTrackTitleControl(output, 'Subtitle-Titel')));
        grid.appendChild(buildLabeledField('Default', createCheckboxField('disposition_default', 'Default', Boolean(output.disposition_default), output.output_id)));
        grid.appendChild(buildLabeledField('Forced', createCheckboxField('disposition_forced', 'Forced', Boolean(output.disposition_forced), output.output_id)));
        card.appendChild(grid);
        return card;
    }

    function renderJobs() {
        clear(els.jobsList);
        if (state.jobs.length === 0) {
            els.jobsList.appendChild(createElement('div', {
                className: 'queue-empty',
                text: 'Queue ist leer.',
            }));
            return;
        }

        state.jobs.forEach((job) => {
            els.jobsList.appendChild(buildQueueCard(job));
        });
    }

    function renderBatches() {
        if (!els.batchesTableBody) {
            return;
        }
        clear(els.batchesTableBody);
        if (state.batches.length === 0) {
            els.batchesTableBody.appendChild(createTableEmptyRow(6, 'Noch keine Batches vorhanden.'));
            return;
        }

        state.batches.forEach((batch) => {
            const summary = batch.summary || {};
            const row = createElement('tr', { dataset: { batchId: batch.id } });
            row.appendChild(createElement('td', { text: batch.label || '' }));
            row.appendChild(createElement('td', {}, [createTypeBadge(batch.status || 'queued')]));
            row.appendChild(createElement('td', {}, [
                buildPathCell(batch.source_folder || '', basename(batch.source_folder || ''), batch.source_folder || ''),
            ]));
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
    }

    function sortJobs() {
        state.jobs.sort((left, right) => {
            const rankDiff = jobStatusRank(left.status) - jobStatusRank(right.status);
            if (rankDiff !== 0) {
                return rankDiff;
            }

            const positionDiff = Number(left.position || 0) - Number(right.position || 0);
            if (positionDiff !== 0) {
                return positionDiff;
            }

            return String(left.created_at || '').localeCompare(String(right.created_at || ''));
        });
    }

    function sortBatches() {
        state.batches.sort((left, right) => Number(right.id || 0) - Number(left.id || 0));
    }

    function jobStatusRank(status) {
        return ({
            running: 0,
            cancel_requested: 1,
            ready: 2,
            queued: 3,
            paused: 4,
            failed: 5,
            completed: 6,
            cancelled: 7,
        })[status] ?? 8;
    }

    async function loadBrowser(folderPath, options = {}) {
        const force = Boolean(options.force);
        const cacheKey = folderPath || '__root__';
        if (!force && state.browserCache.has(cacheKey)) {
            state.browser = state.browserCache.get(cacheKey);
            state.currentFolder = state.browser.folder || folderPath || '';
            renderRootSwitcher();
            renderBrowser();
            return;
        }

        const params = new URLSearchParams();
        if (folderPath) {
            params.set('folder', folderPath);
        }

        const data = await appCommon.fetchJson(`query/api/workspace/browser.php?${params.toString()}`);
        state.browserCache.set(cacheKey, data.browser);
        state.browser = data.browser;
        state.currentFolder = data.browser.folder || folderPath || '';
        renderRootSwitcher();
        renderBrowser();
    }

    async function openWorkspace(sourcePath) {
        const templateId = state.workspace && state.workspace.mode === 'video'
            ? state.workspaceDraft.templateId
            : (state.batchSettings.templateId || firstTemplateId());
        const outputFolder = state.workspace && state.workspace.plan && state.workspace.plan.job
            ? state.workspace.plan.job.output_folder
            : (state.batchSettings.outputFolder || firstOutputRoot());

        await loadWorkspace({
            sourcePath,
            joinedSources: [],
            templateId,
            variant: state.workspaceDraft.variant || state.batchSettings.variant,
            outputFolder,
        });
    }

    async function loadWorkspace(request) {
        const payload = {
            csrf_token: state.csrfToken,
            source_path: request.sourcePath,
            joined_sources: request.joinedSources || [],
            output_folder: request.outputFolder || firstOutputRoot(),
        };

        if (request.type) {
            payload.type = request.type;
        }

        const fileType = request.type || guessTypeFromPath(request.sourcePath);
        if (fileType !== 'rar') {
            payload.template_id = Number(request.templateId || firstTemplateId());
            payload.variant = request.variant || '';
        }

        const data = await appCommon.fetchJson('query/api/workspace/scan.php', {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        state.workspace = data.workspace;
        state.workspaceDirty = false;
        state.cropPreviews = {};
        state.workspaceTabs = {
            video: '',
            audio: '',
            subtitle: '',
        };
        state.workspaceDraft = {
            templateId: data.workspace.mode === 'video' ? String(data.workspace.plan.template.id || firstTemplateId()) : '',
            variant: data.workspace.mode === 'video' ? (data.workspace.plan.variant || '') : '',
        };

        if (request.outputFolder && state.workspace.plan && state.workspace.plan.job) {
            state.workspace.plan.job.output_folder = request.outputFolder;
        }

        syncBatchDefaultsFromWorkspace();
        renderWorkspace();
        await loadBrowser(state.currentFolder || firstInputRoot());
        renderAuthBanner(data.workspace.mode === 'rar' ? 'Archiv geladen.' : 'Scan geladen.');
    }

    async function reloadCurrentWorkspace() {
        if (!state.workspace) {
            return;
        }

        if (state.workspace.mode === 'rar') {
            await loadWorkspace({
                sourcePath: state.workspace.analysis.file,
                type: 'rar',
                outputFolder: state.workspace.plan.job.output_folder,
            });
            return;
        }

        await loadWorkspace({
            sourcePath: getPrimaryInput().source_path,
            joinedSources: getJoinedInputs().map((input) => input.source_path),
            templateId: state.workspaceDraft.templateId || firstTemplateId(),
            variant: state.workspaceDraft.variant,
            outputFolder: state.workspace.plan.job.output_folder,
            type: 'video',
        });
    }

    async function queueCurrentWorkspace() {
        if (!state.workspace) {
            return;
        }

        if (state.workspace.mode === 'video') {
            if (state.workspaceDirty) {
                renderAuthBanner('Template oder Variante wurden geändert. Bitte zuerst den Plan neu anwenden.');
                return;
            }
            if (hasValidationLevel(state.workspace.plan.validation_messages, 'error')) {
                renderAuthBanner('Der aktuelle Plan enthaelt noch Fehler. Bitte zuerst die Hinweise im Plan Preview beheben.');
                return;
            }

            await appCommon.fetchJson('query/api/workspace/queue.php', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    type: 'video',
                    plan: state.workspace.plan,
                }),
            });
            renderAuthBanner('Video-Job angelegt.');
            await refreshLiveSnapshot();
            renderWorkerBanner();
            renderJobs();
            renderBatches();
            return;
        }

        const selectedEntries = (state.workspace.plan.archive_entries || [])
            .filter((entry) => entry.enabled)
            .map((entry) => entry.entry_name);

        await appCommon.fetchJson('query/api/workspace/queue.php', {
            method: 'POST',
            body: JSON.stringify({
                csrf_token: state.csrfToken,
                type: 'rar',
                source_path: state.workspace.analysis.file,
                output_folder: state.workspace.plan.job.output_folder,
                title: state.workspace.plan.job.title,
                selected_entries: selectedEntries,
                ignore_paths: state.workspace.plan.options.ignore_paths,
                overwrite: state.workspace.plan.options.overwrite,
            }),
        });
        renderAuthBanner('Extract-Job angelegt.');
        await refreshLiveSnapshot();
        renderWorkerBanner();
        renderJobs();
        renderBatches();
    }

    async function addJoinSource(sourcePath) {
        if (!state.workspace || state.workspace.mode !== 'video') {
            renderAuthBanner('Bitte zuerst eine primäre Video-Datei scannen.');
            return;
        }

        const joined = getJoinedInputs().map((input) => input.source_path);
        if (!joined.includes(sourcePath)) {
            joined.push(sourcePath);
        }

        await loadWorkspace({
            sourcePath: getPrimaryInput().source_path,
            joinedSources: joined,
            templateId: state.workspaceDraft.templateId || firstTemplateId(),
            variant: state.workspaceDraft.variant,
            outputFolder: state.workspace.plan.job.output_folder,
            type: 'video',
        });
    }

    async function removeJoinSource(sourcePath) {
        const joined = getJoinedInputs()
            .map((input) => input.source_path)
            .filter((path) => path !== sourcePath);

        await loadWorkspace({
            sourcePath: getPrimaryInput().source_path,
            joinedSources: joined,
            templateId: state.workspaceDraft.templateId || firstTemplateId(),
            variant: state.workspaceDraft.variant,
            outputFolder: state.workspace.plan.job.output_folder,
            type: 'video',
        });
    }

    async function loadCropPreview(outputId, seek) {
        const output = findOutput(outputId);
        if (!output) {
            return;
        }

        state.cropPreviews[outputId] = { loading: true };
        renderWorkspace();

        try {
            const params = new URLSearchParams({
                file: output.source_path,
                seek: String(seek),
            });
            const data = await appCommon.fetchJson(`query/api/workspace/crop-preview.php?${params.toString()}`);
            state.cropPreviews[outputId] = data.preview;
        } catch (error) {
            state.cropPreviews[outputId] = { error: error.message };
        }

        renderWorkspace();
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

    async function handleRootClick(event) {
        const button = event.target.closest('button[data-root-path]');
        if (!button) {
            return;
        }

        try {
            await loadBrowser(button.dataset.rootPath);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleBreadcrumbClick(event) {
        const target = event.target.closest('[data-folder-path]');
        if (!target) {
            return;
        }

        if (typeof target.getAttribute === 'function' && target.getAttribute('href') === '#') {
            event.preventDefault();
        }

        try {
            await loadBrowser(target.dataset.folderPath);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleFolderClick(event) {
        const target = event.target.closest('[data-folder-path]');
        if (!target) {
            return;
        }

        if (typeof target.getAttribute === 'function' && target.getAttribute('href') === '#') {
            event.preventDefault();
        }

        try {
            await loadBrowser(target.dataset.folderPath);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleFileClick(event) {
        const openLink = event.target.closest('[data-open-file]');
        if (openLink) {
            if (typeof openLink.getAttribute === 'function' && openLink.getAttribute('href') === '#') {
                event.preventDefault();
            }
            try {
                await openWorkspace(openLink.dataset.openFile);
            } catch (error) {
                renderAuthBanner(error.message);
            }
            return;
        }

        const joinButton = event.target.closest('[data-join-file]');
        if (joinButton) {
            if (typeof joinButton.getAttribute === 'function' && joinButton.getAttribute('href') === '#') {
                event.preventDefault();
            }
            try {
                await addJoinSource(joinButton.dataset.joinFile);
            } catch (error) {
                renderAuthBanner(error.message);
            }
        }
    }

    function handleFileSelectionChange(event) {
        const checkbox = event.target.closest('input[data-batch-path]');
        if (!checkbox) {
            return;
        }

        if (checkbox.checked) {
            state.selectedBatchPaths.add(checkbox.dataset.batchPath);
        } else {
            state.selectedBatchPaths.delete(checkbox.dataset.batchPath);
        }

        state.batchPreviewItems = [];
        renderBatchSelectionBar();
        renderBatchPreview();
    }

    function handleBrowserFilterInput(event) {
        state.browserFilter = event.target.value || '';
        renderBrowser();
    }

    function handleWorkspaceInput(event) {
        if (!state.workspace) {
            return;
        }

        const target = event.target;

        if (target.dataset.workspaceControl === 'templateId') {
            state.workspaceDraft.templateId = target.value;
            state.workspaceDirty = true;
            renderWorkspace();
            return;
        }

        if (target.dataset.workspaceControl === 'variant') {
            state.workspaceDraft.variant = target.value;
            state.workspaceDirty = true;
            renderWorkspace();
            return;
        }

        if (target.dataset.jobField) {
            state.workspace.plan.job[target.dataset.jobField] = inputValue(target);
            return;
        }

        if (target.dataset.archiveOption) {
            state.workspace.plan.options[target.dataset.archiveOption] = Boolean(target.checked);
            return;
        }

        if (target.dataset.archiveEntry) {
            const index = Number(target.dataset.archiveEntry);
            if (state.workspace.plan.archive_entries[index]) {
                state.workspace.plan.archive_entries[index].enabled = Boolean(target.checked);
            }
            return;
        }

        if (!target.dataset.outputId || !target.dataset.outputField) {
            return;
        }

        const output = findOutput(target.dataset.outputId);
        if (!output) {
            return;
        }

        const field = target.dataset.outputField;
        if (field === 'enabled' && output.scope === 'video') {
            enableOnlyVideoOutput(output.output_id);
            renderWorkspace();
            return;
        }

        output[field] = inputValue(target);
        if (field === 'codec') {
            output.action = output.codec === 'copy' ? 'copy' : 'encode';
            renderWorkspace();
        }
    }

    function handleWorkspaceLiveInput(event) {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLTextAreaElement)) {
            return;
        }

        if (target.type === 'checkbox' || target.type === 'radio') {
            return;
        }

        handleWorkspaceInput(event);
    }

    async function handleWorkspaceClick(event) {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const action = button.dataset.action;
        if (action === 'remove-join') {
            await removeJoinSource(button.dataset.joinPath);
            return;
        }

        if (action === 'select-output-tab') {
            state.workspaceTabs[button.dataset.scope] = button.dataset.outputId;
            renderWorkspace();
            return;
        }

        if (action === 'duplicate-output') {
            duplicateOutput(button.dataset.outputId);
            return;
        }

        if (action === 'auto-title-output') {
            autoTitleOutput(button.dataset.outputId);
            return;
        }

        if (action === 'auto-title-scope') {
            autoTitleScope(button.dataset.scope);
            return;
        }

        if (action === 'choose-output-folder') {
            await openOutputFolderModal(button.dataset.target || 'workspace');
            return;
        }

        if (action === 'remove-output') {
            removeOutput(button.dataset.outputId);
            return;
        }

        if (action === 'crop-preview') {
            await loadCropPreview(button.dataset.outputId, Number(button.dataset.seek));
            return;
        }

        if (action === 'apply-detected-crop') {
            const preview = state.cropPreviews[button.dataset.outputId];
            const output = findOutput(button.dataset.outputId);
            if (preview && preview.crop && preview.crop.string && output) {
                output.crop = preview.crop.string;
                renderWorkspace();
            }
        }
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

    function handleOutputRootClick(event) {
        const button = event.target.closest('button[data-output-root]');
        if (!button) {
            return;
        }

        void loadOutputFolderBrowser(button.dataset.outputRoot);
    }

    function handleOutputBreadcrumbClick(event) {
        const button = event.target.closest('button[data-output-folder]');
        if (!button) {
            return;
        }

        void loadOutputFolderBrowser(button.dataset.outputFolder);
    }

    function handleOutputFolderClick(event) {
        const link = event.target.closest('[data-output-folder]');
        if (!link) {
            return;
        }

        event.preventDefault();
        void loadOutputFolderBrowser(link.dataset.outputFolder);
    }

    async function handleOutputFolderCreate(event) {
        event.preventDefault();
        const field = els.outputFolderCreateForm.elements.namedItem('folder_name');
        const folderName = field ? field.value.trim() : '';
        if (!folderName) {
            renderAuthBanner('Bitte zuerst einen Ordnernamen eingeben.');
            return;
        }

        try {
            const data = await appCommon.fetchJson('query/api/workspace/output-folder-create.php', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    parent_path: state.outputFolderModal.folder || firstOutputRoot(),
                    folder_name: folderName,
                }),
            });
            state.outputFolderModal.browser = data.browser || null;
            state.outputFolderModal.folder = data.folder_path || '';
            if (field) {
                field.value = '';
            }
            renderOutputFolderModal();
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function openOutputFolderModal(target) {
        state.outputFolderModal.open = true;
        state.outputFolderModal.target = target;
        state.outputFolderModal.folder = currentOutputFolderForTarget(target);
        state.outputFolderModal.browser = null;
        renderOutputFolderModal();
        await loadOutputFolderBrowser(state.outputFolderModal.folder || firstOutputRoot());
    }

    function closeOutputFolderModal() {
        state.outputFolderModal.open = false;
        state.outputFolderModal.target = null;
        renderOutputFolderModal();
    }

    async function loadOutputFolderBrowser(folder, options = {}) {
        const targetFolder = folder || firstOutputRoot();
        try {
            const url = new URL('query/api/workspace/output-browser.php', window.location.href);
            if (targetFolder) {
                url.searchParams.set('folder', targetFolder);
            }

            const data = await appCommon.fetchJson(url.toString(), {
                cache: options.force ? 'no-store' : 'default',
            });
            state.outputFolderModal.browser = data.browser || null;
            state.outputFolderModal.folder = (data.browser && data.browser.folder) || targetFolder;
            renderOutputFolderModal();
        } catch (error) {
            const fallbackRoot = firstOutputRoot();
            if (targetFolder && fallbackRoot && targetFolder !== fallbackRoot) {
                return loadOutputFolderBrowser(fallbackRoot, options);
            }

            throw error;
        }
    }

    function applySelectedOutputFolder() {
        const folder = state.outputFolderModal.folder;
        if (!folder) {
            return;
        }

        if (state.outputFolderModal.target === 'batch') {
            formField('output_folder').value = folder;
            state.batchSettings.outputFolder = folder;
        } else if (state.workspace && state.workspace.plan && state.workspace.plan.job) {
            state.workspace.plan.job.output_folder = folder;
            renderWorkspace();
        }

        closeOutputFolderModal();
    }

    function currentOutputFolderForTarget(target) {
        if (target === 'batch') {
            return formField('output_folder').value || state.batchSettings.outputFolder || firstOutputRoot();
        }

        if (state.workspace && state.workspace.plan && state.workspace.plan.job) {
            return state.workspace.plan.job.output_folder || firstOutputRoot();
        }

        return firstOutputRoot();
    }

    function syncBatchSettingsFromForm() {
        state.batchSettings.outputFolder = formField('output_folder').value;
        state.batchSettings.label = formField('label').value;
        state.batchSettings.templateId = formField('template_id').value;
        state.batchSettings.variant = formField('variant').value;
    }

    async function handleBatchPreview() {
        syncBatchSettingsFromForm();
        if (state.selectedBatchPaths.size === 0) {
            renderAuthBanner('Bitte zuerst Dateien in der Ordneransicht für den Batch markieren.');
            return;
        }

        try {
            const existingItems = state.batchPreviewItems.length > 0
                ? state.batchPreviewItems
                : Array.from(state.selectedBatchPaths).map((sourcePath) => ({
                    source_path: sourcePath,
                    enabled: true,
                }));
            const payload = {
                csrf_token: state.csrfToken,
                source_folder: state.currentFolder || firstInputRoot(),
                output_folder: state.batchSettings.outputFolder,
                template_id: Number(state.batchSettings.templateId || firstTemplateId()),
                variant: state.batchSettings.variant,
                items: existingItems.map((item) => ({
                    enabled: Boolean(item.enabled),
                    source_path: item.source_path,
                    title: item.title || '',
                    output_file: item.output_file || '',
                    output_folder: item.output_folder || '',
                })),
            };
            const data = await appCommon.fetchJson('query/api/batch-preview.php', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            state.batchPreviewItems = data.items || [];
            renderBatchPreview();
            renderAuthBanner('Batch-Vorschau geladen.');
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

        item[field] = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    }

    async function handleBatchCreate(event) {
        event.preventDefault();
        syncBatchSettingsFromForm();

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
                    source_folder: state.currentFolder || firstInputRoot(),
                    output_folder: state.batchSettings.outputFolder,
                    template_id: Number(state.batchSettings.templateId || firstTemplateId()),
                    variant: state.batchSettings.variant,
                    items: state.batchPreviewItems.map((item) => ({
                        enabled: Boolean(item.enabled),
                        source_path: item.source_path,
                        title: item.title,
                        output_file: item.output_file,
                        output_folder: item.output_folder,
                    })),
                }),
            });
            renderAuthBanner('Batch angelegt.');
            state.batchPreviewItems = [];
            clearBatchSelection();
            await refreshState();
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleJobAction(event) {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const jobCard = button.closest('[data-job-id]');
        if (!jobCard) {
            return;
        }

        try {
            await appCommon.fetchJson('query/api/job-action.php', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    id: jobCard.dataset.jobId,
                    action: button.dataset.action,
                }),
            });
            await refreshLiveSnapshot();
            renderWorkerBanner();
            renderJobs();
            renderBatches();
        } catch (error) {
            renderAuthBanner(error.message);
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
            await refreshLiveSnapshot();
            renderWorkerBanner();
            renderJobs();
            renderBatches();
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    function syncEventStream() {
        if (document.hidden || (state.authConfigured && !state.authenticated)) {
            closeEventStream();
            return;
        }

        if (state.eventSource) {
            return;
        }

        state.eventSource = new EventSource(`query/api/events/stream.php?scope=dashboard&after=${state.lastEventId}`);
        ['worker.updated', 'job.created', 'job.updated', 'job.deleted', 'batch.created', 'batch.updated', 'batch.deleted'].forEach((eventName) => {
            state.eventSource.addEventListener(eventName, (event) => {
                applyRuntimeEvent(event);
            });
        });
        state.eventSource.addEventListener('idle', async (event) => {
            if (event.lastEventId) {
                state.lastEventId = Number(event.lastEventId) || state.lastEventId;
            }
            if ((Date.now() - state.lastStateRefreshAt) > 15000) {
                await refreshLiveSnapshot();
                renderWorkerBanner();
                renderJobs();
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
                    await refreshLiveSnapshot();
                    renderWorkerBanner();
                    renderJobs();
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
            state.lastStateRefreshAt = Date.now();
            scheduleLiveRender({ worker: true });
            return;
        }

        if (data.type === 'job.created' || data.type === 'job.updated') {
            upsertJob(data.payload || {});
            state.lastStateRefreshAt = Date.now();
            scheduleLiveRender({ jobs: true });
            return;
        }

        if (data.type === 'job.deleted') {
            removeJob(data.entity_id || (data.payload && data.payload.id) || '');
            state.lastStateRefreshAt = Date.now();
            scheduleLiveRender({ jobs: true });
            return;
        }

        if (data.type === 'batch.created' || data.type === 'batch.updated') {
            upsertBatch(data.payload || {});
            state.lastStateRefreshAt = Date.now();
            scheduleLiveRender({ batches: true });
            return;
        }

        if (data.type === 'batch.deleted') {
            removeBatch(data.entity_id || (data.payload && data.payload.id) || '');
            state.lastStateRefreshAt = Date.now();
            scheduleLiveRender({ batches: true });
        }
    }

    function scheduleLiveRender(flags = {}) {
        state.liveRenderFlags.worker = state.liveRenderFlags.worker || Boolean(flags.worker);
        state.liveRenderFlags.jobs = state.liveRenderFlags.jobs || Boolean(flags.jobs);
        state.liveRenderFlags.batches = state.liveRenderFlags.batches || Boolean(flags.batches);

        if (state.liveRenderTimer !== null) {
            return;
        }

        state.liveRenderTimer = window.setTimeout(() => {
            state.liveRenderTimer = null;
            const renderFlags = { ...state.liveRenderFlags };
            state.liveRenderFlags.worker = false;
            state.liveRenderFlags.jobs = false;
            state.liveRenderFlags.batches = false;

            if (renderFlags.worker) {
                renderWorkerBanner();
            }
            if (renderFlags.jobs) {
                renderJobs();
            }
            if (renderFlags.batches && els.batchesTableBody) {
                renderBatches();
            }
        }, 250);
    }

    function upsertJob(job) {
        if (!job || !job.id) {
            return;
        }

        const index = state.jobs.findIndex((entry) => entry.id === job.id);
        if (index >= 0) {
            state.jobs[index] = { ...state.jobs[index], ...job };
        } else {
            state.jobs.push(job);
        }
        sortJobs();
    }

    function removeJob(jobId) {
        if (!jobId) {
            return;
        }
        state.jobs = state.jobs.filter((job) => job.id !== jobId);
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

    function handleVisibilityChange() {
        if (document.hidden) {
            closeEventStream();
            return;
        }

        if (state.authConfigured && !state.authenticated) {
            return;
        }

        refreshLiveSnapshot()
            .then(() => {
                renderWorkerBanner();
                renderJobs();
                if (els.batchesTableBody) {
                    renderBatches();
                }
            })
            .catch((error) => {
                renderAuthBanner(error.message);
            })
            .finally(() => {
                syncEventStream();
            });
    }

    function ensureBatchDefaults() {
        if ((!state.batchSettings.templateId || !templateExists(state.batchSettings.templateId)) && state.templates.length > 0) {
            state.batchSettings.templateId = String(state.templates[0].id);
        }
        if (!state.batchSettings.outputFolder) {
            state.batchSettings.outputFolder = firstOutputRoot();
        }
    }

    function syncBatchDefaultsFromWorkspace() {
        if (!state.workspace || !state.workspace.plan || !state.workspace.plan.job) {
            return;
        }
        if (!state.batchSettings.outputFolder) {
            state.batchSettings.outputFolder = state.workspace.plan.job.output_folder || firstOutputRoot();
        }
        if (state.workspace.mode === 'video' && !state.batchSettings.variant) {
            state.batchSettings.variant = state.workspace.plan.variant || '';
        }
        if (state.workspace.mode === 'video' && !templateExists(state.batchSettings.templateId) && state.workspace.plan.template) {
            state.batchSettings.templateId = String(state.workspace.plan.template.id);
        }
    }

    function templateExists(templateId) {
        return state.templates.some((template) => String(template.id) === String(templateId));
    }

    function firstInputRoot() {
        const roots = Object.values(state.roots.input || {});
        return roots.length > 0 ? roots[0] : '';
    }

    function firstOutputRoot() {
        const roots = Object.values(state.roots.output || {});
        return roots.length > 0 ? roots[0] : '';
    }

    function firstTemplateId() {
        return state.templates.length > 0 ? String(state.templates[0].id) : '';
    }

    function canUseWorkspace() {
        return !state.authConfigured || state.authenticated;
    }

    function formField(name) {
        const field = els.batchForm && els.batchForm.elements ? els.batchForm.elements.namedItem(name) : null;
        if (!field) {
            throw new Error(`Fehlendes Formularfeld: ${name}`);
        }

        return field;
    }

    function syncTemplateSelect(select, selectedValue) {
        clear(select);
        state.templates.forEach((template) => {
            const option = createElement('option', {
                text: `${template.name} (v${template.published_version || 'draft'})`,
                attrs: { value: template.id },
            });
            if (String(template.id) === String(selectedValue)) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function buildTemplateSelect(selectedValue) {
        const select = createElement('select', {
            dataset: { workspaceControl: 'templateId' },
        });
        syncTemplateSelect(select, selectedValue);
        return select;
    }

    function buildVariantDatalist(variants) {
        const list = createElement('datalist', { attrs: { id: 'workspaceVariants' } });
        variants.forEach((variant) => {
            list.appendChild(createElement('option', { attrs: { value: variant } }));
        });
        return list;
    }

    function createSelect(outputId, field, options, selectedValue, labelField = 'name') {
        const select = createElement('select', {
            dataset: outputId ? { outputId, outputField: field } : {},
        });
        let normalizedOptions = normalizeSelectOptions(options, labelField);
        if (normalizedOptions.length === 0 && selectedValue !== undefined && selectedValue !== null && selectedValue !== '') {
            normalizedOptions = [{
                value: String(selectedValue),
                label: String(selectedValue),
            }];
        }
        normalizedOptions.forEach((option) => {
            const element = createElement('option', {
                text: option.label,
                attrs: { value: option.value },
            });
            if (String(option.value) === String(selectedValue)) {
                element.selected = true;
            }
            select.appendChild(element);
        });
        return select;
    }

    function normalizeSelectOptions(options, labelField) {
        if (Array.isArray(options)) {
            return options.map((option) => ({
                value: String(option),
                label: String(option),
            }));
        }

        return Object.entries(options || {}).map(([value, config]) => {
            if (Array.isArray(config)) {
                return {
                    value,
                    label: String(config[0] || value),
                };
            }
            if (config && typeof config === 'object') {
                return {
                    value,
                    label: config[labelField] || config.name || value,
                };
            }
            return {
                value,
                label: String(config),
            };
        });
    }

    function createCheckboxField(field, label, checked, outputId = null) {
        const wrap = createElement('label', { className: 'inline-check' });
        wrap.appendChild(createElement('input', {
            attrs: { type: 'checkbox' },
            dataset: outputId ? { outputId, outputField: field } : { archiveOption: field },
            checked,
        }));
        wrap.appendChild(createElement('span', { text: label }));
        return wrap;
    }

    function buildTrackTitleControl(output, placeholder) {
        const wrap = createElement('div', { className: 'field-action-row' });
        wrap.appendChild(createElement('input', {
            attrs: { type: 'text', placeholder },
            dataset: { outputId: output.output_id, outputField: 'title' },
            value: output.title || '',
        }));
        wrap.appendChild(createElement('button', {
            className: 'ghost-button',
            text: 'Auto',
            attrs: { type: 'button', title: 'Titel aus Template erzeugen' },
            dataset: { action: 'auto-title-output', outputId: output.output_id },
        }));
        return wrap;
    }

    function buildOutputFolderControl(target, value) {
        const wrap = createElement('div', { className: 'field-action-row' });
        wrap.appendChild(createElement('input', {
            attrs: { type: 'text', list: 'outputHistory', placeholder: '/DataVolume/serien' },
            dataset: target === 'workspace' ? { jobField: 'output_folder' } : {},
            value: value || '',
        }));
        wrap.appendChild(createElement('button', {
            className: 'ghost-button',
            text: 'Wählen',
            attrs: { type: 'button' },
            dataset: { action: 'choose-output-folder', target },
        }));
        return wrap;
    }

    function buildLabeledField(label, control) {
        const wrapperTag = control.tagName === 'LABEL' ? 'div' : 'label';
        const wrapper = createElement(wrapperTag, { className: 'field-block' });
        wrapper.appendChild(createElement('span', {
            className: 'field-label',
            text: label,
        }));
        wrapper.appendChild(control);
        return wrapper;
    }

    function createSectionHeading(title, copy) {
        const head = createElement('div', { className: 'section-head vertical' });
        head.appendChild(createElement('h3', { text: title }));
        head.appendChild(createElement('p', {
            className: 'muted-copy',
            text: copy,
        }));
        return head;
    }

    function renderStreamRow(type, index, text) {
        const row = createElement('div', { className: 'stream-row' });
        row.appendChild(createTypeBadge(type));
        row.appendChild(createElement('span', { className: 'stream-index', text: `#${index}` }));
        row.appendChild(createElement('span', { text }));
        return row;
    }

    function jobActionButtons(job) {
        const buttons = [];
        if (['queued', 'ready', 'paused'].includes(job.status)) {
            buttons.push(createQueueActionButton('move_up', 'Nach oben', { action: 'move_up' }));
            buttons.push(createQueueActionButton('move_down', 'Nach unten', { action: 'move_down' }));
        }
        if (job.type === 'video' && ['queued', 'ready', 'running'].includes(job.status)) {
            buttons.push(createQueueActionButton('pause', 'Pausieren', { action: 'pause' }));
        }
        if (job.type === 'video' && job.status === 'paused') {
            buttons.push(createQueueActionButton('resume', 'Fortsetzen', { action: 'resume' }));
        }
        if (!['completed', 'cancelled'].includes(job.status)) {
            buttons.push(createQueueActionButton('cancel', 'Abbrechen', { action: 'cancel' }));
        }
        if (['failed', 'cancelled'].includes(job.status)) {
            buttons.push(createQueueActionButton('retry', 'Neu starten', { action: 'retry' }));
        }
        if (!['running', 'cancel_requested'].includes(job.status)) {
            buttons.push(createQueueActionButton('delete', 'Loeschen', { action: 'delete' }));
        }
        return buttons;
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

    function buildQueueJobCell(job) {
        const wrapper = createElement('div', { className: 'queue-job-cell' });
        const title = queueJobTitle(job);

        wrapper.appendChild(createElement('div', {
            className: 'queue-job-title',
            text: title,
            attrs: { title },
        }));

        const flags = createElement('div', { className: 'queue-job-flags' });
        if (job.batch_label) {
            flags.appendChild(createElement('span', {
                className: 'queue-chip',
                text: job.batch_label,
            }));
        }
        wrapper.appendChild(flags);

        wrapper.appendChild(buildQueuePathBlock(
            'Quelle',
            job.source_path || '',
            basename(job.source_path || ''),
            parentDirectory(job.source_path || '')
        ));

        const outputPath = job.output_folder ? `${job.output_folder}/${job.output_file || ''}` : (job.output_file || '');
        wrapper.appendChild(buildQueuePathBlock(
            'Ziel',
            outputPath,
            job.output_file || basename(job.output_folder || ''),
            job.output_folder || ''
        ));

        return wrapper;
    }

    function buildQueuePathBlock(label, fullPath, primaryText, secondaryText = '') {
        const wrapper = createElement('div', { className: 'queue-path-block' });
        wrapper.appendChild(createElement('div', {
            className: 'queue-path-label',
            text: label,
        }));
        const pathCell = buildPathCell(fullPath, primaryText, secondaryText);
        pathCell.classList.add('compact-path-cell');
        wrapper.appendChild(pathCell);
        return wrapper;
    }

    function buildQueueProgressCell(job) {
        const progress = job.progress || {};
        const isCompleted = job.status === 'completed';
        if (isCompleted && !job.error_text) {
            return null;
        }

        const wrapper = createElement('div', {
            className: 'queue-progress-cell',
            attrs: { title: formatProgress(job) },
        });
        const head = [];

        if (progress.phase && !isCompleted) {
            head.push(humanizeToken(progress.phase));
        }
        if (typeof progress.percent === 'number' && !isCompleted) {
            head.push(`${progress.percent.toFixed(1)}%`);
        }
        if (head.length > 0) {
            wrapper.appendChild(createElement('div', {
                className: 'queue-progress-main',
                text: head.join(' · '),
            }));
        }

        const meta = [];
        if (progress.speed) {
            meta.push(String(progress.speed));
        }
        if (typeof progress.eta_seconds === 'number' && !isCompleted) {
            meta.push(`ETA ${formatEtaCompact(progress.eta_seconds)}`);
        }
        if (meta.length > 0) {
            wrapper.appendChild(createElement('div', {
                className: 'queue-progress-meta',
                text: meta.join(' · '),
            }));
        }

        if (progress.message && !isCompleted) {
            wrapper.appendChild(createElement('div', {
                className: 'queue-progress-message',
                text: progress.message,
            }));
        }

        if (job.error_text) {
            wrapper.appendChild(createElement('div', {
                className: 'queue-progress-error',
                text: job.error_text,
            }));
        }

        return wrapper;
    }

    function buildQueueCard(job) {
        const card = createElement('article', {
            className: 'queue-card',
            dataset: { jobId: job.id },
        });

        const head = createElement('div', { className: 'queue-card-head' });
        const status = createElement('div', { className: 'queue-card-status' });
        const badges = createElement('div', { className: 'queue-status-badges' });
        badges.appendChild(createTypeBadge(job.status));
        badges.appendChild(createTypeBadge(job.type || 'video'));
        status.appendChild(badges);
        if (typeof job.position === 'number' && ['queued', 'ready', 'paused'].includes(job.status)) {
            status.appendChild(createElement('div', { className: 'queue-status-meta', text: `#${job.position}` }));
        }
        if (job.worker_pid) {
            status.appendChild(createElement('div', { className: 'queue-status-meta', text: `PID ${job.worker_pid}` }));
        }
        head.appendChild(status);
        card.appendChild(head);

        card.appendChild(buildQueueJobCell(job));
        const progressCell = buildQueueProgressCell(job);
        if (progressCell) {
            card.appendChild(progressCell);
        }

        const actions = createElement('div', { className: 'job-actions queue-actions' });
        jobActionButtons(job).forEach((button) => actions.appendChild(button));
        card.appendChild(actions);

        return card;
    }

    function duplicateOutput(outputId) {
        const collection = outputCollectionForId(outputId);
        const index = collection.findIndex((output) => output.output_id === outputId);
        if (index === -1) {
            return;
        }

        const clone = JSON.parse(JSON.stringify(collection[index]));
        state.cloneCounter += 1;
        clone.output_id = `${clone.scope}_${Date.now()}_${state.cloneCounter}`;
        collection.splice(index + 1, 0, clone);
        state.workspaceTabs[clone.scope] = clone.output_id;
        renderWorkspace();
    }

    function autoTitleOutput(outputId) {
        const output = findOutput(outputId);
        if (!output) {
            return;
        }

        output.title = buildAutoTitle(output);
        renderWorkspace();
    }

    function autoTitleScope(scope) {
        const outputs = scope === 'subtitle'
            ? (state.workspace.plan.subtitle_outputs || [])
            : (state.workspace.plan.audio_outputs || []);

        outputs.forEach((output) => {
            output.title = buildAutoTitle(output);
        });

        renderWorkspace();
    }

    function removeOutput(outputId) {
        const output = findOutput(outputId);
        ['audio_outputs', 'subtitle_outputs'].forEach((key) => {
            state.workspace.plan[key] = (state.workspace.plan[key] || []).filter((output) => output.output_id !== outputId);
        });
        if (output && state.workspaceTabs[output.scope] === outputId) {
            state.workspaceTabs[output.scope] = '';
        }
        renderWorkspace();
    }

    function findOutput(outputId) {
        return ['video_outputs', 'audio_outputs', 'subtitle_outputs']
            .flatMap((key) => state.workspace.plan[key] || [])
            .find((output) => output.output_id === outputId);
    }

    function outputCollectionForId(outputId) {
        return ['video_outputs', 'audio_outputs', 'subtitle_outputs']
            .map((key) => state.workspace.plan[key] || [])
            .find((collection) => collection.some((output) => output.output_id === outputId)) || [];
    }

    function enableOnlyVideoOutput(outputId) {
        (state.workspace.plan.video_outputs || []).forEach((output) => {
            output.enabled = output.output_id === outputId;
        });
        state.workspaceTabs.video = outputId;
    }

    function getPrimaryInput() {
        if (!state.workspace || !state.workspace.plan) {
            return null;
        }

        return (state.workspace.plan.inputs || []).find((input) => input.role === 'primary') || null;
    }

    function getJoinedInputs() {
        if (!state.workspace || !state.workspace.plan) {
            return [];
        }

        return (state.workspace.plan.inputs || []).filter((input) => input.role !== 'primary');
    }

    function workspaceAnalysisMap() {
        const map = {};
        if (!state.workspace || state.workspace.mode !== 'video') {
            return map;
        }

        map.primary = state.workspace.analysis;
        (state.workspace.joined_analyses || []).forEach((analysis, index) => {
            map[`join_${index + 1}`] = analysis;
        });
        return map;
    }

    function findSourceStream(output) {
        if (!output || !output.input_id) {
            return null;
        }

        const analysis = workspaceAnalysisMap()[output.input_id];
        if (!analysis) {
            return null;
        }

        const streams = ((analysis.streams || {})[output.scope]) || [];
        return streams.find((stream) => Number(stream.stream_index) === Number(output.source_stream_index)) || null;
    }

    function ensureActiveOutputTab(scope, outputs) {
        const activeId = state.workspaceTabs[scope];
        if (activeId && outputs.some((output) => output.output_id === activeId)) {
            return activeId;
        }

        const preferred = outputs.find((output) => output.enabled) || outputs[0] || null;
        state.workspaceTabs[scope] = preferred ? preferred.output_id : '';
        return state.workspaceTabs[scope];
    }

    function outputTabLabel(output, index) {
        const stream = findSourceStream(output);
        const fallbackPrefix = {
            video: 'Video',
            audio: 'Audio',
            subtitle: 'Subtitle',
        }[output.scope] || 'Output';

        if (!stream) {
            return `${fallbackPrefix} ${index + 1}`;
        }

        const parts = [];
        if (output.scope !== 'video' && stream.language && stream.language.human) {
            parts.push(stream.language.human);
        }
        parts.push(`#${stream.stream_index}`);
        if (stream.codec && stream.codec.name_uc) {
            parts.push(stream.codec.name_uc);
        }
        if (output.scope === 'audio' && stream.channels && stream.channels.layout) {
            parts.push(stream.channels.layout);
        }
        if (output.scope === 'subtitle' && stream.disposition && stream.disposition.forced) {
            parts.push('Forced');
        }

        return parts.filter(Boolean).join(' · ') || `${fallbackPrefix} ${index + 1}`;
    }

    function namingTemplateForOutput(output) {
        if (!state.workspace || !state.workspace.plan || !output) {
            return '';
        }

        const naming = state.workspace.plan.naming || {};
        return String(naming[output.scope] || '');
    }

    function subtitleNamingIndex(output) {
        const subtitles = state.workspace && state.workspace.plan ? (state.workspace.plan.subtitle_outputs || []) : [];
        let count = 0;

        for (const candidate of subtitles) {
            if (candidate.enabled) {
                count += 1;
            }

            if (candidate.output_id === output.output_id) {
                return count;
            }
        }

        return 0;
    }

    function buildAutoTitle(output) {
        const stream = findSourceStream(output);
        if (!stream) {
            return output.title || '';
        }

        return renderNamingTemplate(
            namingTemplateForOutput(output),
            output,
            stream,
            {
                index: output.scope === 'subtitle' ? subtitleNamingIndex(output) : 0,
            }
        );
    }

    function renderNamingTemplate(template, output, stream, context = {}) {
        const streamTitle = String((stream && stream.title) || '');
        if (!template) {
            return streamTitle;
        }

        const channelLabel = output.scope === 'audio'
            ? resolveChannelLabel(output, stream)
            : '';
        const loudnormSuffix = output.scope === 'audio' && String(output.loudnorm || '0') !== '0'
            ? ', Loudnorm'
            : '';
        const forced = Boolean(output.disposition_forced || (stream && stream.disposition && stream.disposition.forced));
        const forcedSuffix = forced ? '(Forced)' : '';
        const langShort = String((stream && stream.language && stream.language.short) || '');
        const langHuman = String((stream && stream.language && stream.language.human) || '');
        const codec = String((stream && stream.codec && stream.codec.name) || '');
        const codecUc = String((stream && stream.codec && stream.codec.name_uc) || '');
        const index = String(context.index || '');

        let rendered = String(template).replace(/##([A-Z]+)([:=][^#]*)?##/g, (match, token, rawArgument = '') => {
            const argument = String(rawArgument || '');

            switch (token) {
                case 'INDEX':
                    return index;
                case 'TITLE':
                    return streamTitle ? `"${streamTitle}"` : '';
                case 'LANG':
                    return renderLegacyLanguageToken(argument, streamTitle, langShort, langHuman);
                case 'FORCED':
                    return forced && argument.startsWith('=') ? argument.slice(1) : '';
                case 'CHANNELS':
                    return channelLabel;
                case 'LOUDNORM':
                    return argument.slice(1) === 'comma-true' ? loudnormSuffix : '';
                case 'CODEC':
                    return codec;
                case 'CODECUC':
                    return codecUc;
                default:
                    return '';
            }
        });

        rendered = rendered
            .replaceAll('{{title}}', streamTitle)
            .replaceAll('{{title_quoted}}', streamTitle ? `"${streamTitle}"` : '')
            .replaceAll('{{index}}', index)
            .replaceAll('{{lang_short}}', langShort)
            .replaceAll('{{lang_human}}', langHuman)
            .replaceAll('{{codec}}', codec)
            .replaceAll('{{codec_uc}}', codecUc)
            .replaceAll('{{channels}}', String(output.channels || ''))
            .replaceAll('{{channels_label}}', channelLabel)
            .replaceAll('{{loudnorm_suffix}}', loudnormSuffix)
            .replaceAll('{{forced_suffix}}', forcedSuffix);

        return rendered.replace(/\s{2,}/g, ' ').trim();
    }

    function renderLegacyLanguageToken(argument, streamTitle, langShort, langHuman) {
        if (!argument.startsWith(':')) {
            return '';
        }

        let selector = argument.slice(1);
        let condition = '';
        const match = selector.match(/^([^\[]+)\[(.+)\]$/);
        if (match) {
            selector = match[1].trim();
            condition = match[2].trim();
        }

        if (condition === 'if-no-title' && streamTitle.trim() !== '') {
            return '';
        }

        switch (selector.toLowerCase()) {
            case 'human':
                return langHuman;
            case 'short':
                return langShort;
            default:
                return '';
        }
    }

    function resolveChannelLabel(output, stream) {
        const audioOptions = state.workspace && state.workspace.editor_options ? (state.workspace.editor_options.audio || {}) : {};
        const channelMap = audioOptions.channels || {};
        const outputChannels = output.channels !== undefined && output.channels !== null ? String(output.channels) : '';
        if (outputChannels && Object.prototype.hasOwnProperty.call(channelMap, outputChannels)) {
            return String(channelMap[outputChannels]);
        }

        if (outputChannels) {
            return outputChannels;
        }

        return String((((stream || {}).channels || {}).layout) || (((stream || {}).channels || {}).count) || '');
    }

    function previewSeeksForInput(inputId) {
        const analysis = workspaceAnalysisMap()[inputId];
        const duration = analysis && analysis.info ? Number(analysis.info.duration_seconds || 0) : 0;
        if (!duration || duration <= 0) {
            return [];
        }

        const seeks = [];
        for (let index = 1; index <= 10; index += 1) {
            const seconds = (duration / 11) * index;
            seeks.push({
                seconds,
                label: formatTime(seconds),
            });
        }
        return seeks;
    }

    function clearBatchSelection() {
        state.selectedBatchPaths = new Set();
        state.batchPreviewItems = [];
        renderBrowser();
        renderBatchSelectionBar();
        renderBatchPreview();
    }

    function formatProgress(job) {
        const progress = job.progress || {};
        const parts = [];
        if (progress.phase) {
            parts.push(progress.phase);
        }
        if (typeof progress.percent === 'number') {
            parts.push(`${progress.percent.toFixed(1)}%`);
        }
        if (progress.speed) {
            parts.push(progress.speed);
        }
        if (typeof progress.eta_seconds === 'number') {
            parts.push(`ETA ${progress.eta_seconds}s`);
        }
        if (progress.message) {
            parts.push(progress.message);
        }
        if (job.error_text) {
            parts.push(job.error_text);
        }
        return parts.length > 0 ? parts.join(' · ') : '–';
    }

    function queueJobTitle(job) {
        const title = String(job.title || '').trim();
        if (title !== '') {
            return title;
        }

        const outputFile = String(job.output_file || '').trim();
        if (outputFile !== '') {
            return outputFile.replace(/\.[^.]+$/, '');
        }

        return basename(job.source_path || '').replace(/\.[^.]+$/, '') || 'Job';
    }

    function parentDirectory(path) {
        const normalized = String(path || '').replace(/\/+$/, '');
        const index = normalized.lastIndexOf('/');
        if (index <= 0) {
            return normalized;
        }
        return normalized.slice(0, index);
    }

    function humanizeToken(value) {
        return String(value || '').replaceAll('_', ' ');
    }

    function formatEtaCompact(totalSeconds) {
        const seconds = Math.max(0, Number(totalSeconds) || 0);
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = Math.floor(seconds % 60);

        if (hours > 0) {
            return `${hours}h ${minutes}m`;
        }
        if (minutes > 0) {
            return `${minutes}m ${remainingSeconds}s`;
        }
        return `${remainingSeconds}s`;
    }

    function enabledOutputs(outputs) {
        return (outputs || []).filter((output) => {
            if (!output) {
                return false;
            }
            if (Object.prototype.hasOwnProperty.call(output, 'enabled')) {
                return Boolean(output.enabled);
            }
            return output.action !== 'skip';
        }).length;
    }

    function enabledArchiveEntries(entries) {
        return (entries || []).filter((entry) => Boolean(entry && entry.enabled)).length;
    }

    function dedupeValidationMessages(messages) {
        const seen = new Set();
        return (messages || []).filter((message) => {
            const key = `${message.level || 'info'}|${message.message || ''}`;
            if (seen.has(key)) {
                return false;
            }
            seen.add(key);
            return true;
        });
    }

    function formatValidationMessages(messages) {
        const list = (messages || []).map((message) => message.message).filter(Boolean);
        return list.length > 0 ? list.join(' · ') : '';
    }

    function hasValidationLevel(messages, level) {
        return (messages || []).some((message) => message.level === level);
    }

    function matchesBrowserFilter(filter, ...values) {
        if (!filter) {
            return true;
        }

        return values.some((value) => String(value || '').toLowerCase().includes(filter));
    }

    function canJoinFile(file) {
        const primaryInput = getPrimaryInput();
        return Boolean(
            state.workspace &&
            state.workspace.mode === 'video' &&
            file.joinable &&
            primaryInput &&
            file.path !== primaryInput.source_path
        );
    }

    function isJoinedPath(path) {
        return getJoinedInputs().some((input) => input.source_path === path);
    }

    function isFileActive(file) {
        return Boolean(
            (state.workspace && state.workspace.analysis && state.workspace.analysis.file === file.path) ||
            isJoinedPath(file.path) ||
            file.is_active
        );
    }

    function isOpenableFile(file) {
        return file.scan_type === 'video' || file.scan_type === 'rar';
    }

    function inputValue(element) {
        if (element.type === 'checkbox' || element.type === 'radio') {
            return Boolean(element.checked);
        }
        return element.value;
    }

    function guessTypeFromPath(path) {
        return /\.rar$/i.test(path) ? 'rar' : 'video';
    }

    function formatTime(seconds) {
        const total = Math.max(0, Math.round(Number(seconds) || 0));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;
        return [hours, minutes, secs]
            .map((value) => String(value).padStart(2, '0'))
            .join(':');
    }

    function basename(path) {
        return String(path || '').split('/').pop() || path;
    }

    function listToMap(values) {
        return (values || []).reduce((carry, value) => {
            carry[String(value)] = String(value);
            return carry;
        }, {});
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

    function createQueueActionButton(iconName, label, dataset = {}) {
        const button = createElement('button', {
            className: 'queue-icon-button',
            attrs: {
                type: 'button',
                title: label,
                'aria-label': label,
            },
            dataset,
        });
        button.appendChild(createQueueActionIcon(iconName));
        return button;
    }

    function createQueueActionIcon(name) {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 16 16');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('class', 'queue-icon-svg');

        const definitions = queueIconDefinitions()[name] || queueIconDefinitions().cancel;
        definitions.forEach((definition) => {
            const element = document.createElementNS(ns, definition.tag);
            Object.entries(definition.attrs).forEach(([key, value]) => {
                element.setAttribute(key, String(value));
            });
            svg.appendChild(element);
        });

        return svg;
    }

    function queueIconDefinitions() {
        return {
            move_up: [
                { tag: 'path', attrs: { d: 'M8 3 L4.5 6.5 M8 3 L11.5 6.5 M8 3 L8 13' } },
            ],
            move_down: [
                { tag: 'path', attrs: { d: 'M8 13 L4.5 9.5 M8 13 L11.5 9.5 M8 13 L8 3' } },
            ],
            pause: [
                { tag: 'line', attrs: { x1: '6', y1: '4', x2: '6', y2: '12' } },
                { tag: 'line', attrs: { x1: '10', y1: '4', x2: '10', y2: '12' } },
            ],
            resume: [
                { tag: 'path', attrs: { d: 'M6 4.5 L11.5 8 L6 11.5 Z', fill: 'currentColor', stroke: 'none' } },
            ],
            cancel: [
                { tag: 'line', attrs: { x1: '5', y1: '5', x2: '11', y2: '11' } },
                { tag: 'line', attrs: { x1: '11', y1: '5', x2: '5', y2: '11' } },
            ],
            retry: [
                { tag: 'path', attrs: { d: 'M11.5 6.5 A4 4 0 1 0 12 9.5' } },
                { tag: 'path', attrs: { d: 'M9.5 4.5 L12 4.5 L12 7' } },
            ],
            delete: [
                { tag: 'path', attrs: { d: 'M5.5 5.5 L6 12.5 M8 5.5 L8 12.5 M10.5 5.5 L10 12.5' } },
                { tag: 'path', attrs: { d: 'M4.5 4.5 H11.5 M6 4.5 V3.5 H10 V4.5 M5.5 4.5 L6 13 H10 L10.5 4.5' } },
            ],
        };
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
