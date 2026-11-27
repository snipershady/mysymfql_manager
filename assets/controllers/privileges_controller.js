import { Controller } from '@hotwired/stimulus';

/*
 * Privilege checkboxes: "ALL PRIVILEGES" disables and clears the single ones.
 * Read the GRANT clause with privilegesValue() from assets/lib/account-form.js.
 */
export default class extends Controller {
    static targets = ['all', 'single'];

    allChanged() {
        this.singleTargets.forEach((cb) => {
            cb.disabled = this.allTarget.checked;
            cb.checked = false;
        });
    }
}
