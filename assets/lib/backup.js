/*
 * Backup actions shared by the databases and tables pages.
 */
import { escapeHtml, showToast } from './ui.js';

const formData = (params) => {
    const body = new FormData();
    Object.entries(params).forEach(([k, v]) => body.append(k, v));

    return body;
};

/** Runs `work` with the button disabled and a spinner label; restores the button afterwards. */
export async function withBusyButton(button, label, work) {
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i>${label}`;
    try {
        return await work();
    } finally {
        button.disabled = false;
        button.innerHTML = original;
    }
}

/** Adds a backup request to the queue processed by app:process-backup-queue. */
export async function enqueueBackup(url, params) {
    try {
        const json = await (await fetch(url, { method: 'POST', body: formData(params) })).json();
        showToast(json.is_valid ? 'Backup accodato.' : escapeHtml(json.message), json.is_valid);
    } catch {
        showToast('Impossibile accodare il backup.', false);
    }
}

/**
 * Immediate backup; when it times out (5 minutes) or the server fails (5xx) the request is queued instead.
 * Returns the JSON response of an immediate backup, or null when it was queued / failed.
 */
export async function backupOrEnqueue(backupUrl, enqueueUrl, params) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 300_000);
    try {
        const res = await fetch(backupUrl, { method: 'POST', body: formData(params), signal: controller.signal });
        if (res.status >= 500) {
            await enqueueBackup(enqueueUrl, params);

            return null;
        }

        return await res.json();
    } catch (e) {
        if ('AbortError' === e.name) {
            await enqueueBackup(enqueueUrl, params);
        } else {
            showToast('Errore di rete.', false);
        }

        return null;
    } finally {
        clearTimeout(timeout);
    }
}

export { formData };
