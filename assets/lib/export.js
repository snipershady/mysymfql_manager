/*
 * Export of a query result already shown in the page (the query is never run again: it could have side effects
 * or return different rows). Values are exported as MySQL returned them, without any rewriting.
 */

/**
 * CSV (RFC 4180): comma separator, CRLF line ends, fields quoted when they contain a comma, a quote or a line
 * break (quotes doubled), NULL as an empty field. A UTF-8 BOM in front makes Excel read accented text correctly.
 */
export function toCsv(columns, rows) {
    const field = (value) => {
        if (value === null || value === undefined) {
            return '';
        }
        const text = String(value);

        return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    };
    const lines = [columns.map(field).join(','), ...rows.map((row) => columns.map((column) => field(row[column])).join(','))];

    return `﻿${lines.join('\r\n')}\r\n`;
}

/** JSON: an array of objects, one per row, columns in the order of the result, NULL as null. */
export function toJson(columns, rows) {
    return `${JSON.stringify(rows.map((row) => Object.fromEntries(columns.map((column) => [column, row[column] ?? null]))), null, 2)}\n`;
}

/** "<db>_<YYYY-MM-DD_HH-mm-ss>.<extension>", a name safe on every file system. */
export function exportFilename(db, extension, date = new Date()) {
    const pad = (n) => String(n).padStart(2, '0');
    const stamp = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}_${pad(date.getHours())}-${pad(date.getMinutes())}-${pad(date.getSeconds())}`;

    return `${String(db).replace(/[^\w-]/g, '_')}_${stamp}.${extension}`;
}

/** Saves `content` as a file (Blob + temporary link: no request to the server, allowed by the CSP). */
export function download(filename, content, type) {
    const url = URL.createObjectURL(new Blob([content], { type }));
    const link = Object.assign(document.createElement('a'), { href: url, download: filename });
    link.classList.add('d-none');
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
}
