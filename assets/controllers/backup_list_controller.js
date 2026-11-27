import { Controller } from '@hotwired/stimulus';
import DataTable from '../lib/datatables.js';
import { confirmDanger, escapeHtml, showToast } from '../lib/ui.js';

/*
 * Backup list: click on a row opens the dump in a new tab, delete button removes the file.
 */
export default class extends Controller {
    static targets = ['table'];
    static values = { deleteUrl: String };

    connect() {
        if (this.hasTableTarget) {
            this.table = new DataTable(this.tableTarget, {
                order: [[2, 'desc']],
                pageLength: 25,
                columnDefs: [{ orderable: false, targets: 3 }],
            });
        }
    }

    disconnect() {
        this.table?.destroy();
    }

    open(event) {
        // Buttons and links inside the row (download, delete) keep their own behaviour.
        if (event.target.closest('a, button')) return;
        window.open(event.currentTarget.dataset.viewUrl, '_blank', 'noopener');
    }

    async delete(event) {
        const button = event.currentTarget;
        const filename = button.dataset.filename;
        if (!await confirmDanger({ title: 'Elimina backup', text: `Vuoi eliminare definitivamente il file "${filename}"?`, confirmButtonText: 'Elimina' })) {
            return;
        }
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        try {
            const body = new FormData();
            body.append('filename', filename);
            const json = await (await fetch(this.deleteUrlValue, { method: 'POST', body })).json();
            showToast(escapeHtml(json.message), json.is_valid);
            if (json.is_valid) {
                this.table.row(button.closest('tr')).remove().draw();
            }
        } catch {
            showToast('Errore di rete.', false);
        } finally {
            button.disabled = false;
            button.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
        }
    }
}
