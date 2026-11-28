import CockpitController from '../lib/cockpit-controller.js';
import { escapeHtml } from '../lib/ui.js';
import {
    applyWidths, bytes, perconaHtml, compact, duration, findingsHtml, meter, number, percent, tableHtml, tile, unavailableHtml,
} from '../lib/cockpit.js';

/*
 * Server cockpit (admins): health findings, headline numbers, capacity meters, traffic rates, heaviest statements,
 * databases, replication and configuration. Rates per second are computed here between two refreshes (the server
 * returns raw counters); on the first load they show the average since the server started.
 */
const RATES = [
    ['Questions', 'Statement', number],
    ['Com_select', 'SELECT', number],
    ['Com_insert', 'INSERT', number],
    ['Com_update', 'UPDATE', number],
    ['Com_delete', 'DELETE', number],
    ['Innodb_rows_read', 'Righe lette', number],
    ['Innodb_rows_inserted', 'Righe inserite', number],
    ['Innodb_rows_updated', 'Righe aggiornate', number],
    ['Innodb_rows_deleted', 'Righe eliminate', number],
    ['Bytes_received', 'Byte ricevuti', bytes],
    ['Bytes_sent', 'Byte inviati', bytes],
    ['Connections', 'Nuove connessioni', number],
    ['Slow_queries', 'Query lente', number],
    ['Created_tmp_disk_tables', 'Tabelle temporanee su disco', number],
];

/** Configuration variables in bytes: shown also in a readable unit. */
const SIZE_VARIABLES = ['innodb_buffer_pool_size', 'innodb_redo_log_capacity', 'tmp_table_size', 'max_heap_table_size', 'max_allowed_packet'];

const level = (value, warning, danger, higherIsWorse = true) => {
    if (value === null || value === undefined) {
        return 'ok';
    }
    if (higherIsWorse) {
        return value >= danger ? 'danger' : value >= warning ? 'warning' : 'ok';
    }

    return value <= danger ? 'danger' : value <= warning ? 'warning' : 'ok';
};

export default class extends CockpitController {
    static targets = ['percona', 'perconaCard', 'perconaVersion', 'findings', 'tiles', 'meters', 'rates', 'topQueries', 'databases', 'replica', 'config'];
    static values = { name: String, schemaUrl: String };

    connect() {
        this.previous = null;
        super.connect();
    }

    render(data) {
        this.perconaCardTarget.hidden = !data.percona?.detected;
        if (data.percona?.detected) {
            this.perconaVersionTarget.textContent = `${data.percona.version} · userstat ${data.percona.userstat ?? '?'}`;
            this.perconaTarget.innerHTML = perconaHtml(data.percona);
        }
        const g = data.gauges;
        const rates = this.rates(data);
        this.previous = { counters: data.counters, uptime: g.uptime, at: Date.parse(data.generated_at) };

        this.findingsTarget.innerHTML = findingsHtml(data.findings);

        this.tilesTarget.innerHTML = [
            tile('Uptime', duration(g.uptime), data.config.version ? `MySQL ${data.config.version}` : ''),
            tile('Connessioni', number(g.threads_connected), `su ${number(g.max_connections)} (picco ${number(g.max_used_connections)})`),
            tile('Thread attivi', number(g.threads_running)),
            tile('Processi in esecuzione', data.processes.running === null ? '–' : number(data.processes.running), data.processes.running === null ? 'serve PROCESS' : ''),
            tile('Transazioni in attesa di lock', data.processes.blocked === null ? '–' : number(data.processes.blocked)),
            tile('Statement/s', number(rates.values.Questions, 1), rates.live ? 'ora' : 'media dall\'avvio'),
            tile('Query lente', compact(g.slow_queries), 'dall\'avvio'),
            tile('Buffer pool', bytes(g.buffer_pool_bytes), `${percent(g.buffer_pool_usage, 0)} occupato`),
        ].join('');

        this.metersTarget.innerHTML = [
            meter('Connessioni / max_connections', g.connections_usage, percent(g.connections_usage), level(g.connections_usage, 0.8, 0.9)),
            meter('Picco connessioni / max_connections', g.max_used_usage, percent(g.max_used_usage), level(g.max_used_usage, 0.8, 0.95)),
            meter('Hit ratio buffer pool', g.buffer_pool_hit_ratio, percent(g.buffer_pool_hit_ratio, 2), level(g.buffer_pool_hit_ratio, 0.99, 0.95, false)),
            meter('Buffer pool occupato', g.buffer_pool_usage, percent(g.buffer_pool_usage)),
            meter('Pagine dirty nel buffer pool', g.buffer_pool_dirty, percent(g.buffer_pool_dirty), level(g.buffer_pool_dirty, 0.5, 0.75)),
            meter('Tabelle temporanee su disco', g.tmp_disk_ratio, percent(g.tmp_disk_ratio), level(g.tmp_disk_ratio, 0.25, 0.5)),
            meter('Hit ratio table open cache', g.table_cache_hit_ratio, percent(g.table_cache_hit_ratio), level(g.table_cache_hit_ratio, 0.9, 0.8, false)),
        ].join('');
        applyWidths(this.metersTarget);

        this.ratesTarget.innerHTML = tableHtml(
            [{ key: 'label', label: 'Contatore' }, { key: 'value', label: rates.live ? 'Al secondo' : 'Al secondo (media)', className: 'text-end text-nowrap' }],
            RATES.map(([key, label, format]) => ({ label, value: format(rates.values[key], 1) + (format === bytes ? '/s' : '') })),
        );

        this.topQueriesTarget.innerHTML = data.top_queries.available
            ? tableHtml([
                { key: 'schema_name', label: 'Schema', className: 'font-monospace' },
                { key: 'digest_text', label: 'Statement', html: true, format: (v) => `<span class="font-monospace cockpit-sql" title="${escapeHtml(v)}">${escapeHtml(v)}</span>` },
                { key: 'executions', label: 'Esecuzioni', className: 'text-end text-nowrap', format: compact },
                { key: 'total_seconds', label: 'Tempo totale', className: 'text-end text-nowrap', format: (v) => `${number(v, 1)} s` },
                { key: 'avg_seconds', label: 'Medio', className: 'text-end text-nowrap', format: (v) => `${number(v * 1000, 2)} ms` },
                { key: 'rows_examined', label: 'Righe esaminate', className: 'text-end text-nowrap', format: compact },
                { key: 'no_index_used', label: 'Senza indice', className: 'text-end text-nowrap', format: compact },
            ], data.top_queries.rows, 'Nessuno statement registrato.')
            : unavailableHtml(data.top_queries);

        this.databasesTarget.innerHTML = tableHtml([
            { key: 'db_name', label: 'Database', html: true, format: (v) => `<a href="${escapeHtml(`${this.schemaUrlValue}?name=${encodeURIComponent(this.nameValue)}&db_name=${encodeURIComponent(v)}`)}" class="font-monospace">${escapeHtml(v)}</a>` },
            { key: 'table_count', label: 'Tabelle', className: 'text-end text-nowrap', format: number },
            { key: 'size_bytes', label: 'Dimensione', className: 'text-end text-nowrap', format: bytes },
        ], [...data.databases].sort((a, b) => b.size_bytes - a.size_bytes), 'Nessun database.');

        this.replicaTarget.innerHTML = !data.replica.available
            ? unavailableHtml(data.replica)
            : data.replica.rows.length === 0
                ? '<p class="text-muted small mb-0">Il server non è una replica.</p>'
                : tableHtml([
                    { key: 'Channel_Name', label: 'Canale', format: (v) => v || '(default)' },
                    { key: 'Source_Host', label: 'Sorgente' },
                    { key: 'Replica_IO_Running', label: 'IO' },
                    { key: 'Replica_SQL_Running', label: 'SQL' },
                    { key: 'Seconds_Behind_Source', label: 'Ritardo', className: 'text-end text-nowrap', format: (v) => (v === null ? '–' : `${number(v)} s`) },
                ], data.replica.rows);

        this.configTarget.innerHTML = tableHtml(
            [
                { key: 'name', label: 'Variabile', className: 'font-monospace' },
                { key: 'value', label: 'Valore', className: 'font-monospace text-break', format: (v, row) => (SIZE_VARIABLES.includes(row.name) && /^\d+$/.test(v) ? `${v} (${bytes(v)})` : v) },
            ],
            Object.entries(data.config).map(([name, value]) => ({ name, value })),
        );
    }

    /**
     * Rates per second between this refresh and the previous one; the average since the start when there is no
     * previous sample or the server restarted in between (counters went back).
     */
    rates(data) {
        const now = Date.parse(data.generated_at);
        const previous = this.previous;
        const live = previous !== null && data.gauges.uptime >= previous.uptime && now > previous.at;
        const values = {};
        for (const [key] of RATES) {
            const current = data.counters[key] ?? 0;
            if (live) {
                const delta = current - (previous.counters[key] ?? 0);
                values[key] = delta >= 0 ? delta / ((now - previous.at) / 1000) : null;
            } else {
                values[key] = data.gauges.uptime > 0 ? current / data.gauges.uptime : null;
            }
        }

        return { live, values };
    }
}
