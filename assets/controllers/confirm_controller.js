import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';

/*
 * Asks for confirmation before submitting a form:
 *   <form {{ stimulus_controller('confirm', {message: '…'}) }} data-action="submit->confirm#ask">
 */
export default class extends Controller {
    static values = { message: String };

    async ask(event) {
        if (this.confirmed) {
            return;
        }
        event.preventDefault();
        const result = await Swal.fire({
            title: 'Conferma',
            text: this.messageValue,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sì, procedi',
            cancelButtonText: 'Annulla',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
        });
        if (result.isConfirmed) {
            this.confirmed = true;
            // requestSubmit() (not submit()) so the submit listeners, e.g. CSRF double-submit, still run.
            this.element.requestSubmit();
        }
    }
}
