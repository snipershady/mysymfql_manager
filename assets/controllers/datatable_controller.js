import { Controller } from '@hotwired/stimulus';
import DataTable from '../lib/datatables.js';

/*
 * Client-side DataTable on a server-rendered <table>:
 *   <div {{ stimulus_controller('datatable', {options: {pageLength: 25}}) }}>
 *       <table {{ stimulus_target('datatable', 'table') }}>…</table>
 *   </div>
 * The controller must sit on a wrapper, not on the <table>: DataTables moves the table into its own
 * container, and a controller on the table itself would be disconnected/reconnected in a loop.
 * Language (Italian) and Bootstrap styling come from the defaults set in assets/app.js.
 */
export default class extends Controller {
    static targets = ['table'];
    static values = { options: { type: Object, default: {} } };

    connect() {
        this.dataTable = new DataTable(this.tableTarget, this.optionsValue);
    }

    disconnect() {
        this.dataTable?.destroy();
    }
}
