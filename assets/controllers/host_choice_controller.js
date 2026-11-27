import { Controller } from '@hotwired/stimulus';

/*
 * MySQL account host: radio options ("%", "localhost", "__custom__") plus a free-text field shown
 * only for "__custom__". Read the value with hostValue() from assets/lib/account-form.js.
 */
export default class extends Controller {
    static targets = ['option', 'custom'];

    changed() {
        this.customTarget.hidden = '__custom__' !== this.optionTargets.find((o) => o.checked)?.value;
    }
}
