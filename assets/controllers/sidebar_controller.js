import { Controller } from '@hotwired/stimulus';

/*
 * Sidebar toggle (admin layout): collapses it on desktop (remembered in localStorage),
 * opens/closes it as an overlay on small screens.
 */
const COLLAPSED_KEY = 'sidebarCollapsed';

export default class extends Controller {
    connect() {
        if ('1' === localStorage.getItem(COLLAPSED_KEY)) {
            document.body.classList.add('sidebar-collapsed');
        }
    }

    toggle() {
        if (window.innerWidth <= 768) {
            document.body.classList.toggle('sidebar-open');

            return;
        }
        document.body.classList.toggle('sidebar-collapsed');
        localStorage.setItem(COLLAPSED_KEY, document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
    }
}
