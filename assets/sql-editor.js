/*
 * SQL editor for the query page, on CodeMirror 6 (replaces CodeMirror 5 from the CDN).
 *
 * Same operations the page used on CodeMirror 5: getValue, setValue, replaceSelection, focus,
 * plus setSchema() to feed table/column names to the autocompletion once they are loaded
 * (lang-sql also completes `alias.column` after `FROM table alias`).
 */
import { basicSetup } from 'codemirror';
import { EditorView, keymap } from '@codemirror/view';
import { Compartment, Prec } from '@codemirror/state';
import { sql, MySQL } from '@codemirror/lang-sql';

/**
 * @param {HTMLTextAreaElement} textarea hidden and replaced by the editor (its value is the initial text)
 * @param {{ onRun: () => void }} options  onRun: Ctrl/Cmd+Enter
 */
export function createSqlEditor(textarea, { onRun }) {
    const language = new Compartment();
    const sqlLanguage = (schema) => sql({ dialect: MySQL, schema, upperCaseKeywords: true });

    const view = new EditorView({
        doc: textarea.value,
        extensions: [
            basicSetup,
            EditorView.lineWrapping,
            // Content-Security-Policy: nonce for the <style> CodeMirror injects where adoptedStyleSheets is unavailable.
            EditorView.cspNonce.of(document.querySelector('meta[name="csp-nonce"]')?.content ?? ''),
            language.of(sqlLanguage({})),
            // Before the default keymap, which binds Mod-Enter to "insert blank line".
            Prec.highest(keymap.of([{ key: 'Mod-Enter', run: () => { onRun(); return true; } }])),
        ],
    });

    textarea.hidden = true;
    textarea.insertAdjacentElement('afterend', view.dom);

    return {
        view,
        getValue: () => view.state.doc.toString(),
        setValue(text) {
            view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } });
        },
        replaceSelection(text) {
            view.dispatch(view.state.replaceSelection(text));
        },
        focus: () => view.focus(),
        /** @param {Record<string, string[]>} tables table name => column names */
        setSchema(tables) {
            view.dispatch({ effects: language.reconfigure(sqlLanguage(tables)) });
        },
    };
}
