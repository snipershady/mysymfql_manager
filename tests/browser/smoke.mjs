// Browser smoke test (headless Chrome over the DevTools protocol, no npm dependencies; Node >= 22).
// Run it through tests/browser/run.sh, which starts the app in the test environment.
//
// For every page: no uncaught JS error, no console error, no request to another host (no CDN),
// no failed same-origin request; plus page-specific checks and interaction scenarios.
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = process.argv[2] ?? 'http://127.0.0.1:8099';
const PASSWORD = 'Test-Passw0rd!'; // tests/Fixtures/TestCatalog::PASSWORD
const SERVER = 'server_locale';
const DB = 'unit_testing_one';
const S = `name=${SERVER}`;
const D = `name=${SERVER}&db_name=${DB}`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const wait = (ms) => `await new Promise(r => setTimeout(r, ${ms}))`;

// --- Pages -------------------------------------------------------------------------------------------
// expect: minimum counts of DataTables containers (dt), table rows (rows), CodeMirror editors (cm).
const adminPages = [
    { path: '/' },
    { path: `/schema/tables?${D}`, expect: { dt: 1, rows: 1 }, scenario: 'tables' },
    { path: `/schema/query?${D}`, expect: { cm: 1 }, scenario: 'query' },
    { path: `/schema/process-list?${S}`, expect: { dt: 1, rows: 1 } },
    { path: `/schema/transactions?${S}`, expect: { dt: 1 } },
    { path: `/schema/engine-status?${S}` },
    { path: `/schema/db-users?${S}`, expect: { dt: 1, rows: 1 }, scenario: 'dbUsers' },
    { path: `/schema/db-users?${D}`, expect: { dt: 1, rows: 1 } },
    { path: '/schema/backup-list' },
    { path: `/schema/table-restore?${D}&table=jujutsu_kaisen_cast` },
    { path: '/sqlclient' },
    { path: '/sqlclient/1' },
    { path: '/sqlclient/1/edit' },
    { path: '/admin/appuser' },
    { path: '/admin/database-owner', expect: { dt: 1, rows: 1 } },
    { path: '/admin/database-owner/new' },
    { path: '/change-password' },
];
// The databases page only lists servers the user owns: test_owner owns server_locale.
const ownerPages = [
    { path: '/', scenario: 'dashboard' },
    { path: '/schema/databases', scenario: 'databases' },
];

// --- Scenarios (run in the page; libraries are imported through the page's import map) ---------------
const scenarios = {
    tables: () => `(async () => {
        document.querySelector('[data-action="tables#showCreate"]').click(); ${wait(1500)};
        const ddl = document.getElementById('modalShowCreateContent')?.textContent ?? '';
        const shown = !!document.querySelector('#modalShowCreate.show');
        const { Modal } = await import('bootstrap');
        Modal.getInstance(document.getElementById('modalShowCreate'))?.hide(); ${wait(500)};
        return { ok: shown && ddl.includes('CREATE TABLE'), shown, ddl: ddl.slice(0, 40) };
    })()`,
    dbUsers: () => `(async () => {
        document.querySelector('[data-action="db-users#openGrants"]').click(); ${wait(2000)};
        const dbs = document.querySelectorAll('#grant-db-list input[type=checkbox]').length;
        const { Modal } = await import('bootstrap');
        Modal.getInstance(document.getElementById('modal-grant'))?.hide(); ${wait(500)};
        const custom = document.getElementById('cu-host-3');
        custom.click(); ${wait(100)};
        const customShown = !document.getElementById('cu-host-custom').hidden;
        const all = document.getElementById('cu-priv-all');
        all.click(); ${wait(100)};
        const singlesDisabled = [...document.querySelectorAll('#modal-create-user [data-privileges-target="single"]')].every(cb => cb.disabled);
        return { ok: dbs > 0 && customShown && singlesDisabled, dbs, customShown, singlesDisabled };
    })()`,
    dashboard: () => `(async () => {
        const sel = document.querySelector('select');
        if (!sel) return { ok: false, why: 'no server select' };
        sel.value = [...sel.options].find(o => o.textContent.includes('${SERVER}'))?.value ?? '';
        sel.dispatchEvent(new Event('change', { bubbles: true })); ${wait(2500)};
        const text = document.body.innerText;
        return { ok: text.includes('${DB}'), why: 'database list after choosing the server' };
    })()`,
    databases: () => `(async () => {
        const sel = document.getElementById('select-sql-client');
        sel.value = '${SERVER}'; sel.dispatchEvent(new Event('change', { bubbles: true })); ${wait(2500)};
        const info = document.querySelector('.dt-info')?.textContent ?? '';
        const rows = document.querySelectorAll('table.dataTable tbody tr').length;
        document.getElementById('btn-open-create-db').click(); ${wait(800)};
        const modalShown = !!document.querySelector('.modal.show');
        const { Modal } = await import('bootstrap');
        Modal.getInstance(document.querySelector('.modal.show'))?.hide(); ${wait(500)};
        const cta = document.getElementById('btn-open-transactions')?.href.includes('/schema/transactions?${S}');
        const Swal = (await import('sweetalert2/dist/sweetalert2.esm.js')).default;
        Swal.fire({ title: 'probe', timer: 200 }); ${wait(100)};
        const swal = !!document.querySelector('.swal2-popup'); ${wait(500)};
        const italian = /elementi/.test(info);
        return { ok: rows > 0 && italian && modalShown && cta && swal, rows, info, modalShown, cta, swal };
    })()`,
    query: () => `(async () => {
        ${wait(1500)};
        const { EditorView, runScopeHandlers } = await import('@codemirror/view');
        const { startCompletion, currentCompletions } = await import('@codemirror/autocomplete');
        const view = EditorView.findFromDOM(document.querySelector('.cm-editor'));
        view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: 'SELECT * FROM juj' }, selection: { anchor: 17 } });
        view.focus(); startCompletion(view); ${wait(1000)};
        const labels = currentCompletions(view.state).map(c => c.label);
        view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: 'SELECT 41 + 1 AS answer' }, selection: { anchor: 23 } });
        // Ctrl+Enter through the editor keymap (headless key events are not reliable).
        const shortcut = runScopeHandlers(view, new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', ctrlKey: true, keyCode: 13 }), 'editor');
        ${wait(2500)};
        const cells = [...document.querySelectorAll('#query-result-table td')].map(c => c.textContent.trim());
        return { ok: labels.includes('jujutsu_kaisen_cast') && shortcut && cells.includes('42'), labels: labels.slice(0, 3), shortcut, cells };
    })()`,
};

// --- DevTools protocol plumbing ---------------------------------------------------------------------
async function openBrowser() {
    const profile = mkdtempSync(join(tmpdir(), 'smoke-'));
    // Port 0: Chrome picks a free port and writes it to <profile>/DevToolsActivePort, so a leftover
    // browser from an interrupted run can never be picked up by mistake.
    const chrome = spawn(process.env.CHROME_BIN ?? 'google-chrome', [
        '--headless=new', '--remote-debugging-port=0', '--no-first-run', '--no-default-browser-check',
        '--no-sandbox', `--user-data-dir=${profile}`, 'about:blank',
    ], { stdio: 'ignore' });
    let target;
    for (let i = 0; i < 100 && !target; i++) {
        try {
            const port = readFileSync(join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0];
            target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page');
        } catch {
            await sleep(100);
        }
    }
    if (!target) throw new Error('Chrome did not start');
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener('open', r));
    let id = 0;
    const pending = new Map();
    const events = [];
    ws.addEventListener('message', (m) => {
        const msg = JSON.parse(m.data);
        if (msg.id && pending.has(msg.id)) {
            pending.get(msg.id)(msg);
            pending.delete(msg.id);

            return;
        }
        events.push(msg);
        // A native alert/confirm (e.g. a DataTables error) blocks the page and every further command:
        // dismiss it right away; problems() reports it as a failure.
        if ('Page.javascriptDialogOpening' === msg.method) {
            ws.send(JSON.stringify({ id: ++id, method: 'Page.handleJavaScriptDialog', params: { accept: true } }));
        }
    });
    const send = (method, params = {}) => new Promise((r) => {
        const i = ++id;
        pending.set(i, r);
        ws.send(JSON.stringify({ id: i, method, params }));
    });
    const evaluate = async (expression, timeoutMs = 20000) => {
        const r = await Promise.race([
            send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }),
            sleep(timeoutMs).then(() => ({ result: { exceptionDetails: { exception: { description: `evaluation timed out after ${timeoutMs} ms` } } } })),
        ]);
        return r.result?.exceptionDetails ? { error: r.result.exceptionDetails.exception?.description } : r.result?.result?.value;
    };
    for (const domain of ['Runtime', 'Network', 'Log', 'Page']) await send(`${domain}.enable`);
    const close = async () => {
        ws.close();
        const exited = new Promise((r) => chrome.once('exit', r));
        chrome.kill();
        await exited;
        rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
    };

    return { send, evaluate, events, close };
}

const host = new URL(base).host;
let failures = 0;

function problems(events) {
    const url = (u) => { try { return new URL(u); } catch { return null; } };
    return {
        exceptions: events.filter((e) => e.method === 'Runtime.exceptionThrown')
            .map((e) => e.params.exceptionDetails.exception?.description?.split('\n')[0] ?? e.params.exceptionDetails.text),
        consoleErrors: events.filter((e) => e.method === 'Runtime.consoleAPICalled' && e.params.type === 'error')
            .map((e) => e.params.args.map((a) => a.value ?? a.description).join(' ')),
        logErrors: events.filter((e) => e.method === 'Log.entryAdded' && e.params.entry.level === 'error')
            .map((e) => `${e.params.entry.text} ${e.params.entry.url ?? ''}`),
        dialogs: events.filter((e) => e.method === 'Page.javascriptDialogOpening')
            .map((e) => `${e.params.type}: ${e.params.message}`),
        externalHosts: [...new Set(events.filter((e) => e.method === 'Network.requestWillBeSent')
            .map((e) => url(e.params.request.url)).filter((u) => u && /^https?:$/.test(u.protocol) && u.host !== host).map((u) => u.host))],
        failedRequests: events.filter((e) => e.method === 'Network.responseReceived' && e.params.response.status >= 400 && url(e.params.response.url)?.host === host)
            .map((e) => `${e.params.response.status} ${url(e.params.response.url).pathname}`),
    };
}

function check(label, events, extra = {}, assertions = []) {
    const found = problems(events);
    const errors = [...Object.entries(found).filter(([, v]) => v.length).map(([k, v]) => `${k}: ${v.join(' | ')}`), ...assertions];
    console.log(`${errors.length ? 'FAIL' : 'ok  '} ${label} ${Object.keys(extra).length ? JSON.stringify(extra) : ''}`);
    for (const e of errors) console.log(`       ${e}`);
    failures += errors.length ? 1 : 0;
}

async function session(username, pages) {
    const b = await openBrowser();
    const visit = async (path, ms = 2500) => {
        b.events.length = 0;
        await b.send('Page.navigate', { url: base + path });
        await sleep(ms);
    };

    // Login: fill the form and let the Altcha widget solve its proof of work, as a user would.
    await visit('/login', 2000);
    const altcha = await b.evaluate(`(async () => {
        document.querySelector('[name=_username]').value = '${username}';
        document.querySelector('[name=_password]').value = '${PASSWORD}';
        const w = document.querySelector('altcha-widget');
        await w.verify();
        for (let i = 0; i < 50 && w.getState() !== 'verified'; i++) ${wait(200)};
        return w.getState();
    })()`);
    check(`[${username}] /login`, b.events, { altcha }, altcha === 'verified' ? [] : [`altcha state: ${JSON.stringify(altcha)}`]);
    await b.evaluate(`document.querySelector('[name=_username]').form.requestSubmit()`);
    await sleep(2500);
    const landed = await b.evaluate('location.pathname');
    if ('/login' === landed) {
        check(`[${username}] login`, [], {}, ['still on /login after submitting the form']);
        await b.close();

        return;
    }

    for (const page of pages) {
        await visit(page.path);
        const assertions = [];
        const counts = await b.evaluate(`({
            dt: document.querySelectorAll('.dt-container').length,
            rows: document.querySelectorAll('table.dataTable tbody tr:not(:has(td.dt-empty))').length,
            cm: document.querySelectorAll('.cm-editor').length,
            path: location.pathname,
        })`);
        if (counts?.error) assertions.push(`page evaluation failed: ${counts.error}`);
        else if (counts.path !== page.path.split('?')[0]) assertions.push(`redirected to ${counts.path}`);
        for (const [k, min] of Object.entries(page.expect ?? {})) {
            if ((counts[k] ?? 0) < min) assertions.push(`${k}: expected >= ${min}, got ${counts[k]}`);
        }
        let result = {};
        if (page.scenario) {
            result = await b.evaluate(scenarios[page.scenario]());
            if (!result?.ok) assertions.push(`scenario ${page.scenario}: ${JSON.stringify(result)}`);
        }
        check(`[${username}] ${page.path}${page.scenario ? ` (${page.scenario})` : ''}`, b.events, page.expect ? { dt: counts.dt, rows: counts.rows, cm: counts.cm } : {}, assertions);
    }
    await b.close();
}

await session('test_admin', adminPages);
await session('test_owner', ownerPages);

console.log(failures ? `\n${failures} check(s) failed` : '\nAll browser checks passed');
process.exit(failures ? 1 : 0);
