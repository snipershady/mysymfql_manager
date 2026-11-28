/*
 * Shared pieces of the live cockpits (server and schema): formatting, HTML of the recurring blocks, refresh loop.
 * Every value coming from MySQL goes through escapeHtml(); widths are set through element.style (CSP: no inline
 * style attributes).
 */
import { escapeHtml } from './ui.js';

// `digits` may receive anything when a helper is used directly as a table formatter (value, row): only a number counts.
const nf = (digits = 0) => new Intl.NumberFormat('it-IT', { maximumFractionDigits: Number.isInteger(digits) ? Math.min(Math.max(digits, 0), 6) : 0, minimumFractionDigits: 0 });

/** 1284 → "1.284", 12900 → "12,9 Mln"… compact form for big counters. */
export function compact(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '–';
    }

    return new Intl.NumberFormat('it-IT', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value));
}

export function number(value, digits = 0) {
    return value === null || value === undefined ? '–' : nf(digits).format(Number(value));
}

export function percent(ratio, digits = 1) {
    return ratio === null || ratio === undefined ? '–' : `${nf(Number.isInteger(digits) ? digits : 1).format(Number(ratio) * 100)}%`;
}

export function bytes(value) {
    if (value === null || value === undefined) {
        return '–';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = Number(value);
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${nf(unit === 0 ? 0 : 1).format(size)} ${units[unit]}`;
}

export function duration(seconds) {
    const s = Number(seconds ?? 0);
    const days = Math.floor(s / 86400);
    const hours = Math.floor((s % 86400) / 3600);
    const minutes = Math.floor((s % 3600) / 60);

    return days > 0 ? `${days} g ${hours} h` : hours > 0 ? `${hours} h ${minutes} min` : `${minutes} min`;
}

/** Badge of a finding or of the overall state: colour + label, never colour alone. */
export function severityBadge(severity) {
    return `<span class="badge text-bg-${escapeHtml(severity.color)}">${escapeHtml(severity.label)}</span>`;
}

export function findingsHtml(findings) {
    if (!findings.length) {
        return '<p class="text-success small mb-0"><i class="fa-solid fa-circle-check me-1"></i>Nessun problema rilevato.</p>';
    }

    return `<ul class="list-unstyled small mb-0">${findings.map((f) => `
        <li class="mb-1">${severityBadge(f)} <span class="ms-1">${escapeHtml(f.message)}</span></li>`).join('')}</ul>`;
}

/** Stat tile: label, value, optional secondary line (rate, context). */
export function tile(label, value, note = '') {
    return `<div class="cockpit-tile">
        <div class="cockpit-tile-label">${escapeHtml(label)}</div>
        <div class="cockpit-tile-value${String(value).length > 12 ? ' cockpit-tile-value-text' : ''}">${escapeHtml(value)}</div>
        ${note ? `<div class="cockpit-tile-note">${escapeHtml(note)}</div>` : ''}
    </div>`;
}

/**
 * Meter row: name, bar against a limit, figure. `level` (ok/warning/danger) picks the fill; the track is the light
 * step of the same colour.
 */
export function meter(label, ratio, figure, level = 'ok') {
    const color = { ok: 'primary', warning: 'warning', danger: 'danger' }[level] ?? 'primary';
    const width = ratio === null || ratio === undefined ? 0 : Math.max(0, Math.min(100, Number(ratio) * 100));

    return `<div class="cockpit-meter">
        <div class="cockpit-meter-label">${escapeHtml(label)}</div>
        <div class="progress bg-${color}-subtle cockpit-meter-bar" role="meter" aria-label="${escapeHtml(label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${width.toFixed(0)}">
            <div class="progress-bar bg-${color}" data-width="${width.toFixed(2)}"></div>
        </div>
        <div class="cockpit-meter-figure">${escapeHtml(figure)}</div>
    </div>`;
}

/** Applies the widths of the meters just rendered in `root`. */
export function applyWidths(root) {
    root.querySelectorAll('[data-width]').forEach((el) => { el.style.width = `${el.dataset.width}%`; });
}

/** A section the server account cannot read: MySQL's error and the GRANT that would enable it. */
export function unavailableHtml(section) {
    return `<div class="alert alert-light border small mb-0">
        <i class="fa-solid fa-lock text-secondary me-1"></i>Non disponibile con i privilegi dell'account del server.
        <div class="text-muted mt-1">${escapeHtml(section.error ?? '')}</div>
        ${section.grant ? `<div class="mt-1">Per abilitarla: <code>${escapeHtml(section.grant)}</code></div>` : ''}
    </div>`;
}

/**
 * Table from rows. `columns`: [{ key, label, format?(value, row) → text, className? }]. Formatted values are
 * escaped; `html: true` columns return trusted HTML built from escaped parts.
 */
export function tableHtml(columns, rows, empty = 'Nessun dato.') {
    if (!rows.length) {
        return `<p class="text-muted small mb-0">${escapeHtml(empty)}</p>`;
    }
    const cell = (column, row) => {
        const value = column.format ? column.format(row[column.key], row) : row[column.key];

        return column.html ? value : escapeHtml(value ?? '–');
    };

    return `<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
        <thead class="table-light"><tr>${columns.map((c) => `<th class="${c.className ?? ''}">${escapeHtml(c.label)}</th>`).join('')}</tr></thead>
        <tbody>${rows.map((row) => `<tr>${columns.map((c) => `<td class="${c.className ?? ''}">${cell(c, row)}</td>`).join('')}</tr>`).join('')}</tbody>
    </table></div>`;
}

/** Preferred columns of the Percona userstat tables, in display order (others are shown only as a fallback). */
const PERCONA_COLUMNS = {
    USER_STATISTICS: ['USER', 'TOTAL_CONNECTIONS', 'CONCURRENT_CONNECTIONS', 'CONNECTED_TIME', 'BUSY_TIME', 'CPU_TIME', 'BYTES_RECEIVED', 'BYTES_SENT', 'ROWS_FETCHED', 'ROWS_READ', 'ROWS_UPDATED', 'SELECT_COMMANDS', 'UPDATE_COMMANDS', 'DENIED_CONNECTIONS', 'ACCESS_DENIED'],
    CLIENT_STATISTICS: ['CLIENT', 'TOTAL_CONNECTIONS', 'CONCURRENT_CONNECTIONS', 'CONNECTED_TIME', 'BUSY_TIME', 'CPU_TIME', 'BYTES_RECEIVED', 'BYTES_SENT', 'ROWS_FETCHED', 'ROWS_READ', 'ROWS_UPDATED', 'SELECT_COMMANDS', 'UPDATE_COMMANDS', 'DENIED_CONNECTIONS', 'ACCESS_DENIED'],
    TABLE_STATISTICS: ['TABLE_NAME', 'ROWS_READ', 'ROWS_CHANGED', 'ROWS_CHANGED_X_INDEXES'],
    INDEX_STATISTICS: ['TABLE_NAME', 'INDEX_NAME', 'ROWS_READ'],
};

const PERCONA_TITLES = {
    USER_STATISTICS: 'Per utente',
    CLIENT_STATISTICS: 'Per client (host)',
    TABLE_STATISTICS: 'Per tabella',
    INDEX_STATISTICS: 'Per indice',
};

/**
 * Percona Server userstat sections: a table per section with the known columns present (all columns when none of
 * them is), or why the section is not available. Empty string when the server is not Percona.
 */
export function perconaHtml(percona) {
    if (!percona?.detected) {
        return '';
    }

    return Object.entries(percona.sections).map(([table, section]) => {
        let body;
        if (!section.available) {
            body = unavailableHtml(section).replace("Non disponibile con i privilegi dell'account del server.", 'Non disponibile.');
        } else {
            const present = Object.keys(section.rows[0] ?? {});
            const preferred = (PERCONA_COLUMNS[table] ?? []).filter((c) => present.includes(c));
            const keys = preferred.length ? preferred : present;
            body = tableHtml(keys.map((key) => ({
                key,
                label: key.toLowerCase().replace(/_/g, ' '),
                className: /^(USER|CLIENT|TABLE_NAME|INDEX_NAME|TABLE_SCHEMA)$/.test(key) ? 'font-monospace' : 'text-end text-nowrap',
                format: (v) => (typeof v === 'number' || /^\d+(\.\d+)?$/.test(String(v ?? '')) ? (/BYTES/.test(key) ? bytes(v) : compact(v)) : v),
            })), section.rows, 'Nessuna statistica raccolta finora.');
        }

        return `<h6 class="small text-muted mt-2">${escapeHtml(PERCONA_TITLES[table] ?? table)} <span class="font-monospace">(information_schema.${escapeHtml(table)})</span></h6>${body}`;
    }).join('');
}

/**
 * Refresh loop: calls `load` now and every `seconds`, skipping while the tab is hidden (no queries on the server
 * for a page nobody looks at). Returns a stop function for disconnect().
 */
export function refreshLoop(load, seconds) {
    let timer = null;
    const tick = () => {
        if (document.visibilityState === 'visible') {
            load();
        }
    };
    const onVisibility = () => { if (document.visibilityState === 'visible') load(); };
    load();
    timer = setInterval(tick, seconds * 1000);
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisibility);
    };
}
