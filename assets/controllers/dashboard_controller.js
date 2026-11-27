import { Controller } from '@hotwired/stimulus';
import { escapeHtml } from '../lib/ui.js';

/*
 * Dashboard: statistics and database list of the selected server.
 */
const SYSTEM_DBS = new Set(['information_schema', 'mysql', 'performance_schema', 'sys']);

function formatBytes(bytes) {
    const b = Number(bytes);
    if (!b) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(b) / Math.log(1024));

    return `${parseFloat((b / 1024 ** i).toFixed(1))} ${sizes[i]}`;
}

export default class extends Controller {
    static targets = ['select', 'dbCount', 'connections', 'running', 'blocked', 'tableBody'];
    static values = { statsUrl: String, databasesUrl: String };

    connect() {
        if (this.hasSelectTarget && this.selectTarget.value) {
            this.load();
        }
    }

    load() {
        const name = this.hasSelectTarget ? this.selectTarget.value : '';
        if (name) {
            this.loadStats(name);
        }
    }

    get stats() {
        return [this.dbCountTarget, this.connectionsTarget, this.runningTarget, this.blockedTarget];
    }

    async loadStats(name) {
        this.stats.forEach((el) => { el.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>'; });
        this.message('text-muted', '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Caricamento dati…');
        try {
            const res = await fetch(`${this.statsUrlValue}?name=${encodeURIComponent(name)}`);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();

            this.dbCountTarget.textContent = data.db_count ?? '—';
            this.connectionsTarget.textContent = data.active_connections ?? '—';
            this.runningTarget.textContent = data.running_processes ?? '—';
            this.blockedTarget.textContent = data.blocked_processes ?? '—';
            this.renderTable(data.databases ?? [], name);
        } catch {
            this.stats.forEach((el) => { el.textContent = '—'; });
            this.message('text-danger', '<i class="fa-solid fa-triangle-exclamation me-2"></i>Errore nel caricamento dei dati');
        }
    }

    message(cssClass, html) {
        this.tableBodyTarget.innerHTML = `<tr><td colspan="4" class="text-center ${cssClass} py-4">${html}</td></tr>`;
    }

    renderTable(databases, name) {
        if (databases.length === 0) {
            this.message('text-muted', '<i class="fa-solid fa-circle-info me-2"></i>Nessun database trovato');

            return;
        }
        const schemaUrl = `${this.databasesUrlValue}?name=${encodeURIComponent(name)}`;
        this.tableBodyTarget.innerHTML = databases
            .filter((db) => !SYSTEM_DBS.has(db.db_name))
            .map((db) => `
                <tr>
                    <td class="ps-3">
                        <i class="fa-solid fa-database me-2 text-primary opacity-75"></i>
                        ${escapeHtml(db.db_name)}
                    </td>
                    <td class="text-muted">${formatBytes(db.size_bytes)}</td>
                    <td>${db.table_count}</td>
                    <td>
                        <a href="${schemaUrl}" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Azioni
                        </a>
                    </td>
                </tr>`)
            .join('');
    }
}
