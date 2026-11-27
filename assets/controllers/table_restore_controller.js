import { Controller } from '@hotwired/stimulus';
import DataTable from '../lib/datatables.js';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';
import { escapeHtml, showToast } from '../lib/ui.js';

/*
 * Restore page: list of the backups visible to the user, restore of one of them into the database.
 */
export default class extends Controller {
    static targets = ['table'];
    static values = { name: String, db: String, table: String, restoreUrl: String };

    connect() {
        if (this.hasTableTarget) {
            this.dataTable = new DataTable(this.tableTarget, { paging: true, pageLength: 25, order: [] });
        }
    }

    disconnect() {
        this.dataTable?.destroy();
    }

    async restore(event) {
        const button = event.currentTarget;
        const backupFilename = button.dataset.filename;
        const target = this.tableValue
            ? `nella tabella <strong>${escapeHtml(this.tableValue)}</strong> del database <strong>${escapeHtml(this.dbValue)}</strong>`
            : `nel database <strong>${escapeHtml(this.dbValue)}</strong>`;
        const result = await Swal.fire({
            title: 'Conferma ripristino',
            html: `Ripristino del backup:<br><code class="text-break">${escapeHtml(backupFilename)}</code><br><br>${target}.<br><br>I dati esistenti verranno sovrascritti.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ripristina',
            cancelButtonText: 'Annulla',
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#6c757d',
        });
        if (!result.isConfirmed) return;

        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Ripristino…';
        try {
            const body = new FormData();
            body.append('name', this.nameValue);
            body.append('db_name', this.dbValue);
            body.append('backup_filename', backupFilename);
            const json = await (await fetch(this.restoreUrlValue, { method: 'POST', body })).json();
            showToast(escapeHtml(json.message), json.is_valid);
        } catch {
            showToast('Errore di rete durante il ripristino.', false);
        } finally {
            button.disabled = false;
            button.innerHTML = '<i class="fa-solid fa-cloud-arrow-up me-1"></i>Ripristina';
        }
    }
}
