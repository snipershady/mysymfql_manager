/**
 * Adds the CSRF token (from <meta name="csrf-token">) as X-CSRF-Token header to every
 * same-origin state-changing request made with fetch() or jQuery.ajax().
 * Server side: App\EventListener\SchemaCsrfListener.
 *
 * Imported by assets/app.js before the Stimulus controllers start.
 */
import jQuery from 'jquery';

(function () {
    // Read on every request: with Turbo Drive the page (and its <meta>) changes without reloading this module.
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content;
    const HEADER = 'X-CSRF-Token';
    const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    const needsToken = (method, url) =>
        Boolean(token()) &&
        !SAFE.includes((method || 'GET').toUpperCase()) &&
        new URL(url, window.location.href).origin === window.location.origin;

    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init = {}) {
        const isRequest = input instanceof Request;
        const method = init.method || (isRequest ? input.method : 'GET');
        const url = isRequest ? input.url : String(input);
        if (needsToken(method, url)) {
            const headers = new Headers(init.headers || (isRequest ? input.headers : undefined));
            headers.set(HEADER, token());
            init = { ...init, headers };
        }
        return originalFetch(input, init);
    };

    // jQuery requests (none at the moment: pages use fetch()) get the header too.
    jQuery.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        if (needsToken(options.type, options.url)) {
            jqXHR.setRequestHeader(HEADER, token());
        }
    });
})();
