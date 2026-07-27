(() => {
    'use strict';

    const app = document.querySelector('#app');

    const state = {
        session: null,
        csrfToken: '',
        workspaces: [],
        workspace: null,
        pageState: null,
        route: null,
        dirty: false,
        changeCounter: 0,
        savingPromise: null,
        saveAgain: false,
        debounceTimer: null,
        maxSaveTimer: null,
        dirtySince: null,
        metadataPromise: null,
        draggedIndex: null,
        cutBlock: null,
        collapsedBlocks: new Set(),
        collapsedTreePages: new Set(),
    };

    class ApiError extends Error {
        constructor(message, status, code, details = {}) {
            super(message);
            this.name = 'ApiError';
            this.status = status;
            this.code = code;
            this.details = details;
        }
    }

    async function api(path, options = {}) {
        const request = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(options.headers || {}),
            },
        };

        if (options.body !== undefined) {
            request.headers['Content-Type'] = 'application/json';
            request.body = JSON.stringify(options.body);
        }

        if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method.toUpperCase())) {
            request.headers['X-CSRF-Token'] = state.csrfToken;
        }

        const response = await fetch(path, request);
        const contentType = response.headers.get('content-type') || '';
        let payload = null;

        if (contentType.includes('application/json')) {
            payload = await response.json();
        }

        if (!response.ok || !payload?.ok) {
            const error = payload?.error || {};
            throw new ApiError(
                error.message || `HTTP ${response.status}`,
                response.status,
                error.code || 'HTTP_ERROR',
                error.details || {}
            );
        }

        return payload.data;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function clone(value) {
        return typeof structuredClone === 'function'
            ? structuredClone(value)
            : JSON.parse(JSON.stringify(value));
    }

    function formatDate(value, includeTime = true) {
        if (!value) return '–';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat('de-AT', {
            dateStyle: 'medium',
            ...(includeTime ? { timeStyle: 'short' } : {}),
        }).format(date);
    }

    function parseRoute(pathname = window.location.pathname) {
        let match = pathname.match(
            /^\/editor\/workspaces\/([0-9]+)\/pages\/([0-9]+)\/blocks\/([a-f0-9]{64})\/?$/
        );
        if (match) {
            return {
                type: 'block',
                workspaceId: Number(match[1]),
                pageId: Number(match[2]),
                blockId: match[3],
            };
        }

        match = pathname.match(/^\/workspaces\/([0-9]+)\/pages\/([0-9]+)\/?$/);
        if (match) {
            return {
                type: 'page',
                workspaceId: Number(match[1]),
                pageId: Number(match[2]),
                blockId: null,
            };
        }

        match = pathname.match(/^\/workspaces\/([0-9]+)\/?$/);
        if (match) {
            return {
                type: 'workspace',
                workspaceId: Number(match[1]),
                pageId: null,
                blockId: null,
            };
        }

        return { type: 'home', workspaceId: null, pageId: null, blockId: null };
    }

    function pagePath(workspaceId, pageId) {
        return `/workspaces/${workspaceId}/pages/${pageId}`;
    }

    async function navigate(path, replace = false) {
        if (state.metadataPromise) {
            await state.metadataPromise;
        }
        if (state.pageState && (state.dirty || state.savingPromise)) {
            try {
                await flushDraft();
            } catch {
                return;
            }
        }

        if (replace) {
            history.replaceState({}, '', path);
        } else {
            history.pushState({}, '', path);
        }
        await loadAuthenticatedRoute().catch(handleFatal);
    }

    async function boot() {
        try {
            const session = await api('/api/session');
            state.session = session;
            state.csrfToken = session.csrfToken;

            if (!session.configured) {
                renderSetup();
                return;
            }

            if (!session.authenticated) {
                renderLogin();
                return;
            }

            await startAuthenticatedApp();
        } catch (error) {
            handleFatal(error);
        }
    }

    function renderSetup() {
        document.title = 'Ersteinrichtung · BlockKnowledgeBase';
        app.innerHTML = `
            <main class="auth-layout">
                <section class="auth-panel auth-intro">
                    <div class="brand-lockup">
                        <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
                        <span>BlockKnowledgeBase</span>
                    </div>
                    <p class="eyebrow">Ersteinrichtung</p>
                    <h1>Wissen, Aufgaben und Projekte – als Seiten und Blöcke.</h1>
                    <p class="auth-copy">
                        Lege den ersten Administrator an. Danach entstehen automatisch der
                        Workspace „Privat“ und eine leere Willkommensseite.
                    </p>
                    <div class="principle-list">
                        <span><b>01</b> Dauerhaft dateibasierte JSON-Daten</span>
                        <span><b>02</b> Keine Ordnerobjekte, alles ist eine Seite</span>
                        <span><b>03</b> Stabile IDs und nachvollziehbare Revisionen</span>
                    </div>
                </section>
                <section class="auth-panel auth-form-panel">
                    <form id="setup-form" class="auth-form">
                        <div>
                            <p class="eyebrow">Lokaler Administrator</p>
                            <h2>Arbeitsbereich einrichten</h2>
                        </div>
                        <label>
                            Anzeigename
                            <input name="displayName" autocomplete="name" required maxlength="120" placeholder="Michael">
                        </label>
                        <label>
                            Benutzername
                            <input name="username" autocomplete="username" required minlength="3" maxlength="64" placeholder="michael">
                        </label>
                        <label>
                            Passwort
                            <input name="password" type="password" autocomplete="new-password" required minlength="12">
                            <small>Mindestens 12 Zeichen</small>
                        </label>
                        <label>
                            Passwort wiederholen
                            <input name="passwordRepeat" type="password" autocomplete="new-password" required minlength="12">
                        </label>
                        <p id="auth-error" class="form-error" role="alert"></p>
                        <button class="button button-primary button-large" type="submit">
                            BlockKnowledgeBase einrichten
                        </button>
                    </form>
                </section>
            </main>
        `;

        document.querySelector('#setup-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const errorElement = form.querySelector('#auth-error');
            const button = form.querySelector('button[type="submit"]');
            const values = Object.fromEntries(new FormData(form));

            if (values.password !== values.passwordRepeat) {
                errorElement.textContent = 'Die beiden Passwörter stimmen nicht überein.';
                return;
            }

            button.disabled = true;
            button.textContent = 'Wird eingerichtet …';
            errorElement.textContent = '';

            try {
                const result = await api('/api/setup', {
                    method: 'POST',
                    body: {
                        displayName: values.displayName,
                        username: values.username,
                        password: values.password,
                    },
                });
                state.session = {
                    configured: true,
                    authenticated: true,
                    user: result.user,
                };
                state.csrfToken = result.csrfToken;
                history.replaceState({}, '', result.path);
                await startAuthenticatedApp();
            } catch (error) {
                errorElement.textContent = friendlyError(error);
                button.disabled = false;
                button.textContent = 'BlockKnowledgeBase einrichten';
            }
        });
    }

    function renderLogin() {
        document.title = 'Anmelden · BlockKnowledgeBase';
        app.innerHTML = `
            <main class="auth-layout">
                <section class="auth-panel auth-intro">
                    <div class="brand-lockup">
                        <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
                        <span>BlockKnowledgeBase</span>
                    </div>
                    <p class="eyebrow">Willkommen zurück</p>
                    <h1>Dein Arbeitswissen bleibt lesbar, portabel und unter deiner Kontrolle.</h1>
                    <p class="auth-copy">
                        Melde dich an, um deine Workspaces, Seiten, Entwürfe und Revisionen zu öffnen.
                    </p>
                </section>
                <section class="auth-panel auth-form-panel">
                    <form id="login-form" class="auth-form">
                        <div>
                            <p class="eyebrow">Anmeldung</p>
                            <h2>Arbeitsbereich öffnen</h2>
                        </div>
                        <label>
                            Benutzername
                            <input name="username" autocomplete="username" required autofocus>
                        </label>
                        <label>
                            Passwort
                            <input name="password" type="password" autocomplete="current-password" required>
                        </label>
                        <p id="auth-error" class="form-error" role="alert"></p>
                        <button class="button button-primary button-large" type="submit">Anmelden</button>
                    </form>
                </section>
            </main>
        `;

        document.querySelector('#login-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const button = form.querySelector('button[type="submit"]');
            const errorElement = form.querySelector('#auth-error');
            const values = Object.fromEntries(new FormData(form));
            button.disabled = true;
            button.textContent = 'Anmeldung läuft …';
            errorElement.textContent = '';

            try {
                const result = await api('/api/login', {
                    method: 'POST',
                    body: {
                        username: values.username,
                        password: values.password,
                    },
                });
                state.session.authenticated = true;
                state.session.user = result.user;
                state.csrfToken = result.csrfToken;
                await startAuthenticatedApp();
            } catch (error) {
                errorElement.textContent = friendlyError(error);
                button.disabled = false;
                button.textContent = 'Anmelden';
            }
        });
    }

    async function startAuthenticatedApp() {
        renderShell();
        await refreshWorkspaces();
        await loadAuthenticatedRoute();
    }

    function renderShell() {
        const user = state.session.user;
        app.innerHTML = `
            <div class="app-shell">
                <header class="topbar">
                    <button class="icon-button mobile-only" id="sidebar-toggle" aria-label="Navigation öffnen">☰</button>
                    <button class="brand-lockup brand-button" id="brand-home" type="button" aria-label="Zur Startseite">
                        <span class="brand-mark brand-mark-small" aria-hidden="true"><span></span><span></span><span></span></span>
                        <span>BlockKnowledgeBase</span>
                    </button>
                    <div class="topbar-spacer"></div>
                    <span class="user-chip" title="${escapeHtml(user.username)}">
                        ${escapeHtml(initials(user.displayName))}
                    </span>
                    <button class="button button-quiet" id="logout-button" type="button">Abmelden</button>
                </header>
                <div class="workspace-layout">
                    <aside class="sidebar" id="sidebar">
                        <div class="workspace-switcher">
                            <label for="workspace-select">Workspace</label>
                            <div class="workspace-select-row">
                                <select id="workspace-select" aria-label="Workspace auswählen"></select>
                                <button class="icon-button" id="new-workspace" type="button" title="Workspace erstellen" aria-label="Workspace erstellen">＋</button>
                            </div>
                        </div>
                        <div class="sidebar-section-header">
                            <span>Seiten</span>
                            <button class="icon-button icon-button-small" id="new-root-page" type="button" title="Seite erstellen" aria-label="Seite erstellen">＋</button>
                        </div>
                        <nav id="page-tree" class="page-tree" aria-label="Seiten"></nav>
                        <div class="sidebar-footer">
                            <span>Dateibasiert</span>
                            <span class="status-dot" aria-hidden="true"></span>
                        </div>
                    </aside>
                    <main class="main-content" id="main-content" tabindex="-1"></main>
                </div>
            </div>
            <div class="modal-layer" id="modal-layer"></div>
            <div class="toast-region" id="toast-region" aria-live="polite"></div>
        `;

        document.querySelector('#brand-home').addEventListener('click', () => navigate('/'));
        document.querySelector('#logout-button').addEventListener('click', logout);
        document.querySelector('#sidebar-toggle').addEventListener('click', () => {
            document.querySelector('#sidebar').classList.toggle('sidebar-open');
        });
        document.querySelector('#new-workspace').addEventListener('click', createWorkspace);
        document.querySelector('#new-root-page').addEventListener('click', () => createPage(null));
        document.querySelector('#workspace-select').addEventListener('change', (event) => {
            const workspaceId = Number(event.target.value);
            localStorage.setItem('bkb.lastWorkspaceId', String(workspaceId));
            navigate(`/workspaces/${workspaceId}`);
        });

        window.addEventListener('popstate', handlePopstate);
        window.addEventListener('beforeunload', (event) => {
            if (!state.dirty) return;
            event.preventDefault();
            event.returnValue = '';
        });
        document.addEventListener('keydown', globalKeyboardShortcuts);
        document.addEventListener('click', closeOpenMenus);
    }

    async function refreshWorkspaces() {
        const result = await api('/api/v1/workspaces');
        state.workspaces = result.workspaces;
        renderWorkspaceSelect();
    }

    function renderWorkspaceSelect() {
        const select = document.querySelector('#workspace-select');
        if (!select) return;

        select.innerHTML = state.workspaces
            .map((workspace) => `
                <option value="${workspace.id}">
                    ${escapeHtml(workspace.title)} · ${workspace.pageCount}
                </option>
            `)
            .join('');

        if (state.workspace) {
            select.value = String(state.workspace.id);
        }
    }

    async function loadAuthenticatedRoute() {
        clearAutosaveTimers();
        state.route = parseRoute();
        state.pageState = null;
        state.dirty = false;
        state.changeCounter = 0;
        state.dirtySince = null;
        state.cutBlock = null;

        if (state.workspaces.length === 0) {
            state.workspace = null;
            renderWorkspaceSelect();
            renderPageTree();
            renderNoWorkspace();
            return;
        }

        let workspaceId = state.route.workspaceId;
        if (!workspaceId || !state.workspaces.some((workspace) => workspace.id === workspaceId)) {
            const remembered = Number(localStorage.getItem('bkb.lastWorkspaceId'));
            workspaceId = state.workspaces.some((workspace) => workspace.id === remembered)
                ? remembered
                : state.workspaces[0].id;
        }

        try {
            const workspaceResult = await api(`/api/v1/workspaces/${workspaceId}`);
            state.workspace = workspaceResult.workspace;
            localStorage.setItem('bkb.lastWorkspaceId', String(workspaceId));
            renderWorkspaceSelect();
            renderPageTree();

            let pageId = state.route.pageId;
            if (!pageId && state.workspace.pageIndex.rootPageIds.length > 0) {
                pageId = Number(state.workspace.pageIndex.rootPageIds[0]);
                const path = pagePath(workspaceId, pageId);
                history.replaceState({}, '', path);
                state.route = parseRoute(path);
            }

            if (!pageId) {
                renderEmptyWorkspace();
                return;
            }

            const pageState = await api(`/api/v1/workspaces/${workspaceId}/pages/${pageId}`);
            state.pageState = pageState;
            state.workspace = pageState.workspace;
            state.collapsedBlocks = readCollapsedBlocks(pageId);
            renderWorkspaceSelect();
            renderPageTree();
            renderEditor();
        } catch (error) {
            if (error instanceof ApiError && error.code === 'PAGE_MOVED' && error.details.path) {
                history.replaceState({}, '', error.details.path);
                await refreshWorkspaces();
                await loadAuthenticatedRoute();
                return;
            }
            if (error instanceof ApiError && error.code === 'WORKSPACE_NOT_FOUND') {
                await refreshWorkspaces();
                navigate('/', true);
                return;
            }
            throw error;
        }
    }

    function renderPageTree() {
        const tree = document.querySelector('#page-tree');
        if (!tree) return;
        tree.innerHTML = '';

        if (!state.workspace) {
            tree.innerHTML = '<p class="tree-empty">Noch kein Workspace</p>';
            return;
        }

        const roots = state.workspace.pageIndex.rootPageIds || [];
        if (roots.length === 0) {
            tree.innerHTML = '<p class="tree-empty">Noch keine Seiten</p>';
            return;
        }

        tree.append(buildTreeList(roots, 0));
    }

    function buildTreeList(ids, depth) {
        const list = document.createElement('ul');
        list.className = 'tree-list';

        ids.forEach((rawId) => {
            const pageId = Number(rawId);
            const entry = state.workspace.pageIndex.pages[String(pageId)];
            if (!entry) return;

            const item = document.createElement('li');
            const row = document.createElement('div');
            row.className = 'tree-row';
            row.style.setProperty('--tree-depth', depth);

            const children = entry.children || [];
            const toggle = document.createElement('button');
            toggle.className = 'tree-toggle';
            toggle.type = 'button';
            toggle.setAttribute('aria-label', children.length ? 'Unterseiten ein- oder ausklappen' : 'Keine Unterseiten');
            toggle.disabled = children.length === 0;
            toggle.textContent = children.length
                ? (state.collapsedTreePages.has(pageId) ? '›' : '⌄')
                : '·';
            toggle.addEventListener('click', (event) => {
                event.stopPropagation();
                if (state.collapsedTreePages.has(pageId)) {
                    state.collapsedTreePages.delete(pageId);
                } else {
                    state.collapsedTreePages.add(pageId);
                }
                renderPageTree();
            });

            const link = document.createElement('a');
            link.href = pagePath(state.workspace.id, pageId);
            link.className = 'tree-link';
            if (state.pageState?.page?.id === pageId) {
                link.classList.add('tree-link-active');
                link.setAttribute('aria-current', 'page');
            }
            link.textContent = entry.title;
            link.addEventListener('click', (event) => {
                event.preventDefault();
                document.querySelector('#sidebar')?.classList.remove('sidebar-open');
                navigate(link.getAttribute('href'));
            });

            const childButton = document.createElement('button');
            childButton.className = 'tree-add';
            childButton.type = 'button';
            childButton.textContent = '＋';
            childButton.title = `Unterseite von „${entry.title}“ erstellen`;
            childButton.setAttribute('aria-label', `Unterseite von ${entry.title} erstellen`);
            childButton.addEventListener('click', (event) => {
                event.stopPropagation();
                createPage(pageId);
            });

            row.append(toggle, link, childButton);
            item.append(row);
            if (children.length && !state.collapsedTreePages.has(pageId)) {
                item.append(buildTreeList(children, depth + 1));
            }
            list.append(item);
        });

        return list;
    }

    function renderNoWorkspace() {
        document.title = 'BlockKnowledgeBase';
        document.querySelector('#main-content').innerHTML = `
            <section class="empty-state">
                <div class="empty-symbol">BKB</div>
                <p class="eyebrow">Erster Schritt</p>
                <h1>Lege deinen ersten Workspace an.</h1>
                <p>Jeder Workspace erhält einen eigenen Ordner und einen unabhängigen Seitenindex.</p>
                <button class="button button-primary" id="empty-new-workspace" type="button">Workspace erstellen</button>
            </section>
        `;
        document.querySelector('#empty-new-workspace').addEventListener('click', createWorkspace);
    }

    function renderEmptyWorkspace() {
        document.title = `${state.workspace.title} · BlockKnowledgeBase`;
        document.querySelector('#main-content').innerHTML = `
            <section class="empty-state">
                <div class="empty-symbol">＋</div>
                <p class="eyebrow">${escapeHtml(state.workspace.title)}</p>
                <h1>Dieser Workspace ist noch leer.</h1>
                <p>Erstelle eine Wurzelseite. Sie kann Inhalt und später beliebig viele Unterseiten enthalten.</p>
                <button class="button button-primary" id="empty-new-page" type="button">Erste Seite erstellen</button>
            </section>
        `;
        document.querySelector('#empty-new-page').addEventListener('click', () => createPage(null));
    }

    function renderEditor() {
        const main = document.querySelector('#main-content');
        const page = state.pageState.page;
        const standalone = state.route.type === 'block';
        const focusedIndex = standalone
            ? page.blocks.findIndex((block) => block.id === state.route.blockId)
            : -1;

        document.querySelector('.app-shell')?.classList.toggle('focus-mode', standalone);
        document.title = standalone
            ? `${page.title} · Block · BKB`
            : `${page.title} · BlockKnowledgeBase`;

        if (standalone && focusedIndex < 0) {
            main.innerHTML = `
                <section class="empty-state">
                    <div class="empty-symbol">?</div>
                    <h1>Block nicht gefunden</h1>
                    <p>Der Block wurde möglicherweise gelöscht oder besitzt eine andere ID.</p>
                    <a class="button button-primary" href="${pagePath(state.workspace.id, page.id)}">Zur Seite</a>
                </section>
            `;
            return;
        }

        const breadcrumbs = breadcrumbPath(page.id)
            .map((part) => `<span>${escapeHtml(part.title)}</span>`)
            .join('<b aria-hidden="true">/</b>');
        const blocks = standalone ? [page.blocks[focusedIndex]] : page.blocks;

        main.innerHTML = `
            <div class="editor-page ${standalone ? 'editor-page-focused' : ''}">
                <div class="page-breadcrumbs">
                    ${standalone
                        ? `<a href="${pagePath(state.workspace.id, page.id)}" data-nav>← Zur Seite</a><b aria-hidden="true">/</b><span>Einzelblock</span>`
                        : breadcrumbs}
                </div>
                <header class="page-header">
                    <div class="page-title-wrap">
                        <input
                            class="page-title-input"
                            id="page-title"
                            value="${escapeHtml(page.title)}"
                            maxlength="180"
                            aria-label="Seitentitel"
                        >
                        <div class="page-meta">
                            <span>Version ${state.pageState.publishedRevision}</span>
                            <span aria-hidden="true">·</span>
                            <span id="save-status">${saveStatusText()}</span>
                        </div>
                    </div>
                    <div class="page-actions">
                        ${standalone ? '' : `
                            <button class="button button-quiet" id="history-button" type="button">Versionen</button>
                            <button class="button button-quiet" id="move-page-button" type="button">Verschieben</button>
                        `}
                        <button class="button button-primary" id="save-version-button" type="button">Version speichern</button>
                        ${standalone ? '' : `
                            <button class="icon-button" id="page-menu-button" type="button" aria-label="Seitenmenü" title="Seitenmenü">⋯</button>
                            <div class="popover page-menu" id="page-menu" hidden>
                                <button type="button" data-page-action="new-child">Unterseite erstellen</button>
                                <button type="button" data-page-action="rename">Umbenennen</button>
                                <button type="button" data-page-action="discard">Entwurf verwerfen</button>
                                <hr>
                                <button type="button" class="danger-text" data-page-action="delete">Seite mit Unterseiten löschen</button>
                            </div>
                        `}
                    </div>
                </header>
                ${standalone ? '' : `
                    <div class="page-properties">
                        <label>
                            <span>Labels</span>
                            <input id="page-labels" value="${escapeHtml((page.labels || []).join(', '))}" placeholder="z. B. projekt, esp32">
                        </label>
                        <span class="page-id">Page-ID ${page.id}</span>
                    </div>
                `}
                <section class="block-stack" id="block-stack">
                    ${standalone ? '' : insertButtonHtml(0)}
                    ${blocks.map((block, localIndex) => {
                        const index = standalone ? focusedIndex : localIndex;
                        return blockHtml(block, index, standalone)
                            + (standalone ? '' : insertButtonHtml(index + 1));
                    }).join('')}
                    ${blocks.length === 0 && !standalone ? `
                        <div class="blank-page-hint">
                            <p>Diese Seite hat noch keine Blöcke.</p>
                            <span>Nutze das Plus, um mit einer Überschrift, Raw Text oder Markdown zu beginnen.</span>
                        </div>
                    ` : ''}
                </section>
            </div>
        `;

        bindEditorEvents();
        updateMarkdownPreviews();
    }

    function blockHtml(block, index, standalone) {
        const collapsed = !standalone && state.collapsedBlocks.has(block.id);
        const summary = blockSummary(block);
        const typeName = {
            heading: 'Überschrift',
            raw_text: 'Raw Text',
            markdown: 'Markdown',
        }[block.type] || block.type;

        return `
            <article class="block-card ${collapsed ? 'block-collapsed' : ''}" data-block-id="${block.id}" data-index="${index}">
                <header class="block-header">
                    <button class="block-move" type="button" draggable="true" aria-label="Block verschieben" title="Block verschieben">⠿</button>
                    <button class="block-arrow" type="button" data-action="up" aria-label="Block nach oben" title="Nach oben" ${index === 0 ? 'disabled' : ''}>↑</button>
                    <button class="block-arrow" type="button" data-action="down" aria-label="Block nach unten" title="Nach unten" ${index === state.pageState.page.blocks.length - 1 ? 'disabled' : ''}>↓</button>
                    <div class="block-heading">
                        <strong>${escapeHtml(typeName)}</strong>
                        <span>${escapeHtml(summary)}</span>
                    </div>
                    <button class="block-collapse" type="button" data-action="collapse" aria-label="${collapsed ? 'Block ausklappen' : 'Block einklappen'}" title="${collapsed ? 'Ausklappen' : 'Einklappen'}">${collapsed ? '+' : '−'}</button>
                    <button class="block-more" type="button" data-action="menu" aria-label="Blockmenü" title="Blockmenü">⋯</button>
                    <div class="popover block-menu" hidden>
                        <button type="button" data-menu-action="settings">Einstellungen</button>
                        <button type="button" data-menu-action="open">In neuem Tab bearbeiten</button>
                        <button type="button" data-menu-action="duplicate">Duplizieren</button>
                        <button type="button" data-menu-action="cut">Ausschneiden</button>
                        <button type="button" data-menu-action="copy-id">Block-ID kopieren</button>
                        <button type="button" data-menu-action="json">Als JSON anzeigen</button>
                        <hr>
                        <button type="button" class="danger-text" data-menu-action="delete">Löschen</button>
                    </div>
                </header>
                <div class="block-body">
                    ${blockEditorHtml(block)}
                </div>
            </article>
        `;
    }

    function blockEditorHtml(block) {
        if (block.type === 'heading') {
            return `
                <div class="heading-editor">
                    <label class="compact-field">
                        <span>Ebene</span>
                        <select data-setting="level">
                            ${[1, 2, 3, 4, 5, 6].map((level) => `
                                <option value="${level}" ${Number(block.settings.level) === level ? 'selected' : ''}>H${level}</option>
                            `).join('')}
                        </select>
                    </label>
                    <input
                        class="heading-input heading-level-${Number(block.settings.level) || 1}"
                        data-field="content"
                        value="${escapeHtml(block.content)}"
                        placeholder="Überschrift"
                        maxlength="1000000"
                    >
                    <label class="check-field">
                        <input type="checkbox" data-setting="includeInToc" ${block.settings.includeInToc !== false ? 'checked' : ''}>
                        Im Inhaltsverzeichnis
                    </label>
                </div>
            `;
        }

        if (block.type === 'raw_text') {
            return `
                <div class="text-editor">
                    <textarea
                        class="raw-textarea ${block.settings.wrap === false ? 'no-wrap' : ''}"
                        data-field="content"
                        spellcheck="false"
                        placeholder="Text wird exakt und ohne Interpretation gespeichert."
                    >${escapeHtml(block.content)}</textarea>
                    <label class="check-field block-option-row">
                        <input type="checkbox" data-setting="wrap" ${block.settings.wrap !== false ? 'checked' : ''}>
                        Lange Zeilen umbrechen
                    </label>
                </div>
            `;
        }

        const mode = block.settings.editorMode || 'split';
        return `
            <div class="markdown-editor markdown-mode-${escapeHtml(mode)}" data-markdown-editor>
                <div class="markdown-tabs" role="tablist" aria-label="Markdown-Ansicht">
                    <button type="button" data-markdown-mode="raw" class="${mode === 'raw' ? 'active' : ''}">Bearbeiten</button>
                    <button type="button" data-markdown-mode="split" class="${mode === 'split' ? 'active' : ''}">Geteilt</button>
                    <button type="button" data-markdown-mode="preview" class="${mode === 'preview' ? 'active' : ''}">Vorschau</button>
                </div>
                <div class="markdown-toolbar" aria-label="Markdown-Werkzeuge">
                    <button type="button" data-markdown-wrap="**|**" title="Fett"><b>B</b></button>
                    <button type="button" data-markdown-wrap="_|_" title="Kursiv"><i>I</i></button>
                    <button type="button" data-markdown-wrap="\`|\`" title="Inline-Code">&lt;/&gt;</button>
                    <button type="button" data-markdown-prefix="- " title="Liste">• Liste</button>
                    <button type="button" data-markdown-prefix="> " title="Zitat">❯ Zitat</button>
                    <button type="button" data-markdown-link title="Link">↗ Link</button>
                </div>
                <div class="markdown-panes">
                    <textarea
                        class="markdown-source"
                        data-field="content"
                        spellcheck="true"
                        placeholder="Markdown schreiben …"
                    >${escapeHtml(block.content)}</textarea>
                    <div class="markdown-preview" data-markdown-preview></div>
                </div>
            </div>
        `;
    }

    function insertButtonHtml(index) {
        return `
            <div class="insert-row" data-drop-index="${index}">
                <span></span>
                <button class="insert-button" type="button" data-insert-index="${index}" aria-label="Block an Position ${index + 1} einfügen" title="Block einfügen">＋</button>
                <span></span>
            </div>
        `;
    }

    function blockSummary(block) {
        const content = String(block.content || '').replace(/\s+/g, ' ').trim();
        if (content) {
            return content.length > 58 ? `${content.slice(0, 58)}…` : content;
        }
        return block.type === 'raw_text' ? 'Leer · ohne Interpretation' : 'Leer';
    }

    function bindEditorEvents() {
        document.querySelectorAll('[data-nav]').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                navigate(link.getAttribute('href'));
            });
        });

        const titleInput = document.querySelector('#page-title');
        titleInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                titleInput.blur();
            }
            if (event.key === 'Escape') {
                titleInput.value = state.pageState.page.title;
                titleInput.blur();
            }
        });
        titleInput?.addEventListener('change', (event) => {
            const promise = renameCurrentPage(event);
            state.metadataPromise = promise;
            promise.finally(() => {
                if (state.metadataPromise === promise) state.metadataPromise = null;
            });
        });

        document.querySelector('#page-labels')?.addEventListener('input', (event) => {
            state.pageState.page.labels = event.target.value
                .split(',')
                .map((label) => label.trim())
                .filter(Boolean);
            markDirty();
        });

        document.querySelector('#save-version-button')?.addEventListener('click', saveVersion);
        document.querySelector('#history-button')?.addEventListener('click', showHistory);
        document.querySelector('#move-page-button')?.addEventListener('click', moveCurrentPage);
        document.querySelector('#page-menu-button')?.addEventListener('click', (event) => {
            event.stopPropagation();
            const menu = document.querySelector('#page-menu');
            menu.hidden = !menu.hidden;
        });
        document.querySelectorAll('[data-page-action]').forEach((button) => {
            button.addEventListener('click', () => handlePageAction(button.dataset.pageAction));
        });

        document.querySelectorAll('.insert-button').forEach((button) => {
            button.addEventListener('click', () => insertBlock(Number(button.dataset.insertIndex)));
        });

        document.querySelectorAll('.insert-row').forEach((row) => {
            row.addEventListener('dragover', (event) => {
                if (state.draggedIndex === null) return;
                event.preventDefault();
                row.classList.add('drop-target');
            });
            row.addEventListener('dragleave', () => row.classList.remove('drop-target'));
            row.addEventListener('drop', (event) => {
                event.preventDefault();
                row.classList.remove('drop-target');
                if (state.draggedIndex === null) return;
                moveBlock(state.draggedIndex, Number(row.dataset.dropIndex));
                state.draggedIndex = null;
            });
        });

        document.querySelectorAll('.block-card').forEach((card) => bindBlockEvents(card));
    }

    function bindBlockEvents(card) {
        const index = Number(card.dataset.index);
        const block = state.pageState.page.blocks[index];
        if (!block) return;

        card.querySelectorAll('[data-field="content"]').forEach((field) => {
            field.addEventListener('input', () => {
                block.content = field.value;
                const summary = card.querySelector('.block-heading span');
                if (summary) summary.textContent = blockSummary(block);
                if (block.type === 'markdown') {
                    const preview = card.querySelector('[data-markdown-preview]');
                    if (preview) preview.innerHTML = renderMarkdown(block.content);
                }
                markDirty();
            });
        });

        card.querySelectorAll('[data-setting]').forEach((control) => {
            control.addEventListener('change', () => {
                const key = control.dataset.setting;
                block.settings[key] = control.type === 'checkbox'
                    ? control.checked
                    : (key === 'level' ? Number(control.value) : control.value);

                if (key === 'wrap') {
                    card.querySelector('.raw-textarea')?.classList.toggle('no-wrap', !control.checked);
                }
                if (key === 'level') {
                    const input = card.querySelector('.heading-input');
                    input.className = `heading-input heading-level-${control.value}`;
                }
                markDirty();
            });
        });

        card.querySelector('[data-action="up"]')?.addEventListener('click', () => {
            if (index > 0) moveBlock(index, index - 1);
        });
        card.querySelector('[data-action="down"]')?.addEventListener('click', () => {
            if (index < state.pageState.page.blocks.length - 1) moveBlock(index, index + 2);
        });
        card.querySelector('[data-action="collapse"]')?.addEventListener('click', () => toggleBlock(block.id));
        card.querySelector('[data-action="menu"]')?.addEventListener('click', (event) => {
            event.stopPropagation();
            const menu = card.querySelector('.block-menu');
            document.querySelectorAll('.popover').forEach((other) => {
                if (other !== menu) other.hidden = true;
            });
            menu.hidden = !menu.hidden;
        });

        card.querySelectorAll('[data-menu-action]').forEach((button) => {
            button.addEventListener('click', () => handleBlockMenu(button.dataset.menuAction, index, card));
        });

        const moveHandle = card.querySelector('.block-move');
        moveHandle?.addEventListener('dragstart', (event) => {
            state.draggedIndex = index;
            card.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', block.id);
        });
        moveHandle?.addEventListener('dragend', () => {
            state.draggedIndex = null;
            card.classList.remove('dragging');
            document.querySelectorAll('.drop-target').forEach((row) => row.classList.remove('drop-target'));
        });

        card.querySelectorAll('[data-markdown-mode]').forEach((button) => {
            button.addEventListener('click', () => {
                block.settings.editorMode = button.dataset.markdownMode;
                markDirty();
                renderEditor();
            });
        });
        card.querySelectorAll('[data-markdown-wrap]').forEach((button) => {
            button.addEventListener('click', () => {
                const [before, after] = button.dataset.markdownWrap.split('|');
                wrapTextareaSelection(card.querySelector('.markdown-source'), before, after);
            });
        });
        card.querySelectorAll('[data-markdown-prefix]').forEach((button) => {
            button.addEventListener('click', () => {
                prefixTextareaLines(card.querySelector('.markdown-source'), button.dataset.markdownPrefix);
            });
        });
        card.querySelector('[data-markdown-link]')?.addEventListener('click', () => {
            wrapTextareaSelection(card.querySelector('.markdown-source'), '[', '](https://)');
        });
    }

    function markDirty() {
        state.dirty = true;
        state.changeCounter += 1;
        if (!state.dirtySince) {
            state.dirtySince = Date.now();
            state.maxSaveTimer = window.setTimeout(() => saveDraft(), 15_000);
        }

        window.clearTimeout(state.debounceTimer);
        state.debounceTimer = window.setTimeout(() => saveDraft(), 2_000);
        setSaveStatus('Nicht gespeichert', 'dirty');
    }

    async function saveDraft(force = false) {
        if (state.savingPromise) {
            if (state.dirty || force) state.saveAgain = true;
            await state.savingPromise;
            if (state.saveAgain) {
                state.saveAgain = false;
                return saveDraft(force);
            }
            return state.pageState;
        }

        if (!state.dirty && !force) return state.pageState;
        if (!state.pageState) return null;

        clearAutosaveTimers();
        const saveGeneration = state.changeCounter;
        const pageSnapshot = clone(state.pageState.page);
        const baseDraftRevision = state.pageState.draftRevision;
        setSaveStatus('Wird gespeichert …', 'saving');

        state.savingPromise = api(
            `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/draft`,
            {
                method: 'PATCH',
                body: {
                    baseDraftRevision,
                    page: pageSnapshot,
                },
            }
        );

        try {
            const result = await state.savingPromise;
            if (state.changeCounter === saveGeneration) {
                state.pageState = result;
                state.workspace = result.workspace;
                state.dirty = false;
                state.dirtySince = null;
            } else {
                state.pageState.draftRevision = result.draftRevision;
                state.pageState.hasDraft = true;
                state.pageState.draftSavedAt = result.draftSavedAt;
                state.pageState.page.revision = result.publishedRevision;
                state.pageState.page.draftRevision = result.draftRevision;
                state.dirty = true;
                scheduleAutosaveAfterConcurrentChange();
            }
            setSaveStatus(`Automatisch gespeichert ${timeOnly(result.draftSavedAt)}`, 'saved');
            return result;
        } catch (error) {
            state.dirty = true;
            setSaveStatus('Speichern fehlgeschlagen', 'error');
            if (error instanceof ApiError && ['DRAFT_CONFLICT', 'BASE_REVISION_CONFLICT'].includes(error.code)) {
                await showConflict(error);
            } else {
                toast(friendlyError(error), { tone: 'error' });
            }
            throw error;
        } finally {
            state.savingPromise = null;
        }
    }

    function scheduleAutosaveAfterConcurrentChange() {
        window.clearTimeout(state.debounceTimer);
        state.debounceTimer = window.setTimeout(() => saveDraft(), 500);
        if (!state.maxSaveTimer) {
            state.maxSaveTimer = window.setTimeout(() => saveDraft(), 15_000);
        }
    }

    function clearAutosaveTimers() {
        window.clearTimeout(state.debounceTimer);
        window.clearTimeout(state.maxSaveTimer);
        state.debounceTimer = null;
        state.maxSaveTimer = null;
    }

    async function flushDraft() {
        if (state.savingPromise) {
            await state.savingPromise;
        }
        if (state.dirty) {
            await saveDraft(true);
        }
        return state.pageState;
    }

    async function settleMetadata() {
        document.querySelector('#page-title')?.blur();
        if (state.metadataPromise) {
            await state.metadataPromise;
        }
    }

    function setSaveStatus(text, status = '') {
        const element = document.querySelector('#save-status');
        if (!element) return;
        element.textContent = text;
        element.dataset.status = status;
    }

    function saveStatusText() {
        if (state.dirty) return 'Nicht gespeichert';
        if (state.pageState?.hasDraft) {
            return `Entwurf gespeichert ${timeOnly(state.pageState.draftSavedAt)}`;
        }
        return 'Alle Änderungen versioniert';
    }

    async function saveVersion() {
        try {
            await settleMetadata();
            await flushDraft();
            if (!state.pageState.hasDraft) {
                toast('Es gibt keine ungespeicherten Änderungen.');
                return;
            }

            const values = await formModal({
                title: 'Dauerhafte Version speichern',
                description: 'Der aktuelle Entwurf wird als unveränderlicher Snapshot in die Versionshistorie übernommen.',
                fields: `
                    <label>
                        Änderungsbeschreibung <span class="optional">(optional)</span>
                        <textarea name="message" rows="3" maxlength="500" placeholder="Was wurde geändert?"></textarea>
                    </label>
                `,
                submitLabel: 'Version speichern',
            });
            if (!values) return;

            setSaveStatus('Version wird gespeichert …', 'saving');
            const result = await api(
                `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/versions`,
                {
                    method: 'POST',
                    body: {
                        baseDraftRevision: state.pageState.draftRevision,
                        message: values.message || null,
                    },
                }
            );
            state.pageState = result;
            state.workspace = result.workspace;
            state.dirty = false;
            renderEditor();
            toast(`Version ${result.publishedRevision} wurde gespeichert.`, { tone: 'success' });
        } catch (error) {
            setSaveStatus('Version konnte nicht gespeichert werden', 'error');
            if (!(error instanceof ApiError && ['DRAFT_CONFLICT', 'BASE_REVISION_CONFLICT'].includes(error.code))) {
                toast(friendlyError(error), { tone: 'error' });
            }
        }
    }

    async function renameCurrentPage(event) {
        const input = event.currentTarget;
        const title = input.value.trim();
        if (!title || title === state.pageState.page.title) {
            input.value = state.pageState.page.title;
            return;
        }

        input.disabled = true;
        try {
            await flushDraft();
            const result = await api(
                `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}`,
                {
                    method: 'PATCH',
                    body: { title },
                }
            );
            state.pageState = result;
            state.workspace = result.workspace;
            state.dirty = false;
            await refreshWorkspaces();
            renderPageTree();
            renderEditor();
            toast('Seite wurde umbenannt.', { tone: 'success' });
        } catch (error) {
            input.disabled = false;
            input.value = state.pageState.page.title;
            toast(friendlyError(error), { tone: 'error' });
        }
    }

    function moveBlock(fromIndex, insertionIndex) {
        const blocks = state.pageState.page.blocks;
        if (fromIndex < 0 || fromIndex >= blocks.length) return;
        let target = Math.max(0, Math.min(insertionIndex, blocks.length));
        const [block] = blocks.splice(fromIndex, 1);
        if (fromIndex < target) target -= 1;
        blocks.splice(target, 0, block);
        markDirty();
        renderEditor();
    }

    function toggleBlock(blockId) {
        if (state.collapsedBlocks.has(blockId)) {
            state.collapsedBlocks.delete(blockId);
        } else {
            state.collapsedBlocks.add(blockId);
        }
        persistCollapsedBlocks();
        renderEditor();
    }

    async function insertBlock(index) {
        const choice = await choiceModal({
            title: 'Block einfügen',
            description: 'Wähle den Inhaltstyp für die neue Position.',
            choices: [
                { value: 'heading', label: 'Überschrift', detail: 'Strukturierte H1–H6-Überschrift', icon: 'H' },
                { value: 'raw_text', label: 'Raw Text', detail: 'Exakter Text ohne Interpretation', icon: 'T' },
                { value: 'markdown', label: 'Markdown', detail: 'Markdown-Quelle mit Vorschau', icon: 'M' },
                ...(state.cutBlock
                    ? [{ value: 'paste', label: 'Ausgeschnittenen Block einfügen', detail: blockSummary(state.cutBlock), icon: '↳' }]
                    : []),
            ],
        });
        if (!choice) return;

        if (choice === 'paste') {
            state.pageState.page.blocks.splice(index, 0, state.cutBlock);
            state.cutBlock = null;
            markDirty();
            renderEditor();
            return;
        }

        try {
            const result = await api(
                `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/block-ids`,
                { method: 'POST', body: {} }
            );
            const block = newBlock(choice, result.blockId);
            state.pageState.page.blocks.splice(index, 0, block);
            markDirty();
            renderEditor();
            requestAnimationFrame(() => {
                document.querySelector(`[data-block-id="${block.id}"] [data-field="content"]`)?.focus();
            });
        } catch (error) {
            toast(friendlyError(error), { tone: 'error' });
        }
    }

    function newBlock(type, id) {
        if (type === 'heading') {
            return {
                id,
                type,
                content: '',
                settings: { level: 1, includeInToc: true, anchor: null },
                meta: {},
            };
        }
        if (type === 'raw_text') {
            return {
                id,
                type,
                content: '',
                settings: { wrap: true },
                meta: {},
            };
        }
        return {
            id,
            type: 'markdown',
            content: '',
            settings: { editorMode: 'split' },
            meta: {},
        };
    }

    async function handleBlockMenu(action, index, card) {
        const block = state.pageState.page.blocks[index];
        card.querySelector('.block-menu').hidden = true;

        if (action === 'settings') {
            if (state.collapsedBlocks.has(block.id)) {
                state.collapsedBlocks.delete(block.id);
                persistCollapsedBlocks();
                renderEditor();
                requestAnimationFrame(() => {
                    document.querySelector(`[data-block-id="${block.id}"] [data-setting]`)?.focus();
                });
            } else {
                card.querySelector('[data-setting], [data-field="content"]')?.focus();
            }
            return;
        }

        if (action === 'open') {
            window.open(
                `/editor/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/blocks/${block.id}`,
                '_blank',
                'noopener'
            );
            return;
        }

        if (action === 'duplicate') {
            try {
                const result = await api(
                    `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/block-ids`,
                    { method: 'POST', body: {} }
                );
                const duplicate = clone(block);
                duplicate.id = result.blockId;
                duplicate.meta = {};
                state.pageState.page.blocks.splice(index + 1, 0, duplicate);
                markDirty();
                renderEditor();
            } catch (error) {
                toast(friendlyError(error), { tone: 'error' });
            }
            return;
        }

        if (action === 'cut') {
            state.cutBlock = clone(block);
            state.pageState.page.blocks.splice(index, 1);
            markDirty();
            renderEditor();
            toast('Block ausgeschnitten. Nutze ein Plus zum Einfügen.', {
                actionLabel: 'Rückgängig',
                onAction: () => {
                    state.pageState.page.blocks.splice(index, 0, state.cutBlock);
                    state.cutBlock = null;
                    markDirty();
                    renderEditor();
                },
            });
            return;
        }

        if (action === 'copy-id') {
            try {
                await navigator.clipboard.writeText(block.id);
                toast('Block-ID wurde kopiert.', { tone: 'success' });
            } catch {
                await infoModal('Block-ID', `<code class="copy-value">${block.id}</code>`);
            }
            return;
        }

        if (action === 'json') {
            await infoModal(
                'Block als JSON',
                `<pre class="json-view">${escapeHtml(JSON.stringify(block, null, 2))}</pre>`
            );
            return;
        }

        if (action === 'delete') {
            const confirmed = await confirmModal(
                'Block löschen?',
                `Der Block „${escapeHtml(blockSummary(block))}“ wird aus dem Entwurf entfernt.`,
                'Block löschen',
                true
            );
            if (!confirmed) return;

            const deleted = state.pageState.page.blocks.splice(index, 1)[0];
            markDirty();
            renderEditor();
            toast('Block gelöscht.', {
                actionLabel: 'Rückgängig',
                onAction: () => {
                    state.pageState.page.blocks.splice(index, 0, deleted);
                    markDirty();
                    renderEditor();
                },
            });
        }
    }

    async function handlePageAction(action) {
        document.querySelector('#page-menu').hidden = true;
        if (action === 'new-child') {
            await createPage(state.pageState.page.id);
        } else if (action === 'rename') {
            document.querySelector('#page-title')?.focus();
            document.querySelector('#page-title')?.select();
        } else if (action === 'discard') {
            await discardDraft();
        } else if (action === 'delete') {
            await deleteCurrentPage();
        }
    }

    async function createWorkspace() {
        const values = await formModal({
            title: 'Workspace erstellen',
            description: 'Der Workspace erhält einen eigenen Ordner und einen eigenen Hierarchie-Index.',
            fields: `
                <label>
                    Name
                    <input name="title" required maxlength="120" placeholder="z. B. Privat oder UTV Wien" autofocus>
                </label>
            `,
            submitLabel: 'Workspace erstellen',
        });
        if (!values) return;

        try {
            const result = await api('/api/v1/workspaces', {
                method: 'POST',
                body: { title: values.title },
            });
            await refreshWorkspaces();
            navigate(`/workspaces/${result.workspace.id}`);
            toast('Workspace wurde erstellt.', { tone: 'success' });
        } catch (error) {
            toast(friendlyError(error), { tone: 'error' });
        }
    }

    async function createPage(parentPageId) {
        if (!state.workspace) {
            await createWorkspace();
            return;
        }

        const parentEntry = parentPageId
            ? state.workspace.pageIndex.pages[String(parentPageId)]
            : null;
        const values = await formModal({
            title: parentEntry ? 'Unterseite erstellen' : 'Seite erstellen',
            description: parentEntry
                ? `Die neue Seite wird unter „${escapeHtml(parentEntry.title)}“ eingeordnet.`
                : `Die neue Seite wird auf der obersten Ebene von „${escapeHtml(state.workspace.title)}“ eingeordnet.`,
            fields: `
                <label>
                    Seitentitel
                    <input name="title" required maxlength="180" placeholder="Titel der Seite" autofocus>
                </label>
            `,
            submitLabel: 'Seite erstellen',
        });
        if (!values) return;

        try {
            const result = await api(`/api/v1/workspaces/${state.workspace.id}/pages`, {
                method: 'POST',
                body: {
                    title: values.title,
                    parentPageId,
                },
            });
            await refreshWorkspaces();
            navigate(result.path);
            toast('Seite wurde erstellt.', { tone: 'success' });
        } catch (error) {
            toast(friendlyError(error), { tone: 'error' });
        }
    }

    async function moveCurrentPage() {
        try {
            await settleMetadata();
            await flushDraft();
            const workspaceDetails = await Promise.all(
                state.workspaces.map((workspace) => api(`/api/v1/workspaces/${workspace.id}`))
            );
            const workspaces = workspaceDetails.map((result) => result.workspace);

            const fields = `
                <label>
                    Ziel-Workspace
                    <select name="targetWorkspaceId" id="move-workspace-select">
                        ${workspaces.map((workspace) => `
                            <option value="${workspace.id}" ${workspace.id === state.workspace.id ? 'selected' : ''}>
                                ${escapeHtml(workspace.title)}
                            </option>
                        `).join('')}
                    </select>
                </label>
                <label>
                    Zielposition
                    <select name="targetParentPageId" id="move-parent-select"></select>
                </label>
                <p class="field-note">Die Seite wird jeweils am Ende der gewählten Ebene eingeordnet. Unterseiten werden gemeinsam verschoben.</p>
            `;

            const values = await formModal({
                title: 'Seite verschieben',
                description: `„${escapeHtml(state.pageState.page.title)}“ kann innerhalb dieses Workspace oder mit dem gesamten Unterbaum in einen anderen Workspace verschoben werden.`,
                fields,
                submitLabel: 'Verschieben',
                onMount: (modal) => {
                    const workspaceSelect = modal.querySelector('#move-workspace-select');
                    const parentSelect = modal.querySelector('#move-parent-select');

                    const updateParents = () => {
                        const workspace = workspaces.find(
                            (candidate) => candidate.id === Number(workspaceSelect.value)
                        );
                        parentSelect.innerHTML = `
                            <option value="">Oberste Ebene</option>
                            ${flatPageOptions(workspace).map((entry) => `
                                <option value="${entry.id}">
                                    ${'— '.repeat(entry.depth)}${escapeHtml(entry.title)}
                                </option>
                            `).join('')}
                        `;
                    };
                    workspaceSelect.addEventListener('change', updateParents);
                    updateParents();
                },
            });
            if (!values) return;

            const result = await api(
                `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/move`,
                {
                    method: 'POST',
                    body: {
                        targetWorkspaceId: Number(values.targetWorkspaceId),
                        targetParentPageId: values.targetParentPageId
                            ? Number(values.targetParentPageId)
                            : null,
                        targetIndex: null,
                    },
                }
            );
            await refreshWorkspaces();
            navigate(result.path);
            toast('Seite wurde verschoben.', { tone: 'success' });
        } catch (error) {
            toast(friendlyError(error), { tone: 'error' });
        }
    }

    async function deleteCurrentPage() {
        await settleMetadata();
        const subtreeIds = collectSubtreeIds(state.workspace, state.pageState.page.id);
        const confirmed = await confirmModal(
            'Seite löschen?',
            subtreeIds.length > 1
                ? `„${escapeHtml(state.pageState.page.title)}“ und ${subtreeIds.length - 1} Unterseite(n) werden in den dateibasierten Papierkorb verschoben.`
                : `„${escapeHtml(state.pageState.page.title)}“ wird in den dateibasierten Papierkorb verschoben.`,
            'Seite löschen',
            true
        );
        if (!confirmed) return;

        try {
            await api(
                `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}`,
                { method: 'DELETE' }
            );
            const workspaceId = state.workspace.id;
            await refreshWorkspaces();
            history.replaceState({}, '', `/workspaces/${workspaceId}`);
            await loadAuthenticatedRoute();
            toast('Seite wurde in den Papierkorb verschoben.', { tone: 'success' });
        } catch (error) {
            toast(friendlyError(error), { tone: 'error' });
        }
    }

    async function discardDraft() {
        await settleMetadata();
        if (!state.pageState.hasDraft && !state.dirty) {
            toast('Es gibt keinen Entwurf zum Verwerfen.');
            return;
        }

        const confirmed = await confirmModal(
            'Entwurf verwerfen?',
            'Alle Änderungen seit der letzten dauerhaften Version gehen verloren.',
            'Entwurf verwerfen',
            true
        );
        if (!confirmed) return;

        try {
            clearAutosaveTimers();
            state.dirty = false;
            const result = await api(
                `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/draft`,
                { method: 'DELETE' }
            );
            state.pageState = result;
            state.workspace = result.workspace;
            renderEditor();
            toast('Entwurf wurde verworfen.', { tone: 'success' });
        } catch (error) {
            state.dirty = true;
            toast(friendlyError(error), { tone: 'error' });
        }
    }

    async function showHistory() {
        try {
            await settleMetadata();
            const result = await api(
                `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/versions`
            );
            const rows = result.versions.map((version) => {
                const isDraft = version.source === 'autosave';
                return `
                    <li class="version-row ${isDraft ? 'version-draft' : ''}">
                        <div class="version-badge">${isDraft ? 'E' : version.revision}</div>
                        <div>
                            <strong>${escapeHtml(version.message)}</strong>
                            <span>${formatDate(version.createdAt)} · ${escapeHtml(version.createdBy)}</span>
                        </div>
                        ${isDraft ? '' : `
                            <button class="button button-quiet button-small" type="button" data-restore-revision="${version.revision}">
                                Wiederherstellen
                            </button>
                        `}
                    </li>
                `;
            }).join('');

            await infoModal(
                'Versionshistorie',
                `<ol class="version-list">${rows || '<li class="tree-empty">Keine Versionen gefunden.</li>'}</ol>`,
                (modal, close) => {
                    modal.querySelectorAll('[data-restore-revision]').forEach((button) => {
                        button.addEventListener('click', async () => {
                            const revision = Number(button.dataset.restoreRevision);
                            close();
                            const confirmed = await confirmModal(
                                `Version ${revision} wiederherstellen?`,
                                'Die historische Version bleibt bestehen. Die Wiederherstellung erzeugt eine neue dauerhafte Version.',
                                'Wiederherstellen'
                            );
                            if (!confirmed) return;
                            try {
                                const restored = await api(
                                    `/api/v1/workspaces/${state.workspace.id}/pages/${state.pageState.page.id}/versions/${revision}/restore`,
                                    { method: 'POST', body: {} }
                                );
                                state.pageState = restored;
                                state.workspace = restored.workspace;
                                state.dirty = false;
                                await refreshWorkspaces();
                                renderPageTree();
                                renderEditor();
                                toast(`Version ${revision} wurde als neue Version wiederhergestellt.`, { tone: 'success' });
                            } catch (error) {
                                toast(friendlyError(error), { tone: 'error' });
                            }
                        });
                    });
                }
            );
        } catch (error) {
            toast(friendlyError(error), { tone: 'error' });
        }
    }

    async function showConflict(error) {
        const choice = await choiceModal({
            title: 'Bearbeitungskonflikt',
            description: escapeHtml(error.message),
            choices: [
                { value: 'reload', label: 'Neuere Version laden', detail: 'Lokalen Arbeitsstand verwerfen', icon: '↻' },
                { value: 'download', label: 'Meine Fassung herunterladen', detail: 'Aktuellen Browserstand als JSON sichern', icon: '↓' },
                { value: 'keep', label: 'Zurück zum Editor', detail: 'Noch keine Daten überschreiben', icon: '←' },
            ],
        });

        if (choice === 'download') {
            downloadJson(
                `${state.pageState.page.slug || 'seite'}-konflikt.json`,
                state.pageState.page
            );
        } else if (choice === 'reload') {
            state.dirty = false;
            await loadAuthenticatedRoute();
        }
    }

    function updateMarkdownPreviews() {
        document.querySelectorAll('.block-card').forEach((card) => {
            const index = Number(card.dataset.index);
            const block = state.pageState.page.blocks[index];
            if (block?.type !== 'markdown') return;
            const preview = card.querySelector('[data-markdown-preview]');
            if (preview) preview.innerHTML = renderMarkdown(block.content);
        });
    }

    function renderMarkdown(markdown) {
        const lines = String(markdown ?? '').replace(/\r\n?/g, '\n').split('\n');
        const output = [];
        let inCode = false;
        let codeLanguage = '';
        let codeLines = [];
        let listType = null;

        const closeList = () => {
            if (!listType) return;
            output.push(`</${listType}>`);
            listType = null;
        };

        for (const line of lines) {
            const fence = line.match(/^```([A-Za-z0-9_-]*)\s*$/);
            if (fence) {
                if (inCode) {
                    output.push(
                        `<pre><code${codeLanguage ? ` data-language="${escapeHtml(codeLanguage)}"` : ''}>${escapeHtml(codeLines.join('\n'))}</code></pre>`
                    );
                    inCode = false;
                    codeLanguage = '';
                    codeLines = [];
                } else {
                    closeList();
                    inCode = true;
                    codeLanguage = fence[1] || '';
                }
                continue;
            }

            if (inCode) {
                codeLines.push(line);
                continue;
            }

            if (/^\s*$/.test(line)) {
                closeList();
                continue;
            }

            const heading = line.match(/^(#{1,6})\s+(.+)$/);
            if (heading) {
                closeList();
                const level = heading[1].length;
                output.push(`<h${level}>${inlineMarkdown(heading[2])}</h${level}>`);
                continue;
            }

            const unordered = line.match(/^\s*[-*+]\s+(.+)$/);
            if (unordered) {
                if (listType !== 'ul') {
                    closeList();
                    listType = 'ul';
                    output.push('<ul>');
                }
                output.push(`<li>${inlineMarkdown(unordered[1])}</li>`);
                continue;
            }

            const ordered = line.match(/^\s*\d+[.)]\s+(.+)$/);
            if (ordered) {
                if (listType !== 'ol') {
                    closeList();
                    listType = 'ol';
                    output.push('<ol>');
                }
                output.push(`<li>${inlineMarkdown(ordered[1])}</li>`);
                continue;
            }

            closeList();
            const quote = line.match(/^\s*>\s?(.*)$/);
            if (quote) {
                output.push(`<blockquote>${inlineMarkdown(quote[1])}</blockquote>`);
            } else {
                output.push(`<p>${inlineMarkdown(line)}</p>`);
            }
        }

        if (inCode) {
            output.push(`<pre><code>${escapeHtml(codeLines.join('\n'))}</code></pre>`);
        }
        closeList();
        return output.join('');
    }

    function inlineMarkdown(value) {
        const codeSpans = [];
        let text = escapeHtml(value).replace(/`([^`\n]+)`/g, (_, code) => {
            const token = `\u0000CODE${codeSpans.length}\u0000`;
            codeSpans.push(`<code>${code}</code>`);
            return token;
        });

        text = text
            .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, url) => {
                const safe = safeUrl(url);
                return safe
                    ? `<a href="${escapeHtml(safe)}" target="_blank" rel="noopener noreferrer">${label}</a>`
                    : label;
            })
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/__([^_]+)__/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
            .replace(/(^|[^_])_([^_\n]+)_/g, '$1<em>$2</em>');

        return text.replace(/\u0000CODE(\d+)\u0000/g, (_, index) => codeSpans[Number(index)]);
    }

    function safeUrl(value) {
        try {
            const decoded = String(value).replaceAll('&amp;', '&');
            const url = new URL(decoded, window.location.origin);
            return ['http:', 'https:', 'mailto:'].includes(url.protocol) ? url.href : null;
        } catch {
            return null;
        }
    }

    function wrapTextareaSelection(textarea, before, after) {
        if (!textarea) return;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = textarea.value.slice(start, end);
        textarea.setRangeText(`${before}${selected}${after}`, start, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
        if (!selected) textarea.setSelectionRange(start + before.length, start + before.length);
    }

    function prefixTextareaLines(textarea, prefix) {
        if (!textarea) return;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const lineStart = textarea.value.lastIndexOf('\n', start - 1) + 1;
        const nextBreak = textarea.value.indexOf('\n', end);
        const lineEnd = nextBreak === -1 ? textarea.value.length : nextBreak;
        const selected = textarea.value.slice(lineStart, lineEnd);
        const replacement = selected
            .split('\n')
            .map((line) => `${prefix}${line}`)
            .join('\n');
        textarea.setRangeText(replacement, lineStart, lineEnd, 'select');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
    }

    function breadcrumbPath(pageId) {
        const result = [];
        const pages = state.workspace.pageIndex.pages;

        const walk = (ids, trail) => {
            for (const rawId of ids) {
                const id = Number(rawId);
                const entry = pages[String(id)];
                if (!entry) continue;
                const nextTrail = [...trail, { id, title: entry.title }];
                if (id === pageId) {
                    result.push(...nextTrail);
                    return true;
                }
                if (walk(entry.children || [], nextTrail)) return true;
            }
            return false;
        };

        walk(state.workspace.pageIndex.rootPageIds || [], []);
        return [{ id: null, title: state.workspace.title }, ...result.slice(0, -1)];
    }

    function flatPageOptions(workspace) {
        const result = [];
        const pages = workspace.pageIndex.pages;
        const walk = (ids, depth) => {
            ids.forEach((rawId) => {
                const id = Number(rawId);
                const entry = pages[String(id)];
                if (!entry) return;
                result.push({ id, title: entry.title, depth });
                walk(entry.children || [], depth + 1);
            });
        };
        walk(workspace.pageIndex.rootPageIds || [], 0);
        return result;
    }

    function collectSubtreeIds(workspace, rootPageId) {
        const result = [];
        const walk = (pageId) => {
            result.push(pageId);
            const entry = workspace.pageIndex.pages[String(pageId)];
            (entry?.children || []).forEach((id) => walk(Number(id)));
        };
        walk(rootPageId);
        return result;
    }

    function readCollapsedBlocks(pageId) {
        try {
            const parsed = JSON.parse(localStorage.getItem(`bkb.collapsed.${pageId}`) || '[]');
            return new Set(Array.isArray(parsed) ? parsed : []);
        } catch {
            return new Set();
        }
    }

    function persistCollapsedBlocks() {
        if (!state.pageState) return;
        localStorage.setItem(
            `bkb.collapsed.${state.pageState.page.id}`,
            JSON.stringify([...state.collapsedBlocks])
        );
    }

    async function logout() {
        if (state.dirty) {
            const confirmed = await confirmModal(
                'Trotz ungespeicherter Änderungen abmelden?',
                'Der aktuelle Browserstand wurde noch nicht vollständig gespeichert.',
                'Abmelden',
                true
            );
            if (!confirmed) return;
        }

        try {
            await api('/api/logout', { method: 'POST', body: {} });
        } finally {
            window.location.assign('/');
        }
    }

    async function handlePopstate() {
        if (state.metadataPromise) {
            await state.metadataPromise;
        }
        if (state.pageState && (state.dirty || state.savingPromise)) {
            try {
                await flushDraft();
            } catch {
                return;
            }
        }
        await loadAuthenticatedRoute().catch(handleFatal);
    }

    function globalKeyboardShortcuts(event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && state.pageState) {
            event.preventDefault();
            saveVersion();
        }
    }

    function closeOpenMenus(event) {
        if (event.target.closest('.popover, [data-action="menu"], #page-menu-button')) return;
        document.querySelectorAll('.popover').forEach((menu) => {
            menu.hidden = true;
        });
    }

    function formModal({
        title,
        description = '',
        fields,
        submitLabel = 'Speichern',
        danger = false,
        onMount = null,
    }) {
        return new Promise((resolve) => {
            const layer = document.querySelector('#modal-layer');
            layer.innerHTML = `
                <div class="modal-backdrop">
                    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
                        <header>
                            <div>
                                <h2 id="modal-title">${escapeHtml(title)}</h2>
                                ${description ? `<p>${description}</p>` : ''}
                            </div>
                            <button type="button" class="icon-button modal-close" aria-label="Dialog schließen">×</button>
                        </header>
                        <form class="modal-form">
                            <div class="modal-body">${fields}</div>
                            <footer>
                                <button type="button" class="button button-quiet modal-cancel">Abbrechen</button>
                                <button type="submit" class="button ${danger ? 'button-danger' : 'button-primary'}">${escapeHtml(submitLabel)}</button>
                            </footer>
                        </form>
                    </section>
                </div>
            `;

            const backdrop = layer.querySelector('.modal-backdrop');
            const modal = layer.querySelector('.modal');
            const form = layer.querySelector('form');
            const finish = (value) => {
                layer.innerHTML = '';
                resolve(value);
            };
            layer.querySelector('.modal-close').addEventListener('click', () => finish(null));
            layer.querySelector('.modal-cancel').addEventListener('click', () => finish(null));
            backdrop.addEventListener('mousedown', (event) => {
                if (event.target === backdrop) finish(null);
            });
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                if (!form.reportValidity()) return;
                finish(Object.fromEntries(new FormData(form)));
            });
            onMount?.(modal, finish);
            requestAnimationFrame(() => modal.querySelector('[autofocus], input, textarea, select')?.focus());
        });
    }

    function choiceModal({ title, description = '', choices }) {
        return new Promise((resolve) => {
            const layer = document.querySelector('#modal-layer');
            layer.innerHTML = `
                <div class="modal-backdrop">
                    <section class="modal modal-choice" role="dialog" aria-modal="true" aria-labelledby="choice-title">
                        <header>
                            <div>
                                <h2 id="choice-title">${escapeHtml(title)}</h2>
                                ${description ? `<p>${description}</p>` : ''}
                            </div>
                            <button type="button" class="icon-button modal-close" aria-label="Dialog schließen">×</button>
                        </header>
                        <div class="choice-grid">
                            ${choices.map((choice) => `
                                <button type="button" data-choice="${escapeHtml(choice.value)}">
                                    <span class="choice-icon">${escapeHtml(choice.icon)}</span>
                                    <span><strong>${escapeHtml(choice.label)}</strong><small>${escapeHtml(choice.detail)}</small></span>
                                </button>
                            `).join('')}
                        </div>
                    </section>
                </div>
            `;
            const backdrop = layer.querySelector('.modal-backdrop');
            const finish = (value) => {
                layer.innerHTML = '';
                resolve(value);
            };
            layer.querySelector('.modal-close').addEventListener('click', () => finish(null));
            backdrop.addEventListener('mousedown', (event) => {
                if (event.target === backdrop) finish(null);
            });
            layer.querySelectorAll('[data-choice]').forEach((button) => {
                button.addEventListener('click', () => finish(button.dataset.choice));
            });
        });
    }

    function confirmModal(title, description, confirmLabel = 'Bestätigen', danger = false) {
        return new Promise((resolve) => {
            const layer = document.querySelector('#modal-layer');
            layer.innerHTML = `
                <div class="modal-backdrop">
                    <section class="modal modal-confirm" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
                        <header>
                            <div>
                                <h2 id="confirm-title">${escapeHtml(title)}</h2>
                                <p>${description}</p>
                            </div>
                        </header>
                        <footer>
                            <button type="button" class="button button-quiet" data-confirm="no">Abbrechen</button>
                            <button type="button" class="button ${danger ? 'button-danger' : 'button-primary'}" data-confirm="yes">${escapeHtml(confirmLabel)}</button>
                        </footer>
                    </section>
                </div>
            `;
            const finish = (value) => {
                layer.innerHTML = '';
                resolve(value);
            };
            layer.querySelector('[data-confirm="no"]').addEventListener('click', () => finish(false));
            layer.querySelector('[data-confirm="yes"]').addEventListener('click', () => finish(true));
        });
    }

    function infoModal(title, content, onMount = null) {
        return new Promise((resolve) => {
            const layer = document.querySelector('#modal-layer');
            layer.innerHTML = `
                <div class="modal-backdrop">
                    <section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="info-title">
                        <header>
                            <div><h2 id="info-title">${escapeHtml(title)}</h2></div>
                            <button type="button" class="icon-button modal-close" aria-label="Dialog schließen">×</button>
                        </header>
                        <div class="modal-body">${content}</div>
                        <footer>
                            <button type="button" class="button button-primary modal-close-footer">Schließen</button>
                        </footer>
                    </section>
                </div>
            `;
            const finish = () => {
                layer.innerHTML = '';
                resolve();
            };
            layer.querySelector('.modal-close').addEventListener('click', finish);
            layer.querySelector('.modal-close-footer').addEventListener('click', finish);
            onMount?.(layer.querySelector('.modal'), finish);
        });
    }

    function toast(message, {
        tone = 'neutral',
        actionLabel = null,
        onAction = null,
        timeout = 5000,
    } = {}) {
        const region = document.querySelector('#toast-region');
        if (!region) return;

        const element = document.createElement('div');
        element.className = `toast toast-${tone}`;
        const text = document.createElement('span');
        text.textContent = message;
        element.append(text);

        if (actionLabel && onAction) {
            const action = document.createElement('button');
            action.type = 'button';
            action.textContent = actionLabel;
            action.addEventListener('click', () => {
                onAction();
                element.remove();
            });
            element.append(action);
        }

        region.append(element);
        window.setTimeout(() => element.remove(), timeout);
    }

    function friendlyError(error) {
        if (error instanceof ApiError) return error.message;
        if (error instanceof TypeError && String(error.message).includes('fetch')) {
            return 'Der Server ist derzeit nicht erreichbar.';
        }
        return error?.message || 'Ein unerwarteter Fehler ist aufgetreten.';
    }

    function handleFatal(error) {
        console.error(error);
        app.innerHTML = `
            <main class="fatal-state">
                <div class="empty-symbol">!</div>
                <h1>BlockKnowledgeBase konnte nicht geladen werden.</h1>
                <p>${escapeHtml(friendlyError(error))}</p>
                <button class="button button-primary" id="reload-app" type="button">Neu laden</button>
            </main>
        `;
        document.querySelector('#reload-app')?.addEventListener('click', () => window.location.reload());
    }

    function downloadJson(filename, data) {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(url);
    }

    function timeOnly(value) {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';
        return new Intl.DateTimeFormat('de-AT', {
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    }

    function initials(value) {
        return String(value || '?')
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((part) => part[0] || '')
            .join('')
            .toUpperCase();
    }

    boot();
})();
