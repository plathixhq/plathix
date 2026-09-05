/**
 * Опрос статуса job'а импорта до complete/failed/canceled/timeout.
 *
 * [internal] ([internal], четвёртая причина переоткрытия): опрос идёт тем же
 * транспортом, что и диспатч job'а (admin-ajax.php, wp_ajax_plathix_import_status).
 * Ранее опрос шёл через REST GET jobs/{id} — такого роута никогда не существовало в PHP
 * (weakness обнаружен reаl browser-proof'ом на проде клиента A: и pretty-URL, и rest_route
 * fallback давали 404, причём rest_route — от самого WordPress, `rest_no_route`).
 * admin-ajax.php не проходит через REST rewrite, поэтому не подвержен тем же
 * pretty-permalink routing-багам серверов (nginx/LiteSpeed/WAF).
 *
 * [internal]: единичный транспортный сбой самого опроса (сеть/429/503 от хостинга) раньше
 * фатализировал "Import failed" при живой job. `response.status === 403` (invalid_nonce /
 * insufficient permissions — AjaxGuard::require()/Nonce::verify_or_die()) остаётся
 * терминальным: повторный опрос с тем же протухшим nonce исход не изменит. Остальной
 * transport-класс (network reject, иной !response.ok, невалидный JSON) получает допуск
 * MAX_CONSECUTIVE_TRANSPORT_FAILURES подряд сбоев с текущим pollIntervalMs как бэкоффом,
 * прежде чем фатализировать — внутри уже существующего deadlineMs, не сверх него.
 *
 * @param {number} jobId
 * @param {object} settings window.PlathixSettings (ajaxUrl, nonce)
 * @param {(key: string, fallback: string) => string} t
 * @param {(type: string, message: string) => void} setStatus
 * @param {number} [deadlineMs=120000]
 * @param {number} [pollIntervalMs=2000]
 * @returns {Promise<object>}
 */
export async function pollJob(jobId, settings, t, setStatus, deadlineMs = 2 * 60 * 1000, pollIntervalMs = 2000) {
    const deadline = Date.now() + deadlineMs;
    const MAX_CONSECUTIVE_TRANSPORT_FAILURES = 5;
    let consecutiveTransportFailures = 0;

    while (Date.now() < deadline) {
        const formData = new FormData();
        formData.append('action', 'plathix_import_status');
        formData.append('job_id', String(jobId));
        formData.append('nonce', settings.nonce || '');

        let response = null;
        try {
            response = await fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            });
        } catch (e) {
            response = null;
        }

        let payload = null;
        if (response) {
            try {
                payload = await response.json();
            } catch (e) {
                payload = null;
            }
        }

        if (!response || !response.ok || !payload?.success) {
            if (response?.status === 403) {
                throw new Error(payload?.data?.message || t('import_failed', 'Import failed.'));
            }

            consecutiveTransportFailures += 1;
            if (consecutiveTransportFailures >= MAX_CONSECUTIVE_TRANSPORT_FAILURES) {
                throw new Error(
                    t(
                        'import_status_unstable',
                        'Could not check import status — connection is unstable. Please wait or try again later.'
                    )
                );
            }

            setStatus('info', t('import_running', 'Import is running...'));
            await new Promise((resolve) => window.setTimeout(resolve, pollIntervalMs));
            continue;
        }

        consecutiveTransportFailures = 0;
        const status = payload.data?.status;

        if (status === 'complete') {
            return payload.data?.result || {};
        }

        if (status === 'failed' || status === 'canceled' || status === 'not_found') {
            throw new Error(t('import_failed', 'Import failed.'));
        }

        setStatus('info', t('import_running', 'Import is running...'));
        await new Promise((resolve) => window.setTimeout(resolve, pollIntervalMs));
    }

    throw new Error(t('import_timeout', 'Import is still pending. Action Scheduler runner may be unavailable.'));
}
