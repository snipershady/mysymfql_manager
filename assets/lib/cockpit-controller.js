import { Controller } from '@hotwired/stimulus';
import { escapeHtml } from './ui.js';
import { refreshLoop, severityBadge } from './cockpit.js';

/*
 * Base of the cockpit controllers: loads `dataUrl` (JSON), refreshes it every N seconds while the tab is visible,
 * shows the overall state and the time of the last refresh, and passes the data to render(). Subclasses implement
 * render(json). Not registered as a controller itself (it lives in lib/).
 */
export default class extends Controller {
    static targets = ['severity', 'updated', 'auto', 'interval', 'error'];
    static values = { dataUrl: String };

    connect() {
        this.loading = false;
        this.start();
    }

    disconnect() {
        this.stop?.();
        this.abort?.abort();
    }

    start() {
        this.stop?.();
        this.stop = null;
        if (this.autoTarget.checked) {
            this.stop = refreshLoop(() => this.load(), Number(this.intervalTarget.value));
        } else {
            this.load();
        }
    }

    toggle() {
        this.start();
    }

    refresh() {
        this.load();
    }

    async load() {
        if (this.loading) {
            return;
        }
        this.loading = true;
        this.abort = new AbortController();
        try {
            const response = await fetch(this.dataUrlValue, { signal: this.abort.signal });
            const json = await response.json();
            if (!response.ok || !json.is_valid) {
                throw new Error(json.message ?? `HTTP ${response.status}`);
            }
            this.errorTarget.classList.add('d-none');
            this.severityTarget.innerHTML = severityBadge(json.severity);
            this.updatedTarget.textContent = `Aggiornato alle ${new Date(json.generated_at).toLocaleTimeString('it-IT')}`;
            this.render(json);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.errorTarget.innerHTML = `<i class="fa-solid fa-circle-xmark me-1"></i>Impossibile leggere i dati: ${escapeHtml(error.message)}`;
                this.errorTarget.classList.remove('d-none');
            }
        } finally {
            this.loading = false;
        }
    }

    render() {}
}
