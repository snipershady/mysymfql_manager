import { Controller } from '@hotwired/stimulus';
import DataTable from '../lib/datatables.js';
import { Modal } from 'bootstrap';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';
import { escapeHtml, showToast } from '../lib/ui.js';
import { backupOrEnqueue, enqueueBackup, formData, withBusyButton } from '../lib/backup.js';

/*
 * Tables of a database: statistics, backup (immediate or queued), restore, empty, SHOW CREATE,
 * optimize, drop.
 */
const dash = '<span class="text-muted">—</span>';
const ENGINE_COLORS = { InnoDB: 'primary', MyISAM: 'warning' };

function number(data, type) {
    if ('sort' === type || 'type' === type) return data ?? 0;

    return data != null ? Number(data).toLocaleString('it-IT') : '—';
}

function size(data, type) {
    if ('sort' === type || 'type' === type) return data ?? 0;

    return data != null
        ? `<span class="text-end d-block">${Number(data).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>`
        : '—';
}

function comment(text) {
    if (!text) return dash;

    return `<span title="${escapeHtml(text)}" class="small">${escapeHtml(text.length > 40 ? `${text.substring(0, 40)}…` : text)}</span>`;
}

export default class extends Controller {
    static targets = ['table', 'modal', 'modalTitle', 'modalSpinner', 'modalContent', 'modalError', 'copyButton'];
    static values = {
        name: String,
        db: String,
        urls: Object, // tables, backup, enqueue, restore, empty, drop, optimize, showCreate
    };

    connect() {
        this.dataTable = new DataTable(this.tableTarget, {
            processing: true,
            paging: true,
            pageLength: 25,
            lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'Tutti']],
            ajax: {
                url: this.urlsValue.tables,
                dataSrc: 'data',
                data: (params) => Object.assign(params, { name: this.nameValue, db_name: this.dbValue }),
            },
            columns: [
                { data: 'nome_tabella', render: (name) => `<i class="fa-solid fa-table text-secondary me-2"></i>${escapeHtml(name)}` },
                { data: 'engine', render: (engine) => `<span class="badge bg-${ENGINE_COLORS[engine] ?? 'secondary'}">${escapeHtml(engine ?? '—')}</span>` },
                { data: 'collation', render: (c) => `<span class="text-muted small">${escapeHtml(c ?? '—')}</span>` },
                { data: 'record', render: number, className: 'text-end' },
                { data: 'dimensione', render: size, className: 'text-end' },
                { data: 'empty_space', render: size, className: 'text-end' },
                { data: 'auto_increment', render: number, className: 'text-end' },
                { data: 'update_time', render: (t) => (t ? escapeHtml(t) : dash) },
                { data: 'commento', render: comment, orderable: false },
                { data: null, render: (d, t, row) => this.actions(row.nome_tabella), orderable: false, searchable: false },
            ],
        });
    }

    disconnect() {
        this.dataTable?.destroy();
    }

    actions(table) {
        const t = escapeHtml(table);
        const restoreUrl = `${this.urlsValue.restore}?${new URLSearchParams({ name: this.nameValue, db_name: this.dbValue, table })}`;
        const button = (action, css, icon, label, title) => `
            <button class="btn btn-sm ${css}" data-action="tables#${action}" data-table="${t}" title="${title}">
                <i class="fa-solid ${icon} me-1"></i>${label}
            </button>`;

        return `
            <div class="d-flex gap-1 justify-content-end flex-wrap">
                ${button('backup', 'btn-outline-primary', 'fa-cloud-arrow-down', 'Backup', 'Backup tabella')}
                ${button('enqueue', 'btn-outline-info', 'fa-list-check', 'Accoda backup', 'Accoda backup tabella')}
                <a href="${escapeHtml(restoreUrl)}" class="btn btn-sm btn-outline-warning" title="Ripristina tabella">
                    <i class="fa-solid fa-cloud-arrow-up me-1"></i>Ripristina
                </a>
                ${button('empty', 'btn-outline-secondary', 'fa-eraser', 'Svuota', 'Svuota tabella')}
                ${button('showCreate', 'btn-outline-info', 'fa-code', 'Show Create', 'Show Create Table')}
                ${button('optimize', 'btn-outline-success', 'fa-bolt', 'Ottimizza', 'Ottimizza tabella')}
                ${button('drop', 'btn-outline-danger', 'fa-trash', 'Elimina', 'Elimina tabella')}
            </div>`;
    }

    params(table) {
        return { name: this.nameValue, db_name: this.dbValue, table };
    }

    reload(resetPaging = false) {
        this.dataTable.ajax.reload(null, resetPaging);
    }

    async backup(event) {
        const button = event.currentTarget;
        await withBusyButton(button, 'Backup…', async () => {
            const json = await backupOrEnqueue(this.urlsValue.backup, this.urlsValue.enqueue, this.params(button.dataset.table));
            if (json) showToast(escapeHtml(json.message), json.is_valid);
        });
    }

    async enqueue(event) {
        const button = event.currentTarget;
        await withBusyButton(button, 'Accodando…', () => enqueueBackup(this.urlsValue.enqueue, this.params(button.dataset.table)));
    }

    /** POST of a table action; toast with the server message, returns whether it succeeded. */
    async post(url, button, label) {
        return withBusyButton(button, label, async () => {
            try {
                const json = await (await fetch(url, { method: 'POST', body: formData(this.params(button.dataset.table)) })).json();
                showToast(escapeHtml(json.message ?? (json.is_valid ? 'Operazione completata.' : 'Errore.')), json.is_valid);

                return json.is_valid;
            } catch {
                showToast('Errore di rete.', false);

                return false;
            }
        });
    }

    /** Destructive actions ask to type the table name. */
    async confirmTyped(table, { title, html, icon, confirmButtonText, confirmButtonColor }) {
        const result = await Swal.fire({
            title,
            html,
            icon,
            showCancelButton: true,
            confirmButtonText,
            cancelButtonText: 'Annulla',
            confirmButtonColor,
            cancelButtonColor: '#6c757d',
            input: 'text',
            inputPlaceholder: `Digita "${table}" per confermare`,
            preConfirm: (value) => {
                if (value !== table) Swal.showValidationMessage('Il nome inserito non corrisponde.');
            },
        });

        return result.isConfirmed;
    }

    async empty(event) {
        const button = event.currentTarget;
        const table = button.dataset.table;
        if (await this.confirmTyped(table, {
            title: 'Svuota tabella',
            html: `Tutti i record di <strong>${escapeHtml(table)}</strong> verranno eliminati e l'auto-increment verrà azzerato.<br>L'operazione è irreversibile.`,
            icon: 'warning',
            confirmButtonText: 'Svuota',
            confirmButtonColor: '#6c757d',
        })) {
            await this.post(this.urlsValue.empty, button, 'Svuoto…');
        }
    }

    async drop(event) {
        const button = event.currentTarget;
        const table = button.dataset.table;
        if (await this.confirmTyped(table, {
            title: 'Elimina tabella',
            html: `La tabella <strong>${escapeHtml(table)}</strong> verrà eliminata definitivamente.<br>L'operazione è irreversibile.`,
            icon: 'error',
            confirmButtonText: 'Elimina',
            confirmButtonColor: '#dc3545',
        }) && await this.post(this.urlsValue.drop, button, 'Elimino…')) {
            this.reload(true);
        }
    }

    async optimize(event) {
        const button = event.currentTarget;
        const table = button.dataset.table;
        await withBusyButton(button, 'Ottimizzando…', async () => {
            try {
                const json = await (await fetch(this.urlsValue.optimize, { method: 'POST', body: formData(this.params(table)) })).json();
                showToast(json.is_valid ? `Tabella "${escapeHtml(table)}" ottimizzata.` : 'Errore durante l\'ottimizzazione.', json.is_valid);
                if (json.is_valid) this.reload();
            } catch {
                showToast('Errore di rete.', false);
            }
        });
    }

    async showCreate(event) {
        const table = event.currentTarget.dataset.table;
        this.modalTitleTarget.textContent = table;
        this.modalSpinnerTarget.classList.remove('d-none');
        this.modalContentTarget.classList.add('d-none');
        this.modalErrorTarget.classList.add('d-none');
        this.ddl = null;
        Modal.getOrCreateInstance(this.modalTarget).show();
        try {
            const json = await (await fetch(`${this.urlsValue.showCreate}?${new URLSearchParams(this.params(table))}`)).json();
            if (!json.is_valid || !json.data?.length) {
                this.modalErrorTarget.classList.remove('d-none');

                return;
            }
            this.ddl = json.data[0]['Create Table'] ?? '';
            this.modalContentTarget.textContent = this.ddl;
            this.modalContentTarget.classList.remove('d-none');
        } catch {
            this.modalErrorTarget.classList.remove('d-none');
        } finally {
            this.modalSpinnerTarget.classList.add('d-none');
        }
    }

    async copyDdl() {
        if (!this.ddl) return;
        await navigator.clipboard.writeText(this.ddl);
        this.copyButtonTarget.innerHTML = '<i class="fa-solid fa-check me-1"></i>Copiato!';
        setTimeout(() => { this.copyButtonTarget.innerHTML = '<i class="fa-solid fa-copy me-1"></i>Copia DDL'; }, 2000);
    }
}
