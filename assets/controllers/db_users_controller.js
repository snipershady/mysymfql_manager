import { Controller } from '@hotwired/stimulus';
import DataTable from '../lib/datatables.js';
import { Modal } from 'bootstrap';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';
import { escapeHtml } from '../lib/ui.js';
import { formData, withBusyButton } from '../lib/backup.js';
import { hostValue, privilegesValue, resetHost, setPrivileges } from '../lib/account-form.js';

/*
 * MySQL accounts of a server (optionally in the context of one database): create, change password,
 * edit per-database grants, drop.
 */
const result = (ok, message) => Swal.fire({ title: ok ? 'Fatto!' : 'Errore', text: message, icon: ok ? 'success' : 'error' });

export default class extends Controller {
    static targets = [
        'table',
        'createModal', 'createUsername', 'createPassword', 'createHost', 'createPrivileges', 'createSubmit',
        'passwordModal', 'passwordUser', 'newPassword', 'newPasswordConfirm', 'passwordSubmit',
        'grantModal', 'grantUser', 'grantLoading', 'grantBody', 'grantDatabases', 'grantPrivileges', 'grantSubmit',
    ];

    static values = { name: String, db: String, urls: Object }; // users, drop, create, password, grants, grantSave

    connect() {
        const hasDb = '' !== this.dbValue;
        this.dataTable = new DataTable(this.tableTarget, {
            processing: true,
            paging: true,
            pageLength: 25,
            lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'Tutti']],
            ajax: {
                url: this.urlsValue.users,
                dataSrc: 'data',
                data: (params) => Object.assign(params, { name: this.nameValue, db_name: this.dbValue }),
            },
            columns: [
                { data: 'user', render: (u) => `<i class="fa-solid fa-user text-secondary me-2"></i><span class="font-monospace">${escapeHtml(u)}</span>` },
                { data: 'host', render: (h) => `<span class="font-monospace text-muted">${escapeHtml(h)}</span>` },
                ...(hasDb ? [{
                    data: 'has_db_grant',
                    orderable: false,
                    render: (granted) => (granted
                        ? '<span class="badge text-bg-success"><i class="fa-solid fa-check me-1"></i>Sì</span>'
                        : '<span class="badge text-bg-light text-muted">No</span>'),
                }] : []),
                {
                    data: 'account_locked',
                    orderable: false,
                    render: (locked) => (locked
                        ? '<span class="badge text-bg-warning"><i class="fa-solid fa-lock me-1"></i>Bloccato</span>'
                        : '<span class="badge text-bg-success"><i class="fa-solid fa-lock-open me-1"></i>Attivo</span>'),
                },
                { data: null, orderable: false, className: 'text-end', render: (d, t, row) => this.actions(row) },
            ],
        });
    }

    disconnect() {
        this.dataTable?.destroy();
    }

    actions(row) {
        const attrs = `data-user="${escapeHtml(row.user)}" data-host="${escapeHtml(row.host)}"`;

        return `
            <div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-sm btn-outline-primary" data-action="db-users#openGrants" ${attrs} title="Gestione Grant">
                    <i class="fa-solid fa-shield-halved"></i>
                </button>
                <button class="btn btn-sm btn-outline-warning" data-action="db-users#openPassword" ${attrs} title="Cambia password">
                    <i class="fa-solid fa-key"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" data-action="db-users#drop" ${attrs} title="Elimina utente">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>`;
    }

    reload() {
        this.dataTable.ajax.reload();
    }

    async post(url, params) {
        try {
            return await (await fetch(url, { method: 'POST', body: formData({ name: this.nameValue, ...params }) })).json();
        } catch {
            return { is_valid: false, message: 'Errore di rete.' };
        }
    }

    // ── Drop ────────────────────────────────────────────────────────────────────────────────────────
    async drop(event) {
        const button = event.currentTarget;
        const { user, host } = button.dataset;
        const confirmation = await Swal.fire({
            title: 'Elimina utente',
            html: `L'utente <strong>${escapeHtml(user)}@${escapeHtml(host)}</strong> verrà eliminato definitivamente. Digitare <b>${escapeHtml(user)}</b> per procedere alla cancellazione`,
            icon: 'error',
            showCancelButton: true,
            confirmButtonText: 'Elimina',
            cancelButtonText: 'Annulla',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            input: 'text',
            inputPlaceholder: `Digita "${user}" per confermare`,
            preConfirm: (value) => {
                if (value !== user) Swal.showValidationMessage('Il nome inserito non corrisponde.');
            },
        });
        if (!confirmation.isConfirmed) return;
        await withBusyButton(button, '', async () => {
            const json = await this.post(this.urlsValue.drop, { username: user, user_host: host });
            if (json.is_valid) this.reload();
            result(json.is_valid, json.message);
        });
    }

    // ── Change password ─────────────────────────────────────────────────────────────────────────────
    openPassword(event) {
        const { user, host } = event.currentTarget.dataset;
        this.passwordAccount = { user, host };
        this.passwordUserTarget.textContent = `${user}@${host}`;
        this.newPasswordTarget.value = '';
        this.newPasswordConfirmTarget.value = '';
        Modal.getOrCreateInstance(this.passwordModalTarget).show();
    }

    async changePassword() {
        const password = this.newPasswordTarget.value;
        if (!password) {
            result(false, 'Inserisci la nuova password.');

            return;
        }
        if (password !== this.newPasswordConfirmTarget.value) {
            result(false, 'Le password non coincidono.');

            return;
        }
        await withBusyButton(this.passwordSubmitTarget, 'Aggiornamento…', async () => {
            const json = await this.post(this.urlsValue.password, { username: this.passwordAccount.user, user_host: this.passwordAccount.host, password });
            if (json.is_valid) Modal.getInstance(this.passwordModalTarget)?.hide();
            result(json.is_valid, json.message);
        });
    }

    // ── Create ──────────────────────────────────────────────────────────────────────────────────────
    async create() {
        const username = this.createUsernameTarget.value.trim();
        const password = this.createPasswordTarget.value;
        const host = hostValue(this.createHostTarget);
        const privileges = privilegesValue(this.createPrivilegesTarget);
        if (!username) { result(false, 'Il nome utente è obbligatorio.'); return; }
        if (!password) { result(false, 'La password è obbligatoria.'); return; }
        if (null === host) { result(false, 'Inserisci l\'host personalizzato.'); return; }
        if (!privileges) { result(false, 'Seleziona almeno un privilegio o usa ALL PRIVILEGES.'); return; }

        await withBusyButton(this.createSubmitTarget, 'Creazione…', async () => {
            const json = await this.post(this.urlsValue.create, { db_name: this.dbValue, username, password, user_host: host, privileges });
            if (json.is_valid) {
                Modal.getInstance(this.createModalTarget)?.hide();
                this.reload();
            }
            result(json.is_valid, json.message);
        });
    }

    /** Bound to hidden.bs.modal of the create modal. */
    resetCreate() {
        this.createUsernameTarget.value = '';
        this.createPasswordTarget.value = '';
        this.createPasswordTarget.type = 'password';
        resetHost(this.createHostTarget);
        setPrivileges(this.createPrivilegesTarget, null);
    }

    // ── Grants ──────────────────────────────────────────────────────────────────────────────────────
    async openGrants(event) {
        const { user, host } = event.currentTarget.dataset;
        this.grantAccount = { user, host };
        this.grantUserTarget.textContent = `${user}@${host}`;
        this.grantLoadingTarget.hidden = false;
        this.grantLoadingTarget.innerHTML = '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Caricamento…';
        this.grantBodyTarget.hidden = true;
        this.grantSubmitTarget.hidden = true;
        this.grantDatabasesTarget.replaceChildren();
        setPrivileges(this.grantPrivilegesTarget, null);
        Modal.getOrCreateInstance(this.grantModalTarget).show();

        try {
            const json = await (await fetch(`${this.urlsValue.grants}?${new URLSearchParams({ name: this.nameValue, username: user, user_host: host })}`)).json();
            const granted = new Map(json.grants.map((g) => [g.db, g]));
            if (0 === json.databases.length) {
                this.grantDatabasesTarget.innerHTML = '<p class="text-muted small mb-0 p-1">Nessun database trovato sul server.</p>';
            }
            json.databases.forEach(({ db_name: db }) => this.grantDatabasesTarget.appendChild(this.databaseRow(db, granted.get(db))));
            // Pre-select the privileges of the first granted database.
            setPrivileges(this.grantPrivilegesTarget, json.grants[0] ?? null);
            this.grantLoadingTarget.hidden = true;
            this.grantBodyTarget.hidden = false;
            this.grantSubmitTarget.hidden = false;
        } catch {
            this.grantLoadingTarget.innerHTML = '<p class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i>Errore nel caricamento dei dati.</p>';
        }
    }

    databaseRow(db, grant) {
        const id = `grant-db-${db}`;
        const row = document.createElement('div');
        row.className = 'form-check d-flex align-items-center py-1 border-bottom';
        const badge = !grant ? '' : (grant.all_privileges
            ? '<span class="badge text-bg-danger ms-2">ALL PRIVILEGES</span>'
            : `<span class="badge text-bg-secondary ms-2">${escapeHtml(grant.privileges.join(', '))}</span>`);
        row.innerHTML = `
            <input class="form-check-input flex-shrink-0" type="checkbox" id="${escapeHtml(id)}" value="${escapeHtml(db)}"
                   data-previously-granted="${grant ? '1' : '0'}" ${grant ? 'checked' : ''}>
            <label class="form-check-label font-monospace ms-2 flex-grow-1" for="${escapeHtml(id)}">${escapeHtml(db)}</label>
            ${badge}`;

        return row;
    }

    async saveGrants() {
        const privileges = privilegesValue(this.grantPrivilegesTarget);
        if (!privileges) {
            result(false, 'Seleziona almeno un privilegio o usa ALL PRIVILEGES.');

            return;
        }
        const boxes = [...this.grantDatabasesTarget.querySelectorAll('input[type=checkbox]')];
        const grants = boxes.filter((cb) => cb.checked).map((cb) => ({ db: cb.value, privileges }));
        const revoked = boxes.filter((cb) => !cb.checked && '1' === cb.dataset.previouslyGranted).map((cb) => cb.value);

        await withBusyButton(this.grantSubmitTarget, 'Salvataggio…', async () => {
            const json = await this.post(this.urlsValue.grantSave, {
                username: this.grantAccount.user,
                user_host: this.grantAccount.host,
                grants: JSON.stringify(grants),
                revoked_dbs: JSON.stringify(revoked),
            });
            if (json.is_valid) {
                Modal.getInstance(this.grantModalTarget)?.hide();
                this.reload();
            }
            result(json.is_valid, json.message);
        });
    }
}
