/*
 * Application entrypoint (AssetMapper, see importmap.php). Every library is served from
 * assets/vendor/ (`php bin/console importmap:install`): no CDN at runtime.
 * Page behaviour lives in Stimulus controllers (assets/controllers/), started by stimulus_bootstrap.js.
 */

// Styles: vendor first, then the application ones (order matters for overrides).
import 'bootstrap/dist/css/bootstrap.min.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'sweetalert2/dist/sweetalert2.min.css';
import './styles/app.css';

// Bootstrap's data-api (data-bs-toggle="modal", dropdowns, dismiss buttons…).
import 'bootstrap';
import './lib/csrf-fetch.js';
import './stimulus_bootstrap.js';

// Turbo Drive runs this module once: load the widget on every visit (turbo:load also fires on the first one).
document.addEventListener('turbo:load', async () => {
    if (document.querySelector('altcha-widget')) {
        await import('altcha');
        await import('altcha/i18n/it');
    }
});
