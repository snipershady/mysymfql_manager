(() => {
    'use strict';

    const serverSelect = document.getElementById('server-select');
    const statDbCount   = document.getElementById('stat-db-count');
    const statConns     = document.getElementById('stat-connections');
    const statRunning   = document.getElementById('stat-running');
    const statBlocked   = document.getElementById('stat-blocked');
    const tableBody     = document.getElementById('table-databases-body');

    if (!serverSelect) return;

    const SYSTEM_DBS = new Set(['information_schema', 'mysql', 'performance_schema', 'sys']);

    function formatBytes(bytes) {
        const b = Number(bytes);
        if (!b || b === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(b) / Math.log(k));
        return `${parseFloat((b / k ** i).toFixed(1))} ${sizes[i]}`;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function setLoading() {
        [statDbCount, statConns, statRunning, statBlocked].forEach(el => {
            el.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
        });
        tableBody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Caricamento dati…
                </td>
            </tr>`;
    }

    function setError() {
        [statDbCount, statConns, statRunning, statBlocked].forEach(el => {
            el.textContent = '—';
        });
        tableBody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center text-danger py-4">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Errore nel caricamento dei dati
                </td>
            </tr>`;
    }

    function renderTable(databases, name) {
        if (!databases || databases.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">
                        <i class="fa-solid fa-circle-info me-2"></i>Nessun database trovato
                    </td>
                </tr>`;
            return;
        }

        const schemaUrl = `/schema/databases?name=${encodeURIComponent(name)}`;

        tableBody.innerHTML = databases
            .filter(db => !SYSTEM_DBS.has(db.db_name))
            .map(db => `
                <tr>
                    <td class="ps-3">
                        <i class="fa-solid fa-database me-2 text-primary opacity-75"></i>
                        ${escapeHtml(db.db_name)}
                    </td>
                    <td class="text-muted">${formatBytes(db.size_bytes)}</td>
                    <td>${db.table_count}</td>
                    <td>
                        <a href="${schemaUrl}"
                           class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Azioni
                        </a>
                    </td>
                </tr>`)
            .join('');
    }

    async function loadStats(name) {
        setLoading();
        try {
            const res = await fetch(`/schema/dashboard-stats?name=${encodeURIComponent(name)}`);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();

            statDbCount.textContent  = data.db_count ?? '—';
            statConns.textContent    = data.active_connections ?? '—';
            statRunning.textContent  = data.running_processes ?? '—';
            statBlocked.textContent  = data.blocked_processes ?? '—';

            renderTable(data.databases ?? [], name);
        } catch {
            setError();
        }
    }

    serverSelect.addEventListener('change', () => {
        const name = serverSelect.value;
        if (name) loadStats(name);
    });

    // Auto-load on page load with the pre-selected server
    if (serverSelect.value) loadStats(serverSelect.value);
})();
