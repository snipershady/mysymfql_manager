(function () {
    const toggle = document.getElementById('sidebarToggle');
    const body = document.body;
    const COLLAPSED_KEY = 'sidebarCollapsed';

    if (localStorage.getItem(COLLAPSED_KEY) === '1') {
        body.classList.add('sidebar-collapsed');
    }

    toggle.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            body.classList.toggle('sidebar-open');
        } else {
            body.classList.toggle('sidebar-collapsed');
            localStorage.setItem(COLLAPSED_KEY, body.classList.contains('sidebar-collapsed') ? '1' : '0');
        }
    });
})();
