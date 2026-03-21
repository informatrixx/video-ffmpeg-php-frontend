window.appCommon = (() => {
    async function fetchJson(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                ...(options.headers || {}),
            },
            ...options,
        });

        const rawText = await response.text();
        let data = null;
        try {
            data = rawText !== '' ? JSON.parse(rawText) : {};
        } catch (error) {
            throw new Error(rawText || `HTTP ${response.status}`);
        }

        if (!response.ok || data.success === false) {
            throw new Error(data.error || `HTTP ${response.status}`);
        }

        return data;
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function formatJson(value) {
        return JSON.stringify(value, null, 2);
    }

    return {
        fetchJson,
        escapeHtml,
        formatJson,
    };
})();
