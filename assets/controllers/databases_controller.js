import { Controller } from '@hotwired/stimulus';
import DataTable from '../lib/datatables.js';
import { Modal } from 'bootstrap';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';
import { escapeHtml } from '../lib/ui.js';
import { backupOrEnqueue, formData, withBusyButton } from '../lib/backup.js';
import { hostValue, privilegesValue, resetHost, setPrivileges } from '../lib/account-form.js';

/*
 * Databases of the selected server: list with backup / drop, links to the server pages,
 * creation of a database with its MySQL account.
 */
function size(bytes) {
    if (!bytes) return '<span class="text-muted">—</span>';
    const kb = bytes / 1024;
    if (kb >= 1024 * 1024) return `${(kb / 1024 / 1024).toFixed(2)} GB`;
    if (kb >= 1024) return `${(kb / 1024).toFixed(2)} MB`;

    return `${kb.toFixed(1)} KB`;
}

const alertBox = (ok, title, html) => Swal.fire({ title: ok ? title : 'Errore', html, icon: ok ? 'success' : 'error' });

export default class extends Controller {
    static targets = [
        'select', 'card', 'versionBadge', 'serverLink', 'createButton', 'table',
        'modal', 'dbName', 'charset', 'collation', 'username', 'password', 'host', 'privileges', 'submit',
    ];

    static values = { urls: Object }; // databases, tables, create, drop, backup, enqueue

    connect() {
        // Pre-selected server (?name=…): load it right away.
        this.serverChanged();
    }

    disconnect() {
        this.dataTable?.destroy();
    }

    get server() {
        return this.selectTarget.value;
    }

    serverChanged() {
        const name = this.server;
        this.createButtonTarget.disabled = !name;
        this.serverLinkTargets.forEach((a) => {
            a.href = name ? `${a.dataset.path}?name=${encodeURIComponent(name)}` : '#';
            a.classList.toggle('disabled', !name);
        });
        this.cardTarget.hidden = !name;
        if (!name) {
            this.versionBadgeTarget.hidden = true;

            return;
        }
        if (this.dataTable) {
            this.dataTable.ajax.reload();
        } else {
            this.initTable();
        }
    }

    initTable() {
        this.dataTable = new DataTable(this.tableTarget, {
            processing: true,
            paging: true,
            pageLength: 25,
            lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'Tutti']],
            ajax: {
                url: this.urlsValue.databases,
                dataSrc: (json) => {
                    this.versionBadgeTarget.hidden = !json.version;
                    if (json.version) {
                        this.versionBadgeTarget.innerHTML = `<i class="fa-solid fa-code-branch me-1"></i>MySQL ${escapeHtml(json.version)}`;
                    }

                    return json.data;
                },
                data: (params) => Object.assign(params, { name: this.server }),
            },
            columns: [
                { data: 'db_name', render: (db) => `<i class="fa-solid fa-database text-secondary me-2"></i>${escapeHtml(db)}` },
                { data: 'table_count', render: (n) => `<span class="badge bg-light text-dark border">${n}</span>` },
                { data: 'size_bytes', render: size },
                { data: null, orderable: false, className: 'text-end', render: (d, t, row) => this.actions(row.db_name) },
            ],
        });
    }

    actions(db) {
        const d = escapeHtml(db);
        const tablesUrl = `${this.urlsValue.tables}?${new URLSearchParams({ name: this.server, db_name: db })}`;

        return `
            <div class="d-flex gap-2 justify-content-end">
                <a href="${escapeHtml(tablesUrl)}" class="btn btn-sm btn-outline-primary" title="Mostra info e tabelle">
                    <i class="fa-solid fa-table me-1"></i>Dettagli
                </a>
                <button class="btn btn-sm btn-outline-secondary" data-action="databases#backup" data-db="${d}" title="Backup completo database">
                    <i class="fa-solid fa-cloud-arrow-down me-1"></i>Backup
                </button>
                <button class="btn btn-sm btn-outline-info" data-action="databases#enqueue" data-db="${d}" title="Accoda backup database">
                    <i class="fa-solid fa-list-check me-1"></i>Accoda backup
                </button>
                <button class="btn btn-sm btn-outline-danger" data-action="databases#drop" data-db="${d}" title="Elimina database">
                    <i class="fa-solid fa-trash me-1"></i>Elimina
                </button>
            </div>`;
    }

    async drop(event) {
        const button = event.currentTarget;
        const db = button.dataset.db;
        const result = await Swal.fire({
            title: 'Elimina database',
            html: `Il database <strong>${escapeHtml(db)}</strong> e tutti i suoi dati verranno eliminati definitivamente.<br>L'operazione è irreversibile.`,
            icon: 'error',
            showCancelButton: true,
            confirmButtonText: 'Elimina',
            cancelButtonText: 'Annulla',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            input: 'text',
            inputPlaceholder: `Digita "${db}" per confermare`,
            preConfirm: (value) => {
                if (value !== db) Swal.showValidationMessage('Il nome inserito non corrisponde.');
            },
        });
        if (!result.isConfirmed) return;
        await withBusyButton(button, '', async () => {
            try {
                const json = await (await fetch(this.urlsValue.drop, { method: 'POST', body: formData({ name: this.server, db_name: db }) })).json();
                if (json.is_valid) this.dataTable.ajax.reload();
                alertBox(json.is_valid, 'Fatto!', escapeHtml(json.message));
            } catch {
                alertBox(false, '', 'Errore di rete.');
            }
        });
    }

    async backup(event) {
        const button = event.currentTarget;
        const db = button.dataset.db;
        await withBusyButton(button, 'Backup…', async () => {
            const json = await backupOrEnqueue(this.urlsValue.backup, this.urlsValue.enqueue, { name: this.server, db_name: db });
            if (json) {
                alertBox(json.is_valid, 'Backup completato', json.is_valid
                    ? `Backup di <strong>${escapeHtml(db)}</strong> completato.<br><span class="font-monospace small text-muted">${escapeHtml(json.backup_filename)}</span>`
                    : escapeHtml(json.message));
            }
        });
    }

    async enqueue(event) {
        const button = event.currentTarget;
        const db = button.dataset.db;
        await withBusyButton(button, 'Accodando…', async () => {
            try {
                const json = await (await fetch(this.urlsValue.enqueue, { method: 'POST', body: formData({ name: this.server, db_name: db }) })).json();
                Swal.fire({
                    title: json.is_valid ? 'Backup in coda' : 'Errore',
                    html: json.is_valid ? `Il backup di <strong>${escapeHtml(db)}</strong> è stato accodato e verrà eseguito a breve.` : escapeHtml(json.message),
                    icon: json.is_valid ? 'info' : 'error',
                });
            } catch {
                alertBox(false, '', 'Errore di rete.');
            }
        });
    }

    async create() {
        const dbName = this.dbNameTarget.value.trim();
        if (!/^\w+$/.test(dbName)) {
            alertBox(false, '', 'Il nome del database non è valido.');

            return;
        }
        const username = this.usernameTarget.value.trim();
        const host = hostValue(this.hostTarget);
        if (null === host) {
            alertBox(false, '', 'Inserisci l\'host personalizzato.');

            return;
        }
        const privileges = privilegesValue(this.privilegesTarget);
        if (username && !privileges) {
            alertBox(false, '', 'Seleziona almeno un privilegio o usa ALL PRIVILEGES.');

            return;
        }
        await withBusyButton(this.submitTarget, 'Creazione…', async () => {
            try {
                const json = await (await fetch(this.urlsValue.create, {
                    method: 'POST',
                    body: formData({
                        name: this.server,
                        db_name: dbName,
                        charset: this.charsetTarget.value,
                        collation: this.collationTarget.value,
                        username,
                        password: this.passwordTarget.value,
                        user_host: host,
                        privileges,
                    }),
                })).json();
                if (json.is_valid) {
                    Modal.getInstance(this.modalTarget)?.hide();
                    this.dataTable?.ajax.reload();
                }
                alertBox(json.is_valid, 'Fatto!', escapeHtml(json.message));
            } catch {
                alertBox(false, '', 'Errore di rete.');
            }
        });
    }

    /** Bound to hidden.bs.modal: empty form for the next database. */
    resetForm() {
        this.dbNameTarget.value = '';
        this.usernameTarget.value = '';
        this.passwordTarget.value = '';
        this.passwordTarget.type = 'password';
        this.modalTarget.querySelector('[data-password-toggle-target="icon"]')?.setAttribute('class', 'fa-solid fa-eye');
        resetHost(this.hostTarget);
        setPrivileges(this.privilegesTarget, null);
        this.charsetTarget.value = 'utf8mb4';
        this.collationTarget.value = 'utf8mb4_0900_ai_ci';
    }
}
