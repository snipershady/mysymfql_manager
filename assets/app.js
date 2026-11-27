/*
 * Application entrypoint (AssetMapper, see importmap.php). Every library is served from
 * assets/vendor/ (`php bin/console importmap:install`): no CDN at runtime.
 * Page behaviour lives in Stimulus controllers (assets/controllers/), started by stimulus_bootstrap.js.
 */

// Styles are linked in templates/base.html.twig (not imported here: see the comment there).

import './lib/csp-nonce.js';
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
