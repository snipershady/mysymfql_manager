/*
 * Content-Security-Policy + Turbo Drive.
 *
 * The document keeps the policy (and so the nonce) of its first full page load, but every Turbo visit
 * replaces <meta name="csp-nonce"> with the one of the new response, whose nonce that policy does not
 * allow. Libraries reading the meta for the <style> they inject (Altcha, Turbo, CodeMirror) would then be
 * blocked: restore the original value after every Turbo render. Imported first by assets/app.js.
 */
const meta = () => document.querySelector('meta[name="csp-nonce"]');
const originalNonce = meta()?.content;

if (originalNonce) {
    document.addEventListener('turbo:render', () => {
        const current = meta();
        if (current) {
            current.content = originalNonce;
        }
    });
}
