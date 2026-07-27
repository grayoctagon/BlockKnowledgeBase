import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { createRequire } from 'node:module';

const moduleRoot = process.env.PHP_WASM_MODULES;
if (!moduleRoot) {
    throw new Error('PHP_WASM_MODULES muss auf ein node_modules-Verzeichnis mit @php-wasm/node zeigen.');
}

const require = createRequire(import.meta.url);
const { PHP } = require(join(moduleRoot, '@php-wasm/universal'));
const { loadNodeRuntime, useHostFilesystem } = require(join(moduleRoot, '@php-wasm/node'));

const projectRoot = resolve(new URL('..', import.meta.url).pathname);
const dataDir = await mkdtemp(join(tmpdir(), 'bkb-http-test-'));
const scriptPath = join(projectRoot, 'public', 'index.php');
const php = new PHP(
    await loadNodeRuntime('8.3', {
        emscriptenOptions: { processId: process.pid },
    })
);
useHostFilesystem(php);

let cookie = '';
let csrfToken = '';
let assertions = 0;

function assert(condition, message) {
    assertions += 1;
    if (!condition) throw new Error(`Assertion fehlgeschlagen: ${message}`);
}

async function request(method, url, body = undefined, token = csrfToken) {
    const headers = { Accept: 'application/json' };
    if (cookie) headers.Cookie = cookie;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) headers['X-CSRF-Token'] = token;

    const response = await php.run({
        scriptPath,
        relativeUri: url,
        method,
        headers,
        body: body === undefined ? undefined : new TextEncoder().encode(JSON.stringify(body)),
        env: { BKB_DATA_DIR: dataDir },
        $_SERVER: {
            REQUEST_URI: url,
            REQUEST_METHOD: method,
            HTTP_HOST: 'localhost',
        },
    });

    const setCookie = response.headers['set-cookie']?.at(-1);
    if (setCookie) cookie = setCookie.split(';', 1)[0];

    if (response.errors) {
        throw new Error(response.errors);
    }

    return {
        status: response.httpStatusCode,
        payload: JSON.parse(response.text),
    };
}

let response = await request('GET', '/api/session');
assert(response.status === 200, 'Die Session-Abfrage muss HTTP 200 liefern.');
assert(response.payload.data.configured === false, 'Eine leere Installation darf noch nicht konfiguriert sein.');
csrfToken = response.payload.data.csrfToken;

response = await request(
    'POST',
    '/api/setup',
    {
        displayName: 'Test Admin',
        username: 'testadmin',
        password: 'Ein-sicheres-Testpasswort-123!',
    },
    'ungueltig'
);
assert(response.status === 403, 'Mutierende Anfragen mit falschem CSRF-Token müssen abgewiesen werden.');

response = await request('POST', '/api/setup', {
    displayName: 'Test Admin',
    username: 'testadmin',
    password: 'Ein-sicheres-Testpasswort-123!',
});
assert(response.status === 201, 'Die Ersteinrichtung muss HTTP 201 liefern.');
assert(response.payload.data.user.role === 'admin', 'Der erste Benutzer muss Administrator sein.');
assert(response.payload.data.path.startsWith('/workspaces/'), 'Die Ersteinrichtung muss eine Startseite liefern.');
csrfToken = response.payload.data.csrfToken;

const workspaceId = response.payload.data.workspace.id;
const pageId = response.payload.data.page.id;
let page = response.payload.data.page;

response = await request('GET', '/api/v1/workspaces');
assert(response.status === 200, 'Die Workspace-Liste muss abrufbar sein.');
assert(response.payload.data.workspaces.length === 1, 'Die Ersteinrichtung muss genau einen Workspace anlegen.');

response = await request(
    'POST',
    `/api/v1/workspaces/${workspaceId}/pages/${pageId}/block-ids`,
    {}
);
assert(response.status === 201, 'Eine Block-ID muss über die API erzeugt werden können.');
const blockId = response.payload.data.blockId;
assert(/^[a-f0-9]{64}$/.test(blockId), 'Die API muss eine gültige Block-ID liefern.');

page.blocks.push({
    id: blockId,
    type: 'markdown',
    content: '**API-Smoke-Test**',
    settings: { editorMode: 'split' },
    meta: {},
});

response = await request(
    'PATCH',
    `/api/v1/workspaces/${workspaceId}/pages/${pageId}/draft`,
    {
        baseDraftRevision: 0,
        page,
    }
);
assert(response.status === 200, 'Der Entwurf muss über die API gespeichert werden können.');
assert(response.payload.data.draftRevision === 1, 'Die erste Entwurfsrevision muss 1 sein.');

response = await request(
    'POST',
    `/api/v1/workspaces/${workspaceId}/pages/${pageId}/versions`,
    {
        baseDraftRevision: 1,
        message: 'HTTP-Smoke-Test',
    }
);
assert(response.status === 201, 'Eine dauerhafte Version muss über die API erzeugt werden können.');
assert(response.payload.data.publishedRevision === 2, 'Die gespeicherte Version muss Revision 2 sein.');

response = await request('GET', `/api/v1/workspaces/${workspaceId}/pages/${pageId}/blocks?type=markdown`);
assert(response.status === 200, 'Die Block-API muss erreichbar sein.');
assert(response.payload.data.blocks.length === 1, 'Der Markdown-Filter muss den gespeicherten Block liefern.');

console.log(`OK – ${assertions} HTTP-Assertions erfolgreich.`);
