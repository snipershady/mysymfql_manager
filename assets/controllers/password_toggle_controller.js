import { Controller } from '@hotwired/stimulus';

/*
 * Show/hide button for a password field:
 *   <div {{ stimulus_controller('password-toggle') }}>
 *     <input type="password" {{ stimulus_target('password-toggle', 'input') }}>
 *     <button type="button" {{ stimulus_action('password-toggle', 'toggle') }}><i {{ stimulus_target('password-toggle', 'icon') }} class="fa-solid fa-eye"></i></button>
 */
export default class extends Controller {
    static targets = ['input', 'icon'];

    toggle() {
        const show = 'password' === this.inputTarget.type;
        this.inputTarget.type = show ? 'text' : 'password';
        this.iconTarget.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    }

    /** Back to a hidden, empty field (e.g. when its modal closes). */
    reset() {
        this.inputTarget.type = 'password';
        this.iconTarget.className = 'fa-solid fa-eye';
    }
}
