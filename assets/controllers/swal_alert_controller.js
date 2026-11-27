import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';

/*
 * Shows a SweetAlert2 dialog as soon as the element appears:
 *   <div {{ stimulus_controller('swal-alert', {title: '…', text: '…', icon: 'warning'}) }}></div>
 */
export default class extends Controller {
    static values = { title: String, text: String, icon: { type: String, default: 'info' } };

    connect() {
        Swal.fire({
            title: this.titleValue,
            text: this.textValue,
            icon: this.iconValue,
            confirmButtonText: 'OK',
            confirmButtonColor: '#6c757d',
        });
    }
}
