/*
 * DataTables configured for this application; import it instead of 'datatables.net-bs5'.
 * DataTables 3 does not import jQuery: register it (creates $.fn.DataTable) and the Bootstrap 5
 * integration, and use the Italian UI everywhere.
 */
import $ from 'jquery';
import * as bootstrap from 'bootstrap';
import DataTable from 'datatables.net-bs5';
import dataTablesItalian from 'datatables.net-plugins/i18n/it-IT.mjs';

DataTable.use($);
DataTable.use(bootstrap);
$.extend(true, DataTable.defaults, { language: dataTablesItalian });

export default DataTable;
