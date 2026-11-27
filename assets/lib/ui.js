/*
 * Small UI helpers shared by the Stimulus controllers.
 */
import { Toast } from 'bootstrap';
import Swal from 'sweetalert2/dist/sweetalert2.esm.js';

/** Escapes a value for safe interpolation into HTML. */
export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Bootstrap toast in the bottom-right corner (#toast-container, created when missing).
 * `message` is HTML: escape any untrusted part with escapeHtml().
 */
export function showToast(message, success) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'position-fixed bottom-0 end-0 p-3 toast-container-front';
        document.body.appendChild(container);
    }
    const bg = success ? 'text-bg-success' : 'text-bg-danger';
    const icon = success ? 'fa-circle-check' : 'fa-circle-xmark';
    const el = document.createElement('div');
    el.className = `toast align-items-center ${bg} border-0`;
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-atomic', 'true');
    el.innerHTML = `
        <div class="d-flex">
            <div class="toast-body"><i class="fa-solid ${icon} me-2"></i>${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>`;
    container.appendChild(el);
    new Toast(el, { delay: 4000 }).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

/** Red "are you sure" dialog; resolves to true when confirmed. */
export async function confirmDanger({ title, text, html, confirmButtonText = 'Conferma' }) {
    const result = await Swal.fire({
        title,
        text,
        html,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText: 'Annulla',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
    });

    return result.isConfirmed;
}
