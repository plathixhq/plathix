

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
