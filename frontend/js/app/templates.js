(function () {
    const MANAGED_RULE_IDS = new Set([
        'guided-job-default',
        'guided-video-default',
        'german-ac3-dual-output',
    ]);

    const state = {
        csrfToken: null,
        authConfigured: false,
        authenticated: false,
        templates: [],
        selectedTemplate: null,
        editorOptions: {},
        editorMode: 'guided',
    };

    const els = {};

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        cacheElements();
        bindEvents();
        await refreshState();
    }

    function cacheElements() {
        els.authBanner = document.getElementById('authBanner');
        els.templateList = document.getElementById('templateList');
        els.templateForm = document.getElementById('templateForm');
        els.templatePreview = document.getElementById('templatePreview');
        els.templateVersionSelect = document.getElementById('templateVersionSelect');
        els.newTemplateBtn = document.getElementById('newTemplateBtn');
        els.publishTemplateBtn = document.getElementById('publishTemplateBtn');
        els.guidedModeBtn = document.getElementById('guidedModeBtn');
        els.jsonModeBtn = document.getElementById('jsonModeBtn');
        els.guidedToJsonBtn = document.getElementById('guidedToJsonBtn');
        els.jsonToGuidedBtn = document.getElementById('jsonToGuidedBtn');
        els.guidedEditor = document.getElementById('guidedEditor');
        els.jsonEditorBlock = document.getElementById('jsonEditorBlock');
        els.schemaEditor = document.getElementById('templateSchemaEditor');

        els.guidedJobTitle = document.getElementById('guidedJobTitle');
        els.guidedJobOutputFile = document.getElementById('guidedJobOutputFile');
        els.guidedAudioNaming = document.getElementById('guidedAudioNaming');
        els.guidedSubtitleNaming = document.getElementById('guidedSubtitleNaming');
        els.guidedSubtitleLanguages = document.getElementById('guidedSubtitleLanguages');
        els.guidedVideoRuleEnabled = document.getElementById('guidedVideoRuleEnabled');
        els.guidedVideoCodec = document.getElementById('guidedVideoCodec');
        els.guidedVideoMode = document.getElementById('guidedVideoMode');
        els.guidedVideoModeValue = document.getElementById('guidedVideoModeValue');
        els.guidedVideoPreset = document.getElementById('guidedVideoPreset');
        els.guidedVideoResize = document.getElementById('guidedVideoResize');
        els.guidedVideoCrop = document.getElementById('guidedVideoCrop');
        els.guidedVideoNlmeans = document.getElementById('guidedVideoNlmeans');
        els.guidedDualAudioEnabled = document.getElementById('guidedDualAudioEnabled');
        els.guidedDualAudioCodec = document.getElementById('guidedDualAudioCodec');
        els.guidedDualAudioProfile = document.getElementById('guidedDualAudioProfile');
        els.guidedDualAudioBitrate = document.getElementById('guidedDualAudioBitrate');
        els.guidedDualAudioSamplerate = document.getElementById('guidedDualAudioSamplerate');
        els.guidedDualAudioChannels = document.getElementById('guidedDualAudioChannels');
        els.guidedDualAudioLoudnorm = document.getElementById('guidedDualAudioLoudnorm');
    }

    function bindEvents() {
        els.newTemplateBtn.addEventListener('click', createNewTemplateDraft);
        els.templateList.addEventListener('click', handleTemplateSelect);
        els.templateVersionSelect.addEventListener('change', handleVersionChange);
        els.templateForm.addEventListener('submit', saveTemplateDraft);
        els.schemaEditor.addEventListener('input', syncPreview);
        els.publishTemplateBtn.addEventListener('click', publishSelectedVersion);
        els.authBanner.addEventListener('submit', handleAuthSubmit);
        els.authBanner.addEventListener('click', handleAuthClick);
        els.guidedModeBtn.addEventListener('click', () => setEditorMode('guided'));
        els.jsonModeBtn.addEventListener('click', () => setEditorMode('json'));
        els.guidedToJsonBtn.addEventListener('click', () => {
            syncGuidedToJson();
            renderAuthBanner('Gefuehrter Editor in JSON uebernommen.');
        });
        els.jsonToGuidedBtn.addEventListener('click', () => {
            loadGuidedFromJson();
            renderAuthBanner('JSON in den gefuehrten Editor geladen.');
        });

        [
            els.guidedJobTitle,
            els.guidedJobOutputFile,
            els.guidedAudioNaming,
            els.guidedSubtitleNaming,
            els.guidedSubtitleLanguages,
            els.guidedVideoRuleEnabled,
            els.guidedVideoCodec,
            els.guidedVideoMode,
            els.guidedVideoModeValue,
            els.guidedVideoPreset,
            els.guidedVideoResize,
            els.guidedVideoCrop,
            els.guidedVideoNlmeans,
            els.guidedDualAudioEnabled,
            els.guidedDualAudioCodec,
            els.guidedDualAudioProfile,
            els.guidedDualAudioBitrate,
            els.guidedDualAudioSamplerate,
            els.guidedDualAudioChannels,
            els.guidedDualAudioLoudnorm,
        ].forEach((element) => {
            element.addEventListener('input', handleGuidedInput);
            element.addEventListener('change', handleGuidedInput);
        });
    }

    async function refreshState(message = '') {
        const data = await appCommon.fetchJson('query/api/bootstrap.php');
        state.csrfToken = data.auth.csrf_token;
        state.authConfigured = data.auth.configured;
        state.authenticated = data.auth.authenticated;
        state.templates = data.templates || [];
        state.editorOptions = data.editor_options || {};

        populateGuidedEditorOptions();
        renderAuthBanner(message);
        renderEditorMode();
        renderTemplateList();

        if (state.selectedTemplate) {
            const stillExists = state.templates.some((template) => Number(template.id) === Number(state.selectedTemplate.id));
            if (stillExists) {
                await loadTemplate(state.selectedTemplate.id);
                return;
            }
        }

        if (state.templates.length > 0) {
            await loadTemplate(state.templates[0].id);
        } else {
            createNewTemplateDraft();
        }
    }

    function renderAuthBanner(message = '') {
        clear(els.authBanner);

        const text = document.createElement('span');
        text.className = 'status-copy';
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

            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.action = 'logout';
            button.textContent = 'Logout';
            els.authBanner.appendChild(button);
            return;
        }

        els.authBanner.className = 'status-banner warning';
        text.textContent = message || 'Nicht angemeldet.';
        els.authBanner.appendChild(text);

        const form = document.createElement('form');
        form.id = 'authLoginForm';
        form.className = 'auth-inline-form';

        const input = document.createElement('input');
        input.type = 'password';
        input.name = 'password';
        input.placeholder = 'Admin-Passwort';
        input.required = true;

        const button = document.createElement('button');
        button.type = 'submit';
        button.textContent = 'Login';

        form.appendChild(input);
        form.appendChild(button);
        els.authBanner.appendChild(form);
    }

    function renderTemplateList() {
        clear(els.templateList);
        state.templates.forEach((template) => {
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'template-card' + (state.selectedTemplate && Number(state.selectedTemplate.id) === Number(template.id) ? ' active' : '');
            card.dataset.templateId = String(template.id);

            const title = document.createElement('h3');
            title.textContent = template.name;
            card.appendChild(title);

            const description = document.createElement('p');
            description.textContent = template.description || 'Keine Beschreibung.';
            card.appendChild(description);

            const pill = document.createElement('span');
            pill.className = 'pill';
            pill.textContent = `v${template.published_version || 'draft'}`;
            card.appendChild(pill);

            els.templateList.appendChild(card);
        });
    }

    function renderEditorMode() {
        const isGuided = state.editorMode === 'guided';
        els.guidedEditor.classList.toggle('hidden', !isGuided);
        els.jsonEditorBlock.classList.toggle('hidden', isGuided);
        els.guidedModeBtn.classList.toggle('active', isGuided);
        els.jsonModeBtn.classList.toggle('active', !isGuided);
        els.guidedModeBtn.disabled = isGuided;
        els.jsonModeBtn.disabled = !isGuided;
        els.guidedToJsonBtn.disabled = !isGuided;
        els.jsonToGuidedBtn.disabled = isGuided;
    }

    function setEditorMode(mode) {
        state.editorMode = mode === 'json' ? 'json' : 'guided';
        if (state.editorMode === 'guided') {
            syncGuidedToJson(false);
        }
        renderEditorMode();
        syncPreview();
    }

    function populateGuidedEditorOptions() {
        const editorOptions = state.editorOptions || {};
        fillSelect(
            els.guidedVideoCodec,
            normalizeSelectOptions(editorOptions.video && editorOptions.video.codecs ? editorOptions.video.codecs : {}),
            'libx265'
        );
        fillSelect(
            els.guidedVideoNlmeans,
            normalizeSelectOptions(editorOptions.video && editorOptions.video.nlmeans ? editorOptions.video.nlmeans : {}, 'name'),
            '0'
        );
        fillSelect(
            els.guidedDualAudioCodec,
            normalizeSelectOptions(editorOptions.audio && editorOptions.audio.codecs ? editorOptions.audio.codecs : {}),
            'libfdk_aac'
        );
        fillSelect(
            els.guidedDualAudioSamplerate,
            normalizeSelectOptions(listToMap(editorOptions.audio && editorOptions.audio.samplerate ? editorOptions.audio.samplerate : [])),
            '48000'
        );
        fillSelect(
            els.guidedDualAudioChannels,
            normalizeSelectOptions(editorOptions.audio && editorOptions.audio.channels ? editorOptions.audio.channels : {}),
            '2'
        );
        fillSelect(
            els.guidedDualAudioLoudnorm,
            normalizeSelectOptions(editorOptions.audio && editorOptions.audio.loudnorm ? editorOptions.audio.loudnorm : {}, 'name'),
            'ebur128'
        );

        renderGuidedVideoModes(els.guidedVideoCodec.value || 'libx265', els.guidedVideoMode.value || 'crf');
    }

    function renderGuidedVideoModes(codec, selectedValue) {
        const codecs = (((state.editorOptions || {}).video || {}).codecs || {});
        const modes = (codecs[codec] || {}).modes || {};
        fillSelect(els.guidedVideoMode, normalizeSelectOptions(modes, 'name'), selectedValue || 'crf');
    }

    function fillSelect(select, options, selectedValue) {
        if (!select) {
            return;
        }

        const currentValue = selectedValue !== undefined ? String(selectedValue) : String(select.value || '');
        clear(select);
        options.forEach((option) => {
            const element = document.createElement('option');
            element.value = option.value;
            element.textContent = option.label;
            if (String(option.value) === currentValue) {
                element.selected = true;
            }
            select.appendChild(element);
        });

        if (!select.value && select.options.length > 0) {
            select.value = select.options[0].value;
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
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                }),
            });
            await refreshState('Abgemeldet.');
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function handleTemplateSelect(event) {
        const button = event.target.closest('[data-template-id]');
        if (!button) {
            return;
        }

        await loadTemplate(Number(button.dataset.templateId));
    }

    async function loadTemplate(templateId) {
        const data = await appCommon.fetchJson(`query/api/template.php?id=${templateId}`);
        state.selectedTemplate = data.template;
        renderTemplateList();
        fillForm();
    }

    function fillForm() {
        const template = state.selectedTemplate;
        if (!template) {
            return;
        }

        els.templateForm.id.value = template.id;
        els.templateForm.name.value = template.name;
        els.templateForm.description.value = template.description || '';
        clear(els.templateVersionSelect);

        template.versions.forEach((version) => {
            const option = document.createElement('option');
            option.value = String(version.id);
            option.textContent = `v${version.version} · ${version.status}`;
            els.templateVersionSelect.appendChild(option);
        });

        const initialVersion = template.versions[0];
        if (initialVersion) {
            els.templateVersionSelect.value = String(initialVersion.id);
            loadSchemaIntoEditor(initialVersion.schema);
        } else {
            loadSchemaIntoEditor(buildDefaultSchema());
        }
    }

    function handleVersionChange() {
        if (!state.selectedTemplate) {
            return;
        }

        const versionId = Number(els.templateVersionSelect.value);
        const version = state.selectedTemplate.versions.find((candidate) => Number(candidate.id) === versionId);
        if (!version) {
            return;
        }

        loadSchemaIntoEditor(version.schema);
    }

    function loadSchemaIntoEditor(schema) {
        els.schemaEditor.value = appCommon.formatJson(schema);
        loadGuidedFromSchema(schema);
        syncPreview();
    }

    function handleGuidedInput(event) {
        if (event.target === els.guidedVideoCodec) {
            renderGuidedVideoModes(els.guidedVideoCodec.value, els.guidedVideoMode.value || 'crf');
        }

        if (state.editorMode === 'guided') {
            syncGuidedToJson(false);
        } else {
            syncPreview();
        }
    }

    function syncGuidedToJson(showPreview = true) {
        const schema = buildSchemaFromGuided(currentSchema());
        els.schemaEditor.value = appCommon.formatJson(schema);
        if (showPreview) {
            syncPreview();
        }
    }

    function loadGuidedFromJson() {
        const schema = JSON.parse(els.schemaEditor.value || '{}');
        loadGuidedFromSchema(schema);
        syncPreview();
    }

    function loadGuidedFromSchema(schema) {
        const model = guidedModelFromSchema(schema);
        els.guidedJobTitle.value = model.jobTitle;
        els.guidedJobOutputFile.value = model.jobOutputFile;
        els.guidedAudioNaming.value = model.audioNaming;
        els.guidedSubtitleNaming.value = model.subtitleNaming;
        els.guidedSubtitleLanguages.value = model.subtitleLanguages;
        els.guidedVideoRuleEnabled.checked = model.videoRuleEnabled;
        setSelectValue(els.guidedVideoCodec, model.videoCodec);
        renderGuidedVideoModes(model.videoCodec, model.videoMode);
        setSelectValue(els.guidedVideoMode, model.videoMode);
        els.guidedVideoModeValue.value = model.videoModeValue;
        els.guidedVideoPreset.value = model.videoPreset;
        els.guidedVideoResize.value = model.videoResize;
        els.guidedVideoCrop.value = model.videoCrop;
        setSelectValue(els.guidedVideoNlmeans, model.videoNlmeans);
        els.guidedDualAudioEnabled.checked = model.dualAudioEnabled;
        setSelectValue(els.guidedDualAudioCodec, model.dualAudioCodec);
        els.guidedDualAudioProfile.value = model.dualAudioProfile;
        els.guidedDualAudioBitrate.value = model.dualAudioBitrate;
        setSelectValue(els.guidedDualAudioSamplerate, model.dualAudioSamplerate);
        setSelectValue(els.guidedDualAudioChannels, model.dualAudioChannels);
        setSelectValue(els.guidedDualAudioLoudnorm, model.dualAudioLoudnorm);
    }

    function syncPreview() {
        try {
            const schema = JSON.parse(els.schemaEditor.value || '{}');
            const ruleScopes = (schema.rules || []).map((rule) => rule.scope).filter(Boolean);
            const summary = [
                `Modus: ${state.editorMode === 'guided' ? 'Gefuehrt' : 'JSON'}`,
                `Regeln: ${(schema.rules || []).length}`,
                `Scopes: ${ruleScopes.length > 0 ? Array.from(new Set(ruleScopes)).join(', ') : 'keine'}`,
            ];
            els.templatePreview.textContent = `${summary.join(' | ')}\n\n${appCommon.formatJson(schema)}`;
        } catch (error) {
            els.templatePreview.textContent = `JSON-Fehler: ${error.message}\n\n${els.schemaEditor.value || ''}`;
        }
    }

    async function saveTemplateDraft(event) {
        event.preventDefault();

        try {
            if (state.editorMode === 'guided') {
                syncGuidedToJson(false);
            }

            const schema = JSON.parse(els.schemaEditor.value);
            const isNewTemplate = !els.templateForm.id.value;
            const endpoint = isNewTemplate ? 'query/api/templates.php' : 'query/api/template.php';
            const payload = {
                csrf_token: state.csrfToken,
                name: els.templateForm.name.value,
                description: els.templateForm.description.value,
                schema,
            };

            if (!isNewTemplate) {
                payload.id = Number(els.templateForm.id.value);
            }

            const data = await appCommon.fetchJson(endpoint, {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            await refreshState(isNewTemplate ? 'Template angelegt.' : 'Draft gespeichert.');
            await loadTemplate(data.template.id);
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    async function publishSelectedVersion() {
        if (!state.selectedTemplate || !els.templateVersionSelect.value) {
            renderAuthBanner('Bitte zuerst ein Template und eine Version auswählen.');
            return;
        }

        try {
            await appCommon.fetchJson('query/api/template.php', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    id: state.selectedTemplate.id,
                    action: 'publish',
                    version_id: Number(els.templateVersionSelect.value),
                }),
            });
            await refreshState('Version veröffentlicht.');
        } catch (error) {
            renderAuthBanner(error.message);
        }
    }

    function createNewTemplateDraft() {
        state.selectedTemplate = null;
        renderTemplateList();
        els.templateForm.reset();
        els.templateForm.id.value = '';
        clear(els.templateVersionSelect);
        els.schemaEditor.value = appCommon.formatJson(buildDefaultSchema());
        loadGuidedFromSchema(buildDefaultSchema());
        setEditorMode('guided');
        syncPreview();
    }

    function buildDefaultSchema() {
        return {
            schema_version: 1,
            name: 'Neues Template',
            description: '',
            naming: {
                audio: '##LANG:human## (##CHANNELS####LOUDNORM:comma-true##)',
                subtitle: '##INDEX## - ##LANG:human[if-no-title]## ##TITLE## ##FORCED=(Forced)##',
            },
            legacy_decisions: {
                subtitles: {
                    pick: ['ger', 'deu'],
                },
            },
            rules: [
                {
                    id: 'default-audio-copy',
                    scope: 'audio',
                    priority: 10,
                    replace_default: true,
                    outputs: [
                        {
                            action: 'copy',
                            title: '{{lang_human}} ({{codec_uc}})',
                        },
                    ],
                },
                {
                    id: 'guided-video-default',
                    scope: 'video',
                    priority: 60,
                    replace_default: true,
                    outputs: [
                        {
                            action: 'encode',
                            codec: 'libx265',
                            mode: 'crf',
                            mode_value: 23,
                            preset: 'slow',
                            resize: '0',
                            crop: 'auto',
                            nlmeans: '0',
                        },
                    ],
                },
                {
                    id: 'german-ac3-dual-output',
                    scope: 'audio',
                    priority: 100,
                    replace_default: true,
                    match: {
                        language_in: ['ger', 'deu'],
                        codec_in: ['ac3'],
                    },
                    outputs: [
                        {
                            action: 'copy',
                            title: '{{lang_human}} ({{codec_uc}})',
                        },
                        {
                            action: 'encode',
                            codec: 'libfdk_aac',
                            profile: 'main',
                            bitrate: '256k',
                            samplerate: 48000,
                            channels: 2,
                            loudnorm: 'ebur128',
                            title: '{{lang_human}} (AAC, Loudnorm)',
                        },
                    ],
                },
            ],
        };
    }

    function currentSchema() {
        try {
            return JSON.parse(els.schemaEditor.value || '{}');
        } catch (error) {
            return buildDefaultSchema();
        }
    }

    function guidedModelFromSchema(schema) {
        const base = buildDefaultSchema();
        const legacy = isObject(schema.legacy_decisions) ? schema.legacy_decisions : base.legacy_decisions;
        const rules = Array.isArray(schema.rules) ? schema.rules : [];
        const jobRule = rules.find((rule) => rule.id === 'guided-job-default' && rule.scope === 'job');
        const videoRule = rules.find((rule) => rule.id === 'guided-video-default' && rule.scope === 'video');
        const dualAudioRule = rules.find((rule) => rule.id === 'german-ac3-dual-output' && rule.scope === 'audio');
        const videoDefaults = videoRule && Array.isArray(videoRule.outputs) && videoRule.outputs[0]
            ? videoRule.outputs[0]
            : defaultVideoRuleOutput();
        const dualAudioOutput = dualAudioRule && Array.isArray(dualAudioRule.outputs) && dualAudioRule.outputs[1]
            ? dualAudioRule.outputs[1]
            : defaultDualAudioOutput();

        return {
            jobTitle: jobRule && isObject(jobRule.job) ? String(jobRule.job.title || '') : '',
            jobOutputFile: jobRule && isObject(jobRule.job) ? String(jobRule.job.output_file || '') : '',
            audioNaming: String(((schema.naming || {}).audio) || base.naming.audio),
            subtitleNaming: String(((schema.naming || {}).subtitle) || base.naming.subtitle),
            subtitleLanguages: Array.isArray(((legacy.subtitles || {}).pick))
                ? legacy.subtitles.pick.join(', ')
                : 'ger, deu',
            videoRuleEnabled: Boolean(videoRule),
            videoCodec: String(videoDefaults.codec || 'libx265'),
            videoMode: String(videoDefaults.mode || 'crf'),
            videoModeValue: String(videoDefaults.mode_value ?? 23),
            videoPreset: String(videoDefaults.preset || 'slow'),
            videoResize: String(videoDefaults.resize ?? '0'),
            videoCrop: String(videoDefaults.crop || 'auto'),
            videoNlmeans: String(videoDefaults.nlmeans || '0'),
            dualAudioEnabled: Boolean(dualAudioRule),
            dualAudioCodec: String(dualAudioOutput.codec || 'libfdk_aac'),
            dualAudioProfile: String(dualAudioOutput.profile || 'main'),
            dualAudioBitrate: String(dualAudioOutput.bitrate || '256k'),
            dualAudioSamplerate: String(dualAudioOutput.samplerate || 48000),
            dualAudioChannels: String(dualAudioOutput.channels || 2),
            dualAudioLoudnorm: String(dualAudioOutput.loudnorm || 'ebur128'),
        };
    }

    function buildSchemaFromGuided(baseSchema) {
        const schema = deepClone(isObject(baseSchema) ? baseSchema : buildDefaultSchema());
        schema.schema_version = 1;
        schema.name = schema.name || 'Neues Template';
        schema.description = schema.description || '';
        schema.naming = isObject(schema.naming) ? schema.naming : {};
        schema.naming.audio = els.guidedAudioNaming.value.trim();
        schema.naming.subtitle = els.guidedSubtitleNaming.value.trim();

        const defaultSchema = buildDefaultSchema();
        schema.legacy_decisions = isObject(schema.legacy_decisions) ? schema.legacy_decisions : deepClone(defaultSchema.legacy_decisions);
        schema.legacy_decisions.subtitles = isObject(schema.legacy_decisions.subtitles) ? schema.legacy_decisions.subtitles : {};
        schema.legacy_decisions.subtitles.pick = splitLanguages(els.guidedSubtitleLanguages.value);

        const existingRules = Array.isArray(schema.rules) ? schema.rules : [];
        schema.rules = existingRules.filter((rule) => !MANAGED_RULE_IDS.has(rule.id));

        const jobRule = {};
        if (els.guidedJobTitle.value.trim() !== '') {
            jobRule.title = els.guidedJobTitle.value.trim();
        }
        if (els.guidedJobOutputFile.value.trim() !== '') {
            jobRule.output_file = els.guidedJobOutputFile.value.trim();
        }
        if (Object.keys(jobRule).length > 0) {
            schema.rules.push({
                id: 'guided-job-default',
                scope: 'job',
                priority: 80,
                job: jobRule,
            });
        }

        if (els.guidedVideoRuleEnabled.checked) {
            schema.rules.push({
                id: 'guided-video-default',
                scope: 'video',
                priority: 60,
                replace_default: true,
                outputs: [
                    {
                        action: 'encode',
                        codec: els.guidedVideoCodec.value || 'libx265',
                        mode: els.guidedVideoMode.value || 'crf',
                        mode_value: numberOrString(els.guidedVideoModeValue.value, 23),
                        preset: els.guidedVideoPreset.value.trim() || 'slow',
                        resize: els.guidedVideoResize.value.trim() || '0',
                        crop: els.guidedVideoCrop.value.trim() || 'auto',
                        nlmeans: els.guidedVideoNlmeans.value || '0',
                    },
                ],
            });
        }

        if (els.guidedDualAudioEnabled.checked) {
            schema.rules.push({
                id: 'german-ac3-dual-output',
                scope: 'audio',
                priority: 100,
                replace_default: true,
                match: {
                    language_in: ['ger', 'deu'],
                    codec_in: ['ac3'],
                },
                outputs: [
                    {
                        action: 'copy',
                        title: '{{lang_human}} ({{codec_uc}})',
                    },
                    {
                        action: 'encode',
                        codec: els.guidedDualAudioCodec.value || 'libfdk_aac',
                        profile: els.guidedDualAudioProfile.value.trim() || 'main',
                        bitrate: els.guidedDualAudioBitrate.value.trim() || '256k',
                        samplerate: numberOrString(els.guidedDualAudioSamplerate.value, 48000),
                        channels: numberOrString(els.guidedDualAudioChannels.value, 2),
                        loudnorm: els.guidedDualAudioLoudnorm.value || 'ebur128',
                        title: '{{lang_human}} (AAC, Loudnorm)',
                    },
                ],
            });
        }

        return schema;
    }

    function splitLanguages(value) {
        return String(value || '')
            .split(',')
            .map((entry) => entry.trim())
            .filter(Boolean);
    }

    function numberOrString(value, fallback) {
        const trimmed = String(value || '').trim();
        if (trimmed === '') {
            return fallback;
        }
        return /^-?\d+(\.\d+)?$/.test(trimmed) ? Number(trimmed) : trimmed;
    }

    function defaultVideoRuleOutput() {
        return {
            codec: 'libx265',
            mode: 'crf',
            mode_value: 23,
            preset: 'slow',
            resize: '0',
            crop: 'auto',
            nlmeans: '0',
        };
    }

    function defaultDualAudioOutput() {
        return {
            codec: 'libfdk_aac',
            profile: 'main',
            bitrate: '256k',
            samplerate: 48000,
            channels: 2,
            loudnorm: 'ebur128',
        };
    }

    function normalizeSelectOptions(options, labelField = 'name') {
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
                    label: String(config[labelField] || config.name || value),
                };
            }

            return {
                value,
                label: String(config),
            };
        });
    }

    function listToMap(values) {
        return (values || []).reduce((carry, value) => {
            carry[String(value)] = String(value);
            return carry;
        }, {});
    }

    function setSelectValue(select, value) {
        if (!select) {
            return;
        }

        const stringValue = String(value ?? '');
        const hasOption = Array.from(select.options).some((option) => option.value === stringValue);
        if (!hasOption && stringValue !== '') {
            const option = document.createElement('option');
            option.value = stringValue;
            option.textContent = stringValue;
            select.appendChild(option);
        }
        select.value = stringValue;
    }

    function clear(element) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function deepClone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }
})();
