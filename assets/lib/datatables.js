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
// autoWidth off: DataTables 3 measures the columns once and pins every one to a pixel width (<colgroup>),
// so the width classes of the headers (cw-*) became hard limits and the action buttons wrapped on several
// lines (very tall rows). Without it the browser sizes the columns from their content, as with DataTables 2.
$.extend(true, DataTable.defaults, { language: dataTablesItalian, autoWidth: false });

export default DataTable;
