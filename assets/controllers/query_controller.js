import { Controller } from '@hotwired/stimulus';
import { createSqlEditor } from '../sql-editor.js';
import { escapeHtml, showToast } from '../lib/ui.js';

/*
 * SQL console of a database: CodeMirror 6 editor with schema-aware completion, table list with
 * query skeletons, execution (Ctrl/Cmd+Enter or buttons), EXPLAIN / EXPLAIN ANALYZE.
 */
const cell = (value) => (value === null || value === undefined ? '<span class="text-muted">NULL</span>' : escapeHtml(value));

const SKELETONS = {
    select: (table, cols) => `SELECT ${cols.length ? cols.join(', ') : '*'}\nFROM ${table};`,
    insert: (table, cols) => `INSERT INTO ${table} (${cols.join(', ')})\nVALUES (${cols.map(() => "''").join(', ')});`,
    update: (table, cols) => `UPDATE ${table}\nSET ${cols.map((col) => `${col} = ''`).join(',\n    ')}\nWHERE ;`,
    delete: (table) => `DELETE FROM ${table}\nWHERE ;`,
};

export default class extends Controller {
    static targets = ['editor', 'tables', 'alert', 'card', 'tableWrap', 'pre', 'resultTable', 'count', 'button'];
    static values = { name: String, db: String, columnsUrl: String, executeUrl: String };

    connect() {
        /** table name => column names (list on the left + autocompletion) */
        this.schema = {};
        this.editor = createSqlEditor(this.editorTarget, { onRun: () => this.execute() });
        this.loadSchema();
    }

    disconnect() {
        this.editor?.view.destroy();
    }

    async loadSchema() {
        try {
            const json = await (await fetch(this.columnsUrlValue)).json();
            this.schema = json.tables ?? {};
            this.editor.setSchema(this.schema);
            this.renderTables();
        } catch {
            // Autocompletion degrades to keywords only; editing still works.
            this.tablesTarget.innerHTML = '<div class="list-group-item text-danger small"><i class="fa-solid fa-circle-xmark me-1"></i>Impossibile caricare le tabelle.</div>';
        }
    }

    renderTables() {
        const names = Object.keys(this.schema).sort((a, b) => a.localeCompare(b));
        if (names.length === 0) {
            this.tablesTarget.innerHTML = '<div class="list-group-item text-muted small">Nessuna tabella trovata.</div>';

            return;
        }
        const action = (kind, icon, title, table) => `
            <button type="button" class="btn btn-outline-secondary" data-action="query#generate" data-kind="${kind}" data-table="${escapeHtml(table)}" title="${title}">
                <i class="fa-solid ${icon}"></i>
            </button>`;
        this.tablesTarget.innerHTML = names.map((table) => `
            <div class="list-group-item d-flex align-items-center justify-content-between gap-1 py-1 px-2">
                <button type="button" class="btn btn-link btn-sm p-0 text-start text-truncate" data-action="query#insertTable" data-table="${escapeHtml(table)}" title="Inserisci nome tabella">
                    <i class="fa-solid fa-table text-secondary me-1"></i>${escapeHtml(table)}
                </button>
                <div class="btn-group btn-group-sm flex-shrink-0" role="group">
                    ${action('select', 'fa-magnifying-glass', 'Genera SELECT', table)}
                    ${action('insert', 'fa-plus', 'Genera INSERT', table)}
                    ${action('update', 'fa-pen', 'Genera UPDATE', table)}
                    ${action('delete', 'fa-trash', 'Genera DELETE', table)}
                </div>
            </div>`).join('');
    }

    insertTable(event) {
        this.editor.replaceSelection(`\`${event.currentTarget.dataset.table}\``);
        this.editor.focus();
    }

    /** Writes a ready-to-edit statement for the whole column list, replacing the editor content. */
    generate(event) {
        const { kind, table } = event.currentTarget.dataset;
        const quote = (identifier) => `\`${identifier}\``;
        this.editor.setValue(SKELETONS[kind](quote(table), (this.schema[table] ?? []).map(quote)));
        this.editor.focus();
    }

    execute() { this.run(''); }

    explain() { this.run('EXPLAIN'); }

    explainAnalyze() { this.run('EXPLAIN ANALYZE'); }

    async run(prefix) {
        const rawSql = this.editor.getValue().trim();
        if (!rawSql) {
            showToast('Inserisci una query da eseguire.', false);

            return;
        }
        const clicked = this.buttonTargets.find((b) => b.dataset.prefix === prefix) ?? this.buttonTargets[0];
        const original = clicked.innerHTML;
        this.buttonTargets.forEach((b) => { b.disabled = true; });
        clicked.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Eseguo…';
        this.alertTarget.classList.add('d-none');
        this.cardTarget.classList.add('d-none');
        try {
            const body = new FormData();
            body.append('name', this.nameValue);
            body.append('db_name', this.dbValue);
            body.append('sql', prefix ? `${prefix} ${rawSql}` : rawSql);
            const json = await (await fetch(this.executeUrlValue, { method: 'POST', body })).json();
            if (!json.is_valid) {
                this.alert('danger', `<i class="fa-solid fa-circle-xmark me-2"></i>${escapeHtml(json.message)}`);
            } else if (!json.is_select) {
                this.alert('success', `<i class="fa-solid fa-circle-check me-2"></i>Query eseguita con successo. Righe interessate: ${json.affected_rows}.`);
            } else {
                if (json.rows.length === 0) {
                    this.alert('info', '<i class="fa-solid fa-circle-info me-2"></i>La query non ha restituito risultati.');
                }
                if (prefix && this.isTreeShaped(json.columns, json.rows)) {
                    this.renderText(json.columns, json.rows);
                } else {
                    this.renderTable(json.columns, json.rows);
                }
            }
        } catch {
            this.alert('danger', '<i class="fa-solid fa-circle-xmark me-2"></i>Errore di rete durante l\'esecuzione della query.');
        } finally {
            this.buttonTargets.forEach((b) => { b.disabled = false; });
            clicked.innerHTML = original;
        }
    }

    alert(type, html) {
        this.alertTarget.className = `alert alert-${type} mt-3`;
        this.alertTarget.innerHTML = html;
    }

    renderTable(columns, rows) {
        this.preTarget.classList.add('d-none');
        this.tableWrapTarget.classList.remove('d-none');
        const theadRow = this.resultTableTarget.querySelector('thead tr');
        const tbody = this.resultTableTarget.querySelector('tbody');
        if (columns.length === 0) {
            theadRow.innerHTML = '';
            tbody.innerHTML = '';

            return;
        }
        theadRow.replaceChildren(...columns.map((col) => Object.assign(document.createElement('th'), { textContent: col })));
        tbody.innerHTML = rows.map((row) => `<tr>${columns.map((col) => `<td>${cell(row[col])}</td>`).join('')}</tr>`).join('');
        this.countTarget.textContent = `${rows.length} righe`;
        this.cardTarget.classList.remove('d-none');
    }

    // EXPLAIN ANALYZE (and EXPLAIN FORMAT=TREE/JSON) return one text column holding an indented
    // tree: show it as preformatted text so the structure stays readable.
    isTreeShaped(columns, rows) {
        return columns.length === 1 && rows.some((row) => typeof row[columns[0]] === 'string' && row[columns[0]].includes('\n'));
    }

    renderText(columns, rows) {
        this.tableWrapTarget.classList.add('d-none');
        this.preTarget.textContent = rows.map((row) => row[columns[0]]).join('\n\n');
        this.preTarget.classList.remove('d-none');
        this.countTarget.textContent = `${rows.length} righe`;
        this.cardTarget.classList.remove('d-none');
    }
}
