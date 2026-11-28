import CockpitController from '../lib/cockpit-controller.js';
import { escapeHtml } from '../lib/ui.js';
import {
    applyWidths, bytes, perconaHtml, compact, findingsHtml, meter, number, percent, tableHtml, tile, unavailableHtml,
} from '../lib/cockpit.js';

/*
 * Schema cockpit (admin or owner of the database): health findings, sizes, biggest tables, AUTO_INCREMENT
 * capacity, redundant and unused indexes, table I/O, heaviest statements and sessions of this schema only.
 */
const sql = (value) => `<span class="font-monospace cockpit-sql" title="${escapeHtml(value)}">${escapeHtml(value)}</span>`;

export default class extends CockpitController {
    static targets = ['percona', 'perconaCard', 'perconaVersion', 'findings', 'tiles', 'tables', 'autoIncrement', 'indexes', 'tableIo', 'topQueries', 'sessions'];

    render(data) {
        this.perconaCardTarget.hidden = !data.percona?.detected;
        if (data.percona?.detected) {
            this.perconaVersionTarget.textContent = `${data.percona.version} · userstat ${data.percona.userstat ?? '?'}`;
            this.perconaTarget.innerHTML = perconaHtml(data.percona);
        }
        const s = data.summary;
        this.findingsTarget.innerHTML = findingsHtml(data.findings);

        const engines = Object.entries(s.engines).map(([engine, count]) => `${engine || '?'} ${count}`).join(', ');
        this.tilesTarget.innerHTML = [
            tile('Tabelle', number(s.table_count), engines),
            tile('Righe (stima)', compact(s.rows)),
            tile('Dati', bytes(s.data_bytes)),
            tile('Indici', bytes(s.index_bytes)),
            tile('Spazio libero', bytes(s.free_bytes), 'recuperabile con OPTIMIZE'),
            tile('Senza primary key', number(data.without_primary_key.length)),
            tile('Sessioni', data.sessions.available ? number(data.sessions.rows.length) : '–'),
            tile('Collation', s.collation || '–', s.charset),
        ].join('');

        this.tablesTarget.innerHTML = tableHtml([
            { key: 'table_name', label: 'Tabella', className: 'font-monospace' },
            { key: 'engine', label: 'Engine' },
            { key: 'table_rows', label: 'Righe', className: 'text-end text-nowrap', format: compact },
            { key: 'data_bytes', label: 'Dati', className: 'text-end text-nowrap', format: bytes },
            { key: 'index_bytes', label: 'Indici', className: 'text-end text-nowrap', format: bytes },
            { key: 'free_bytes', label: 'Libero', className: 'text-end text-nowrap', format: bytes },
            { key: 'table_name', label: 'PK', className: 'text-center', html: true, format: (v) => (data.without_primary_key.includes(v) ? '<span class="badge text-bg-warning">no</span>' : '<i class="fa-solid fa-check text-success" title="presente"></i>') },
        ], data.tables.slice(0, 15), 'Nessuna tabella.');

        const fullest = data.auto_increment.slice(0, 8);
        this.autoIncrementTarget.innerHTML = fullest.length === 0
            ? '<p class="text-muted small mb-0">Nessuna colonna AUTO_INCREMENT.</p>'
            : fullest.map((c) => meter(
                `${c.table_name}.${c.column_name} (${c.column_type})`,
                c.usage,
                `${percent(c.usage, 2)} · prossimo ${compact(c.next_value)}`,
                c.usage >= 0.9 ? 'danger' : c.usage >= 0.75 ? 'warning' : 'ok',
            )).join('');
        applyWidths(this.autoIncrementTarget);

        const redundant = data.redundant_indexes;
        const unused = data.unused_indexes;
        this.indexesTarget.innerHTML = `
            <h6 class="small text-muted">Ridondanti</h6>
            ${redundant.available ? tableHtml([
                { key: 'table_name', label: 'Tabella', className: 'font-monospace' },
                { key: 'index_name', label: 'Indice', className: 'font-monospace' },
                { key: 'dominant_index', label: 'Coperto da', className: 'font-monospace' },
                { key: 'sql_drop_index', label: 'Per rimuoverlo', html: true, format: sql },
            ], redundant.rows, 'Nessun indice ridondante.') : unavailableHtml(redundant)}
            <h6 class="small text-muted mt-3">Mai usati dall'avvio del server</h6>
            ${unused.available ? tableHtml([
                { key: 'table_name', label: 'Tabella', className: 'font-monospace' },
                { key: 'index_name', label: 'Indice', className: 'font-monospace' },
            ], unused.rows, 'Nessun indice inutilizzato.') : unavailableHtml(unused)}`;

        this.tableIoTarget.innerHTML = data.table_io.available
            ? tableHtml([
                { key: 'table_name', label: 'Tabella', className: 'font-monospace' },
                { key: 'read_count', label: 'Letture', className: 'text-end text-nowrap', format: compact },
                { key: 'write_count', label: 'Scritture', className: 'text-end text-nowrap', format: compact },
                { key: 'read_seconds', label: 'Tempo lettura', className: 'text-end text-nowrap', format: (v) => `${number(v, 2)} s` },
                { key: 'write_seconds', label: 'Tempo scrittura', className: 'text-end text-nowrap', format: (v) => `${number(v, 2)} s` },
            ], data.table_io.rows, 'Nessun I/O registrato.')
            : unavailableHtml(data.table_io);

        this.topQueriesTarget.innerHTML = data.top_queries.available
            ? tableHtml([
                { key: 'digest_text', label: 'Statement', html: true, format: sql },
                { key: 'executions', label: 'Esecuzioni', className: 'text-end text-nowrap', format: compact },
                { key: 'total_seconds', label: 'Tempo totale', className: 'text-end text-nowrap', format: (v) => `${number(v, 1)} s` },
                { key: 'avg_seconds', label: 'Medio', className: 'text-end text-nowrap', format: (v) => `${number(v * 1000, 2)} ms` },
                { key: 'rows_examined', label: 'Righe esaminate', className: 'text-end text-nowrap', format: compact },
                { key: 'no_index_used', label: 'Senza indice', className: 'text-end text-nowrap', format: compact },
            ], data.top_queries.rows, 'Nessuno statement registrato su questo schema.')
            : unavailableHtml(data.top_queries);

        this.sessionsTarget.innerHTML = data.sessions.available
            ? tableHtml([
                { key: 'id', label: 'PID', className: 'text-end text-nowrap' },
                { key: 'user', label: 'Utente', className: 'font-monospace' },
                { key: 'host', label: 'Host', className: 'font-monospace' },
                { key: 'command', label: 'Comando' },
                { key: 'seconds', label: 'Da', className: 'text-end text-nowrap', format: (v) => `${number(v)} s` },
                { key: 'state', label: 'Stato' },
                { key: 'info', label: 'Statement', html: true, format: (v) => (v ? sql(v) : '–') },
            ], data.sessions.rows, 'Nessuna sessione su questo schema.')
            : unavailableHtml(data.sessions);
    }
}
