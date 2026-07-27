import { readFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { createRequire } from 'node:module';

const moduleRoot = process.env.JSDOM_MODULES;
if (!moduleRoot) {
    throw new Error('JSDOM_MODULES muss auf ein node_modules-Verzeichnis mit jsdom zeigen.');
}

const require = createRequire(import.meta.url);
const { JSDOM } = require(join(moduleRoot, 'jsdom'));
const projectRoot = resolve(new URL('..', import.meta.url).pathname);
const appSource = await readFile(join(projectRoot, 'public', 'assets', 'app.js'), 'utf8');

const workspace = {
    schemaVersion: 1,
    id: 301,
    title: 'Privat',
    createdAt: '2026-07-26T10:00:00+02:00',
    updatedAt: '2026-07-26T10:00:00+02:00',
    pageIndex: {
        rootPageIds: [102],
        pages: {
            102: {
                title: 'Wetterstation',
                slug: 'wetterstation',
                children: [],
            },
        },
        retiredPageIds: [],
    },
};

let page = {
    schemaVersion: 1,
    id: 102,
    title: 'Wetterstation',
    slug: 'wetterstation',
    revision: 1,
    draftRevision: 0,
    createdAt: '2026-07-26T10:00:00+02:00',
    createdBy: 'user_test',
    updatedAt: '2026-07-26T10:00:00+02:00',
    updatedBy: 'user_test',
    labels: ['esp32'],
    blocks: [
        {
            id: 'a'.repeat(64),
            type: 'heading',
            content: 'Aufbau',
            settings: { level: 1, includeInToc: true, anchor: null },
            meta: {},
        },
        {
            id: 'b'.repeat(64),
            type: 'raw_text',
            content: 'GPIO 4 = SDA',
            settings: { wrap: true },
            meta: {},
        },
        {
            id: 'c'.repeat(64),
            type: 'markdown',
            content: 'Sensor über **I²C** anschließen.',
            settings: { editorMode: 'split' },
            meta: {},
        },
        {
            id: 'd'.repeat(64),
            type: 'code',
            content: 'digitalWrite(4, HIGH);',
            settings: { language: 'cpp', showLineNumbers: true, wrap: false, title: 'main.cpp' },
            meta: {},
        },
        {
            id: 'e'.repeat(64),
            type: 'divider',
            content: null,
            settings: { style: 'line' },
            meta: {},
        },
        {
            id: 'f'.repeat(64),
            type: 'callout',
            content: null,
            settings: { style: 'warning', title: 'Achtung', icon: '⚠' },
            children: [
                {
                    id: '1'.repeat(64),
                    type: 'raw_text',
                    content: 'Vorher ausschalten',
                    settings: { wrap: true },
                    meta: {},
                },
            ],
            meta: {},
        },
        {
            id: '2'.repeat(64),
            type: 'expand',
            content: null,
            settings: { title: 'Details', defaultDisplay: 'collapsed' },
            children: [
                {
                    id: '3'.repeat(64),
                    type: 'code',
                    content: 'const pin = 4;',
                    settings: { language: 'js', showLineNumbers: false, wrap: true, title: null },
                    meta: {},
                },
            ],
            meta: {},
        },
    ],
};

let draftRevision = 0;
let blockCounter = 13;
const calls = [];
const dom = new JSDOM(
    '<!doctype html><html><head><title></title></head><body><div id="app"></div></body></html>',
    {
        url: 'http://localhost/workspaces/301/pages/102',
        runScripts: 'outside-only',
        pretendToBeVisual: true,
    }
);

const { window } = dom;
window.structuredClone = globalThis.structuredClone;
window.open = () => null;
Object.defineProperty(window.navigator, 'clipboard', {
    value: { writeText: async () => {} },
});

window.fetch = async (url, options = {}) => {
    const path = String(url);
    calls.push({ path, method: options.method || 'GET' });
    let status = 200;
    let data;

    if (path === '/api/session') {
        data = {
            configured: true,
            authenticated: true,
            user: {
                id: 'user_test',
                username: 'test',
                displayName: 'Test User',
                role: 'admin',
                createdAt: '2026-07-26T10:00:00+02:00',
            },
            csrfToken: 'csrf-test',
        };
    } else if (path === '/api/v1/workspaces') {
        data = {
            workspaces: [
                {
                    id: 301,
                    title: 'Privat',
                    createdAt: workspace.createdAt,
                    updatedAt: workspace.updatedAt,
                    pageCount: 1,
                },
            ],
        };
    } else if (path === '/api/v1/workspaces/301') {
        data = { workspace };
    } else if (path === '/api/v1/workspaces/301/pages/102') {
        data = {
            workspace,
            page,
            hasDraft: draftRevision > 0,
            publishedRevision: 1,
            draftRevision,
            draftSavedAt: draftRevision ? '2026-07-26T10:05:00+02:00' : null,
        };
    } else if (path.endsWith('/block-ids')) {
        status = 201;
        data = { blockId: blockCounter.toString(16).padStart(64, '0') };
        blockCounter += 1;
    } else if (path.endsWith('/draft') && options.method === 'PATCH') {
        const body = JSON.parse(options.body);
        page = body.page;
        draftRevision += 1;
        page.draftRevision = draftRevision;
        data = {
            workspace,
            page,
            hasDraft: true,
            publishedRevision: 1,
            draftRevision,
            draftSavedAt: '2026-07-26T10:05:00+02:00',
        };
    } else {
        throw new Error(`Nicht gemockter API-Aufruf: ${options.method || 'GET'} ${path}`);
    }

    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: (name) => name.toLowerCase() === 'content-type' ? 'application/json' : null },
        json: async () => ({ ok: true, data }),
    };
};

const errors = [];
window.addEventListener('error', (event) => errors.push(event.error || event.message));
window.eval(`${appSource}\n//# sourceURL=app.js`);

async function waitFor(selector, timeout = 2000) {
    const started = Date.now();
    while (Date.now() - started < timeout) {
        const element = window.document.querySelector(selector);
        if (element) return element;
        await new Promise((resolvePromise) => setTimeout(resolvePromise, 10));
    }
    throw new Error(`Element nicht gefunden: ${selector}`);
}

async function waitForDraftRevision(minimumRevision, timeout = 3500) {
    const started = Date.now();
    while (Date.now() - started < timeout) {
        if (draftRevision >= minimumRevision) return;
        await new Promise((resolvePromise) => setTimeout(resolvePromise, 20));
    }
    throw new Error(`Entwurfsrevision ${minimumRevision} wurde nicht rechtzeitig gespeichert.`);
}

function findBlockInTree(blocks, blockId) {
    for (const block of blocks) {
        if (block.id === blockId) return block;
        const nested = findBlockInTree(block.children || [], blockId);
        if (nested) return nested;
    }
    return null;
}

let assertions = 0;
function assert(condition, message) {
    assertions += 1;
    if (!condition) throw new Error(`Assertion fehlgeschlagen: ${message}`);
}

await waitFor('.editor-page');
assert(window.document.querySelector('#page-title').value === 'Wetterstation', 'Der Seitentitel muss erscheinen.');
assert(window.document.querySelectorAll('.block-card').length === 9, 'Alle sieben Basisblocktypen und ihre Kindblöcke müssen gerendert werden.');
assert(window.document.querySelectorAll('.block-move').length === 9, 'Jeder Block benötigt ein Move-Handle.');
assert(window.document.querySelectorAll('[data-action="up"]').length === 9, 'Jeder Block benötigt einen Aufwärtspfeil.');
assert(window.document.querySelectorAll('[data-action="down"]').length === 9, 'Jeder Block benötigt einen Abwärtspfeil.');
assert(window.document.querySelectorAll('[data-action="menu"]').length === 9, 'Jeder Block benötigt ein Drei-Punkte-Menü.');
assert(window.document.querySelector('.code-editor'), 'Der Codeblock muss einen eigenen Editor besitzen.');
assert(
    window.document.querySelector('[data-block-path="3"] .code-line-numbers span')?.textContent === '1',
    'Aktivierte Code-Zeilennummern müssen sichtbar sein.'
);
assert(window.document.querySelector('.divider-editor hr'), 'Der Divider muss als Trennlinie erscheinen.');
assert(window.document.querySelector('.callout-warning'), 'Der Callout-Stil muss sichtbar umgesetzt werden.');
assert(
    window.document.querySelector('[data-block-path="5"] [data-callout-title]')?.textContent === 'Achtung',
    'Callouts müssen Titel und Icon als Vorschau darstellen.'
);
assert(window.document.querySelector('.expand-editor'), 'Der Expand-Container muss erscheinen.');
assert(
    window.document.querySelector('[data-block-path="5.0"] textarea')?.value === 'Vorher ausschalten',
    'Kindblöcke in einem Callout müssen bearbeitbar sein.'
);
assert(
    window.document.querySelector('[data-block-id="' + 'c'.repeat(64) + '"] .markdown-preview strong')?.textContent === 'I²C',
    'Die Markdown-Vorschau muss Fettschrift sicher rendern.'
);

const rawTextarea = window.document.querySelector('[data-block-id="' + 'b'.repeat(64) + '"] textarea');
rawTextarea.value += '\nGPIO 5 = SCL';
rawTextarea.dispatchEvent(new window.Event('input', { bubbles: true }));
assert(window.document.querySelector('#save-status').textContent === 'Nicht gespeichert', 'Eingaben müssen den Entwurf als dirty markieren.');

const markdownCollapse = window.document.querySelector(
    '[data-block-id="' + 'c'.repeat(64) + '"] [data-action="collapse"]'
);
markdownCollapse.click();
assert(
    window.document.querySelector('[data-block-id="' + 'c'.repeat(64) + '"]').classList.contains('block-collapsed'),
    'Blöcke müssen ohne Inhaltsrevision minimierbar sein.'
);

window.document.querySelector('[data-insert-index="0"]').click();
await waitFor('[data-choice="heading"]');
window.document.querySelector('[data-choice="heading"]').click();
await waitFor('[data-block-id="' + 'd'.repeat(63) + '"]', 50).catch(() => null);
await new Promise((resolvePromise) => setTimeout(resolvePromise, 30));
assert(window.document.querySelectorAll('.block-card').length === 10, 'Ein neuer Block muss über das Plus eingefügt werden.');
assert(
    calls.some((call) => call.path.endsWith('/block-ids') && call.method === 'POST'),
    'Neue Blöcke müssen ihre ID vom Server beziehen.'
);

window.document.querySelector('[data-parent-path="6"][data-insert-index="1"]').click();
await waitFor('[data-choice="code"]');
window.document.querySelector('[data-choice="code"]').click();
await new Promise((resolvePromise) => setTimeout(resolvePromise, 30));
assert(
    window.document.querySelectorAll('[data-block-path^="6."]').length >= 2,
    'Neue Blöcke müssen direkt in einen Container eingefügt werden können.'
);
window.document.querySelector('[data-block-path="6.1"] [data-action="up"]').click();
assert(
    window.document.querySelector('[data-block-path="6.0"]')?.dataset.blockId
        === (14).toString(16).padStart(64, '0'),
    'Kindblöcke müssen innerhalb ihres Containers mit den Pfeilen sortierbar sein.'
);

const regressionFailures = [];
function regressionAssert(condition, message) {
    assertions += 1;
    if (!condition) regressionFailures.push(message);
}

const markdownId = 'c'.repeat(64);
let markdownCard = window.document.querySelector(`[data-block-id="${markdownId}"]`);
if (markdownCard.classList.contains('block-collapsed')) {
    markdownCard.querySelector('[data-action="collapse"]').click();
    markdownCard = window.document.querySelector(`[data-block-id="${markdownId}"]`);
}
markdownCard.querySelector('[data-markdown-mode="raw"]').click();

let expectedDraftRevision = draftRevision + 1;
await waitForDraftRevision(expectedDraftRevision);

markdownCard = window.document.querySelector(`[data-block-id="${markdownId}"]`);
let markdownSource = markdownCard.querySelector('.markdown-source');
const markdownFastText = '\nSchneller Moduswechsel bleibt erhalten.';
markdownSource.value += markdownFastText;
markdownSource.dispatchEvent(new window.Event('input', { bubbles: true }));
markdownCard.querySelector('[data-markdown-mode="split"]').click();

markdownCard = window.document.querySelector(`[data-block-id="${markdownId}"]`);
regressionAssert(
    markdownCard.querySelector('.markdown-source').value.includes(markdownFastText),
    'Markdown-Eingabe darf bei einem sofortigen Wechsel von Bearbeiten zu Geteilt nicht verloren gehen.'
);
regressionAssert(
    markdownCard.querySelector('[data-markdown-editor]').classList.contains('markdown-mode-split'),
    'Der sofort gewählte Markdown-Modus muss tatsächlich aktiviert werden.'
);

expectedDraftRevision = draftRevision + 1;
await waitForDraftRevision(expectedDraftRevision);

const rawId = 'b'.repeat(64);
let currentRaw = window.document.querySelector(`[data-block-id="${rawId}"] textarea`);
const rawFastText = '\nSchnelles Einklappen bleibt erhalten.';
currentRaw.value += rawFastText;
currentRaw.dispatchEvent(new window.Event('input', { bubbles: true }));
window.document
    .querySelector(`[data-block-id="${'e'.repeat(64)}"] [data-action="collapse"]`)
    .click();

currentRaw = window.document.querySelector(`[data-block-id="${rawId}"] textarea`);
regressionAssert(
    currentRaw.value.includes(rawFastText),
    'Raw-Text-Eingabe darf beim sofortigen Einklappen eines anderen Blocks nicht verloren gehen.'
);

expectedDraftRevision = draftRevision + 1;
await waitForDraftRevision(expectedDraftRevision);

currentRaw = window.document.querySelector(`[data-block-id="${rawId}"] textarea`);
const reloadText = '\nDieser Entwurf muss einen Reload überleben.';
currentRaw.value += reloadText;
currentRaw.dispatchEvent(new window.Event('input', { bubbles: true }));
expectedDraftRevision = draftRevision + 1;
await waitForDraftRevision(expectedDraftRevision);

regressionAssert(
    window.document.querySelector('#save-status').textContent.startsWith('Automatisch gespeichert'),
    'Die Oberfläche muss den abgeschlossenen Autosave anzeigen.'
);
regressionAssert(
    findBlockInTree(page.blocks, rawId)?.content.includes(reloadText),
    'Der als gespeichert gemeldete Browserstand muss im serverseitigen Entwurf liegen und einen Reload überleben.'
);

if (regressionFailures.length > 0) {
    throw new Error(`Lost-Update-Regressionen:\n- ${regressionFailures.join('\n- ')}`);
}
assert(errors.length === 0, 'Die Oberfläche darf keine unbehandelten Laufzeitfehler auslösen.');

window.close();
console.log(`OK – ${assertions} Frontend-Assertions erfolgreich.`);
