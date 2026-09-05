// CSS сетки импорта co-located в Import-модуле ([internal], #113):
// webpack извлекает его в assets/css/import.css, ImportEnqueueService грузит на Tools.
import './import-grid.css';
import { pollJob } from './poll-job.js';
import { escapeHtml } from '../sidebar/utils/escape.js';

(function () {
    if (!window.PlathixSettings) {
        return;
    }

    const t = (key, fallback) => window.PlathixSettings?.i18n?.[key] || fallback;

    const statusNode = document.getElementById('plathix-import-status');
    const buttons = Array.from(document.querySelectorAll('.plathix-import-button'));
    const restartButtons = Array.from(document.querySelectorAll('.plathix-import-restart-button'));

    if (!statusNode || buttons.length === 0) {
        return;
    }

    const setStatus = (type, message) => {
        // [internal]: className replace уже убирает is-hidden (базовое скрытое
        // состояние из PHP-разметки) — className полностью заменяется, не через toggle.
        statusNode.className = `notice inline notice-${type}`;
        statusNode.innerHTML = `<p>${escapeHtml(message)}</p>`;
    };

    const initialDisabled = new WeakMap();
    buttons.forEach((button) => {
        initialDisabled.set(button, button.disabled);
    });

    const setBusy = (busy) => {
        buttons.forEach((button) => {
            button.disabled = busy || Boolean(initialDisabled.get(button));
        });
    };

    const queueImport = async (adapter, restart) => {
        const formData = new FormData();
        formData.append('action', 'plathix_import');
        formData.append('adapter', adapter);
        formData.append('nonce', window.PlathixSettings.nonce || '');
        if (restart) {
            formData.append('restart', 'true');
        }

        const response = await fetch(window.PlathixSettings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        });

        // [internal] (симметрично pollJob): не-JSON тело (например HTML-страница ошибки
        // от веб-сервера/WAF) не должно всплывать как сырой SyntaxError парсера.
        let payload = null;
        try {
            payload = await response.json();
        } catch (e) {
            payload = null;
        }

        if (!response.ok || !payload?.success) {
            throw new Error(payload?.data?.message || t('request_failed', 'Request failed.'));
        }

        return Number(payload?.data?.jobId || 0);
    };

    const runImport = async (adapter, restart) => {
        setBusy(true);
        setStatus('info', t('import_queued', 'Import queued. Waiting for Action Scheduler...'));

        try {
            const jobId = await queueImport(adapter, restart);
            if (jobId <= 0) {
                throw new Error(t('import_failed', 'Import failed.'));
            }

            const result = await pollJob(jobId, window.PlathixSettings, t, setStatus);
            const moved = Number(result?.moved || 0);
            setStatus('success', t('import_completed', 'Import completed. Moved items: %d').replace('%d', String(moved)));
        } catch (error) {
            setStatus('error', error instanceof Error ? error.message : t('import_failed', 'Import failed.'));
        } finally {
            setBusy(false);
        }
    };

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            const adapter = button.dataset.adapter || '';
            if (!adapter) {
                return;
            }

            await runImport(adapter, false);
        });
    });

    restartButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const adapter = button.dataset.adapter || '';
            if (!adapter) {
                return;
            }

            await runImport(adapter, true);
        });
    });
})();
