/*
 * Application entrypoint (AssetMapper, see importmap.php). Every library is served from
 * assets/vendor/ (`php bin/console importmap:install`): no CDN at runtime.
 */

// Styles: vendor first, then the application ones (order matters for overrides).
import 'bootstrap/dist/css/bootstrap.min.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'sweetalert2/dist/sweetalert2.min.css';
import './styles/app.css';

import $ from 'jquery';
import * as bootstrap from 'bootstrap';
import DataTable from 'datatables.net-bs5';
import dataTablesItalian from 'datatables.net-plugins/i18n/it-IT.mjs';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';
import 'altcha';
import './legacy/csrf-fetch.js';

// DataTables 3 does not import jQuery: register it (creates $.fn.DataTable) and the Bootstrap 5 integration.
DataTable.use($);
DataTable.use(bootstrap);
// Italian UI for every table (was downloaded from cdn.datatables.net by each page).
$.extend(true, DataTable.defaults, { language: dataTablesItalian });

// TRANSITIONAL (frontend review, phase F1): the page scripts still live inline in the Twig templates and
// use these globals. They go away when each page becomes a Stimulus controller (phase F2).
Object.assign(window, { $, jQuery: $, bootstrap, DataTable, Swal });
