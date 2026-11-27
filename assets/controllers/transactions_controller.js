import { Controller } from '@hotwired/stimulus';
import DataTable from '../lib/datatables.js';
import { confirmDanger, escapeHtml, showToast } from '../lib/ui.js';

/*
 * Open InnoDB transactions of a server (information_schema.INNODB_TRX) with kill of the owning thread.
 */
const STATE_BADGES = {
    RUNNING: 'text-bg-success',
    'LOCK WAIT': 'text-bg-danger',
    'ROLLING BACK': 'text-bg-warning',
    COMMITTING: 'text-bg-info',
};

export default class extends Controller {
    static targets = ['table'];
    static values = { name: String, dataUrl: String, killUrl: String };

    connect() {
        this.table = new DataTable(this.tableTarget, {
            processing: true,
            paging: true,
            pageLength: 25,
            order: [[2, 'asc']],
            ajax: {
                url: this.dataUrlValue,
                dataSrc: 'data',
                data: (params) => { params.name = this.nameValue; return params; },
            },
            columns: [
                { data: 'threadId', render: (id) => `<span class="badge bg-secondary font-monospace">${id}</span>` },
                { data: 'state', render: (state) => `<span class="badge ${STATE_BADGES[state] ?? 'text-bg-secondary'}">${escapeHtml(state)}</span>` },
                { data: 'started', render: escapeHtml },
                { data: 'rowsLocked', render: (rows) => (rows > 0 ? `<span class="fw-semibold text-danger">${rows}</span>` : rows) },
                {
                    data: null,
                    orderable: false,
                    className: 'text-end',
                    render: (d, t, row) => `<button class="btn btn-sm btn-outline-danger" data-action="transactions#kill" data-thread="${row.threadId}" title="Kill thread ${row.threadId}"><i class="fa-solid fa-stop"></i></button>`,
                },
            ],
        });
    }

    disconnect() {
        this.table?.destroy();
    }

    refresh() {
        this.table.ajax.reload(null, false);
    }

    async kill(event) {
        const button = event.currentTarget;
        const threadId = parseInt(button.dataset.thread, 10);
        if (!await confirmDanger({ title: `Termina thread ${threadId}`, text: `Terminare il thread ${threadId}? La sua transazione verrà annullata (rollback).`, confirmButtonText: 'Kill' })) {
            return;
        }
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        try {
            const body = new FormData();
            body.append('name', this.nameValue);
            body.append('pid', threadId);
            const json = await (await fetch(this.killUrlValue, { method: 'POST', body })).json();
            showToast(escapeHtml(json.message), json.is_valid);
            if (json.is_valid) this.refresh();
        } catch {
            showToast('Errore di rete.', false);
        } finally {
            button.disabled = false;
            button.innerHTML = '<i class="fa-solid fa-stop"></i>';
        }
    }
}
