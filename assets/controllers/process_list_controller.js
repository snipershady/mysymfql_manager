import { Controller } from '@hotwired/stimulus';
import DataTable from '../lib/datatables.js';
import { confirmDanger, escapeHtml, showToast } from '../lib/ui.js';

/*
 * Process list of a server (performance_schema.processlist) with kill by row or by PID.
 */
function renderTime(seconds) {
    if (seconds >= 60) return `<span class="text-danger fw-semibold">${seconds}</span>`;
    if (seconds >= 10) return `<span class="text-warning">${seconds}</span>`;

    return seconds;
}

function renderInfo(info) {
    if (!info) return '<span class="text-muted">—</span>';
    const escaped = escapeHtml(info);

    return `<span class="font-monospace small" title="${escaped}">${escapeHtml(info.substring(0, 80))}${info.length > 80 ? '…' : ''}</span>`;
}

const orDash = (value) => (value == null ? '<span class="text-muted">—</span>' : escapeHtml(value));

export default class extends Controller {
    static targets = ['table', 'pid'];
    static values = { name: String, dataUrl: String, killUrl: String };

    connect() {
        this.table = new DataTable(this.tableTarget, {
            processing: true,
            paging: true,
            pageLength: 25,
            order: [[5, 'desc']],
            ajax: {
                url: this.dataUrlValue,
                dataSrc: 'data',
                data: (params) => { params.name = this.nameValue; return params; },
            },
            columns: [
                { data: 'id', render: (id) => `<span class="badge bg-secondary font-monospace">${id}</span>` },
                { data: 'user', render: escapeHtml },
                { data: 'host', render: escapeHtml },
                { data: 'db', render: orDash },
                { data: 'command', render: escapeHtml },
                { data: 'time', render: renderTime },
                { data: 'state', render: orDash },
                { data: 'info', render: renderInfo },
                {
                    data: null,
                    orderable: false,
                    className: 'text-end',
                    render: (d, t, row) => `<button class="btn btn-sm btn-outline-danger" data-action="process-list#killRow" data-pid="${row.id}" title="Kill processo ${row.id}"><i class="fa-solid fa-stop"></i></button>`,
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

    killRow(event) {
        const button = event.currentTarget;
        this.kill(parseInt(button.dataset.pid, 10), button);
    }

    killTyped() {
        const pid = parseInt(this.pidTarget.value, 10);
        if (!pid || pid < 1) {
            showToast('Inserisci un PID valido.', false);

            return;
        }
        this.kill(pid, null);
    }

    async kill(pid, button) {
        if (!await confirmDanger({ title: `Termina processo ${pid}`, text: `Vuoi terminare forzatamente il processo con PID ${pid}?`, confirmButtonText: 'Kill' })) {
            return;
        }
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        }
        try {
            const body = new FormData();
            body.append('name', this.nameValue);
            body.append('pid', pid);
            const json = await (await fetch(this.killUrlValue, { method: 'POST', body })).json();
            showToast(escapeHtml(json.message), json.is_valid);
            if (json.is_valid) this.refresh();
        } catch {
            showToast('Errore di rete.', false);
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i class="fa-solid fa-stop"></i>';
            }
        }
    }
}
