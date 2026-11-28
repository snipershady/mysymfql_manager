// Browser smoke test (headless Chrome over the DevTools protocol, no npm dependencies; Node >= 22).
// Run it through tests/browser/run.sh, which starts the app in the test environment.
//
// For every page: no uncaught JS error, no console error, no request to another host (no CDN),
// no failed same-origin request; plus page-specific checks and interaction scenarios.
// SMOKE_SCREENSHOTS=<dir>: also saves a full-page PNG of every page with a scenario (1440 px wide), to look at.
import { spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
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
    { path: `/schema/server-cockpit?${S}`, scenario: 'serverCockpit' },
    { path: `/schema/schema-cockpit?${D}`, scenario: 'schemaCockpit' },
    { path: `/schema/transactions?${S}`, expect: { dt: 1 } },
    { path: `/schema/engine-status?${S}` },
    { path: `/schema/db-users?${S}`, expect: { dt: 1, rows: 1 }, scenario: 'dbUsers' },
    { path: `/schema/db-users?${D}`, expect: { dt: 1, rows: 1 }, scenario: 'grants' },
    { path: '/schema/backup-list' },
    { path: `/schema/table-restore?${D}&table=jujutsu_kaisen_cast` },
    { path: '/sqlclient' },
    { path: '/sqlclient/1' },
    { path: '/sqlclient/1/edit' },
    { path: '/admin/appuser' },
    { path: '/admin/database-owner', expect: { dt: 1, rows: 1 } },
    { path: '/admin/database-owner/new' },
    { path: '/admin/audit-log' },
    { path: '/admin/server-grants' },
    { path: '/change-password' },
];
// The databases page only lists servers the user owns: test_owner owns server_locale.
const ownerPages = [
    { path: '/', scenario: 'dashboard' },
    { path: '/schema/databases', scenario: 'databases' },
    { path: '/', scenario: 'turboNavigation' },
    { path: '/change-password', scenario: 'turboForm', allowStatus: [422] },
];

// --- Scenarios (run in the page; libraries are imported through the page's import map) ---------------
const scenarios = {
    // Turbo Drive: a sidebar link must not reload the page, and the controllers of the new page must work.
    turboNavigation: () => `(async () => {
        window.__noReload = 'still-here';
        document.querySelector('#sidebar a[href="/schema/databases"]').click(); ${wait(2500)};
        const onDatabases = location.pathname === '/schema/databases';
        const sel = document.getElementById('select-sql-client');
        sel.value = '${SERVER}'; sel.dispatchEvent(new Event('change', { bubbles: true })); ${wait(2500)};
        const rows = document.querySelectorAll('table.dataTable tbody tr').length;
        document.querySelector('#sidebar a[href="/"]').click(); ${wait(2000)};
        const backOnDashboard = location.pathname === '/' && !!document.getElementById('server-select');
        const noReload = window.__noReload === 'still-here';
        const turbo = !!window.Turbo;
        return { ok: turbo && onDatabases && rows > 0 && backOnDashboard && noReload, turbo, onDatabases, rows, backOnDashboard, noReload };
    })()`,
    // Turbo form submission answered with 422: the form is re-rendered with its errors, without a reload.
    turboForm: () => `(async () => {
        window.__noReload = 'still-here';
        const form = document.querySelector('form[name="change_password"]');
        form.querySelector('[name="change_password[oldPlainPassword]"]').value = 'whatever';
        form.querySelector('[name="change_password[newPlainPassword][first]"]').value = 'New-passw0rd-1';
        form.querySelector('[name="change_password[newPlainPassword][second]"]').value = 'Different-passw0rd';
        form.requestSubmit(); ${wait(2500)};
        const error = document.body.innerText.includes('Le password non coincidono');
        const noReload = window.__noReload === 'still-here';
        return { ok: error && noReload, error, noReload };
    })()`,
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
    // Grant modal end to end (account smoke_grant, created by seed.php with SELECT on the demo database):
    // the JSON built by the modal replaces the grants, then unchecking the database revokes them.
    grants: () => `(async () => {
        const grantsOnServer = async () => (await (await fetch('/schema/db-user-grants-data?' + new URLSearchParams({ name: '${SERVER}', username: 'smoke_grant', user_host: '%' }))).json()).grants;
        const search = document.querySelector('.dt-search input');
        search.value = 'smoke_grant'; search.dispatchEvent(new Event('input', { bubbles: true })); ${wait(800)};
        const open = async () => { document.querySelector('[data-action="db-users#openGrants"][data-user="smoke_grant"]').click(); ${wait(2000)}; };
        const save = async () => { document.getElementById('btn-grant-submit').click(); ${wait(2500)}; document.querySelector('.swal2-confirm')?.click(); ${wait(500)}; };

        await open();
        const box = document.querySelector('#grant-db-list input[value="${DB}"]');
        const preChecked = box?.checked === true && document.getElementById('grant-priv-select').checked;
        document.getElementById('grant-priv-insert').click();
        document.getElementById('grant-priv-update').click();
        await save();
        const replaced = JSON.stringify((await grantsOnServer()).map(g => [g.db, g.privileges]));

        await open();
        document.querySelector('#grant-db-list input[value="${DB}"]').click(); // uncheck: revoke
        await save();
        const revoked = (await grantsOnServer()).length;

        const expected = JSON.stringify([['${DB}', ['SELECT', 'INSERT', 'UPDATE']]]);
        return { ok: preChecked && replaced === expected && 0 === revoked, preChecked, replaced, revoked };
    })()`,
    dashboard: () => `(async () => {
        const sel = document.querySelector('select');
        if (!sel) return { ok: false, why: 'no server select' };
        sel.value = [...sel.options].find(o => o.textContent.includes('${SERVER}'))?.value ?? '';
        sel.dispatchEvent(new Event('change', { bubbles: true })); ${wait(2500)};
        const databases = document.body.innerText.includes('${DB}');
        // Backup queue card (seed.php adds a failed entry): retry through a Turbo form submission.
        window.__noReload = 'still-here';
        const failed = !!document.querySelector('#backup-queue [data-queue-status="failed"]');
        document.querySelector('#backup-queue form button').click(); ${wait(2500)};
        const flash = document.querySelector('#backup-queue .alert')?.textContent.includes('rimesso in coda') ?? false;
        const pending = !!document.querySelector('#backup-queue [data-queue-status="pending"]');
        const noReload = window.__noReload === 'still-here';
        return { ok: databases && failed && flash && pending && noReload, databases, failed, flash, pending, noReload };
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
    serverCockpit: () => `(async () => {
        ${wait(2500)};
        const tiles = document.querySelectorAll('#cockpit-tiles .cockpit-tile').length;
        const findings = !document.querySelector('#cockpit-findings .fa-spinner');
        const queries = !!document.querySelector('#cockpit-top-queries table, #cockpit-top-queries .alert, #cockpit-top-queries p');
        const meters = [...document.querySelectorAll('.cockpit-meter .progress-bar')].every((bar) => bar.style.width !== '');
        const severity = !!document.querySelector('#cockpit-severity .badge');
        const databases = document.querySelectorAll('[data-server-cockpit-target="databases"] tbody tr').length;
        // Percona Server: the card is shown and explains userstat; on stock MySQL it stays hidden.
        const percona = document.getElementById('cockpit-percona');
        const isPercona = [...document.querySelectorAll('[data-server-cockpit-target="config"] td')].some((td) => td.textContent.includes('Percona'));
        const perconaOk = isPercona ? !percona.hidden && percona.textContent.includes('USER_STATISTICS') : percona.hidden;
        // Manual refresh: a second sample turns the traffic into live rates.
        document.querySelector('[data-action="server-cockpit#refresh"]').click(); ${wait(2000)};
        const live = [...document.querySelectorAll('th')].some((th) => th.textContent === 'Al secondo');
        const error = document.querySelector('[data-server-cockpit-target="error"]');
        const noError = error.classList.contains('d-none');
        return { ok: tiles >= 6 && findings && queries && meters && severity && live && databases > 0 && noError && perconaOk, perconaOk, tiles, findings, queries, meters, severity, live, databases, error: noError ? null : error.textContent };
    })()`,
    schemaCockpit: () => `(async () => {
        ${wait(2500)};
        const tiles = document.querySelectorAll('#cockpit-tiles .cockpit-tile').length;
        const tables = document.querySelectorAll('#cockpit-tables tbody tr').length;
        const severity = !!document.querySelector('#cockpit-severity .badge');
        const sessions = !!document.querySelector('#cockpit-sessions table, #cockpit-sessions p, #cockpit-sessions .alert');
        const error = document.querySelector('[data-schema-cockpit-target="error"]');
        const noError = error.classList.contains('d-none');
        return { ok: tiles >= 6 && tables > 0 && severity && sessions && noError, tiles, tables, severity, sessions, error: noError ? null : error.textContent };
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
        // Export: built in the browser from the result shown (capture the Blob instead of saving a file).
        const blobs = [];
        const createObjectURL = URL.createObjectURL;
        URL.createObjectURL = (blob) => { blobs.push(blob); return createObjectURL.call(URL, blob); };
        document.getElementById('btn-export-csv').click();
        document.getElementById('btn-export-json').click();
        URL.createObjectURL = createObjectURL;
        const csv = blobs[0] ? await blobs[0].text() : ''; // text() drops the UTF-8 BOM: check it on the bytes
        const bom = blobs[0] ? [...new Uint8Array(await blobs[0].slice(0, 3).arrayBuffer())].join(',') === '239,187,191' : false;
        const json = blobs[1] ? JSON.parse(await blobs[1].text()) : null;
        const exported = bom && csv === 'answer\\r\\n42\\r\\n' && Array.isArray(json) && String(json[0]?.answer) === '42';
        // History (from the audit log): the statement just run, back in the editor with a click.
        const item = document.querySelector('#query-history-list [data-action="query#useHistory"]');
        view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: '' } });
        item?.click();
        const history = !!item && view.state.doc.toString() === 'SELECT 41 + 1 AS answer';
        return { ok: labels.includes('jujutsu_kaisen_cast') && shortcut && cells.includes('42') && exported && history, labels: labels.slice(0, 3), shortcut, cells, exported, csv, history };
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

function problems(events, allowStatus = []) {
    const url = (u) => { try { return new URL(u); } catch { return null; } };
    return {
        exceptions: events.filter((e) => e.method === 'Runtime.exceptionThrown')
            .map((e) => e.params.exceptionDetails.exception?.description?.split('\n')[0] ?? e.params.exceptionDetails.text),
        consoleErrors: events.filter((e) => e.method === 'Runtime.consoleAPICalled' && e.params.type === 'error')
            .map((e) => e.params.args.map((a) => a.value ?? a.description).join(' ')),
        logErrors: events.filter((e) => e.method === 'Log.entryAdded' && e.params.entry.level === 'error'
                && !allowStatus.some((status) => e.params.entry.text.includes(`status of ${status} `)))
            .map((e) => `${e.params.entry.text} ${e.params.entry.url ?? ''}`),
        dialogs: events.filter((e) => e.method === 'Page.javascriptDialogOpening')
            .map((e) => `${e.params.type}: ${e.params.message}`),
        externalHosts: [...new Set(events.filter((e) => e.method === 'Network.requestWillBeSent')
            .map((e) => url(e.params.request.url)).filter((u) => u && /^https?:$/.test(u.protocol) && u.host !== host).map((u) => u.host))],
        failedRequests: events.filter((e) => e.method === 'Network.responseReceived' && e.params.response.status >= 400
                && !allowStatus.includes(e.params.response.status) && url(e.params.response.url)?.host === host)
            .map((e) => `${e.params.response.status} ${url(e.params.response.url).pathname}`),
    };
}

function check(label, events, extra = {}, assertions = [], allowStatus = []) {
    const found = problems(events, allowStatus);
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
            if (process.env.SMOKE_SCREENSHOTS) {
                mkdirSync(process.env.SMOKE_SCREENSHOTS, { recursive: true });
                await b.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
                await sleep(500);
                const shot = await b.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                writeFileSync(join(process.env.SMOKE_SCREENSHOTS, `${username}_${page.scenario}.png`), Buffer.from(shot.result?.data ?? '', 'base64'));
            }
        }
        check(`[${username}] ${page.path}${page.scenario ? ` (${page.scenario})` : ''}`, b.events, page.expect ? { dt: counts.dt, rows: counts.rows, cm: counts.cm } : {}, assertions, page.allowStatus ?? []);
    }
    await b.close();
}

// Password reset from the e-mailed link (prepared by seed.php), without any session: a real browser POST with
// <meta name="referrer" content="no-referrer"> (Origin: null) must still pass the stateless CSRF check.
async function resetFlow() {
    const b = await openBrowser();
    const path = readFileSync(new URL('../../var/smoke/reset-path.txt', import.meta.url), 'utf8').trim();
    b.events.length = 0;
    await b.send('Page.navigate', { url: base + path });
    await sleep(2000);
    const result = await b.evaluate(`(async () => {
        const form = document.querySelector('form[name="reset_password_confirm"]');
        if (!form) return { ok: false, why: 'no form', path: location.pathname };
        form.querySelector('[name="reset_password_confirm[newPlainPassword][first]"]').value = 'Smoke-Reset-passw0rd';
        form.querySelector('[name="reset_password_confirm[newPlainPassword][second]"]').value = 'Smoke-Reset-passw0rd';
        form.requestSubmit(); ${wait(2500)};
        const landed = location.pathname;
        const flash = document.body.innerText.includes('Password aggiornata');
        return { ok: landed === '/login' && flash, landed, flash };
    })()`);
    check('[anonymous] password reset link', b.events, result, result?.ok ? [] : [`reset: ${JSON.stringify(result)}`]);
    await b.close();
}

await resetFlow();
await session('test_admin', adminPages);
await session('test_owner', ownerPages);

console.log(failures ? `\n${failures} check(s) failed` : '\nAll browser checks passed');
process.exit(failures ? 1 : 0);
