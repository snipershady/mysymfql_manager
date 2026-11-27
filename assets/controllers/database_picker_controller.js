import { Controller } from '@hotwired/stimulus';

/*
 * Database ownership form: once a server is chosen, lists its databases in a select and copies
 * the choice into the (hidden) dbName field of the Symfony form.
 */
const SYSTEM_DBS = new Set(['information_schema', 'mysql', 'performance_schema', 'sys']);

export default class extends Controller {
    static targets = ['server', 'select', 'field', 'group'];
    static values = { url: String, currentServer: String, currentDb: String };

    connect() {
        if (this.currentServerValue) {
            this.load(this.currentServerValue);
        }
    }

    serverChanged() {
        const option = this.serverTarget.options[this.serverTarget.selectedIndex];
        const name = option?.dataset.name ?? '';
        if (!name) {
            this.groupTarget.hidden = true;
            this.placeholder('— Seleziona prima un server —');
            this.sync();

            return;
        }
        this.groupTarget.hidden = false;
        this.load(name);
    }

    sync() {
        this.fieldTarget.value = this.selectTarget.value;
    }

    placeholder(text) {
        this.selectTarget.replaceChildren(new Option(text, ''));
    }

    async load(serverName) {
        this.placeholder('Caricamento…');
        this.selectTarget.disabled = true;
        this.sync();
        try {
            const json = await (await fetch(`${this.urlValue}?name=${encodeURIComponent(serverName)}`)).json();
            const names = (json.data ?? []).map((d) => d.db_name).filter((n) => !SYSTEM_DBS.has(n));
            if (names.length === 0) {
                this.placeholder('Nessun database trovato');
            } else {
                this.selectTarget.replaceChildren(
                    new Option('— Seleziona un database —', ''),
                    ...names.map((n) => new Option(n, n, false, n === this.currentDbValue)),
                );
            }
        } catch {
            this.placeholder('Errore nel caricamento');
        } finally {
            this.selectTarget.disabled = false;
            this.sync();
        }
    }
}
