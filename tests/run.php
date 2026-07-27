<?php

declare(strict_types=1);

if (!getenv('BKB_DATA_DIR')) {
    file_put_contents(
        'php://stderr',
        "BKB_DATA_DIR muss für Tests auf ein leeres temporäres Verzeichnis zeigen.\n"
    );
    exit(2);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';

$workspaces = $container['workspaces'];
$pages = $container['pages'];
$ids = $container['ids'];
$layout = $container['layout'];
$userId = 'user_test';
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion fehlgeschlagen: ' . $message);
    }
};

$workspaceA = $ids->withNewWorkspaceId(
    static fn (int $id): array => $workspaces->createWithId($id, 'Workspace A', $userId)
);
$workspaceB = $ids->withNewWorkspaceId(
    static fn (int $id): array => $workspaces->createWithId($id, 'Workspace B', $userId)
);

$assert($workspaceA['id'] !== $workspaceB['id'], 'Workspace-IDs müssen eindeutig sein.');
$assert($workspaceA['id'] > 100 && $workspaceB['id'] > 100, 'Workspace-IDs müssen größer als 100 sein.');

$rootResult = $pages->create((int) $workspaceA['id'], 'Projekt Alpha', null, $userId);
$rootPage = $rootResult['page'];
$childResult = $pages->create(
    (int) $workspaceA['id'],
    'Notizen',
    (int) $rootPage['id'],
    $userId
);
$childPage = $childResult['page'];

$assert($rootPage['id'] !== $childPage['id'], 'Page-IDs müssen global eindeutig sein.');
$assert($rootPage['id'] > 100 && $childPage['id'] > 100, 'Page-IDs müssen größer als 100 sein.');

$workspaceAState = $workspaces->get((int) $workspaceA['id']);
$assert(
    $workspaces->parentOf($workspaceAState, (int) $childPage['id']) === (int) $rootPage['id'],
    'Die Eltern-Kind-Beziehung muss ausschließlich aus dem Workspace-Index hervorgehen.'
);
$assert(
    !array_key_exists('parentPageId', $childPage) && !array_key_exists('workspaceId', $childPage),
    'Seitendateien dürfen weder parentPageId noch workspaceId enthalten.'
);

$blockId = $pages->newBlockId((int) $workspaceA['id'], (int) $rootPage['id']);
$assert(
    preg_match('/^[a-f0-9]{64}$/', $blockId) === 1,
    'Block-IDs müssen 64-stellige kleingeschriebene Hexadezimalwerte sein.'
);

$editorState = $pages->editorState((int) $workspaceA['id'], (int) $rootPage['id']);
$draftPage = $editorState['page'];
$draftPage['blocks'][] = [
    'id' => $blockId,
    'type' => 'heading',
    'content' => 'Testüberschrift',
    'settings' => [
        'level' => 2,
        'includeInToc' => true,
        'anchor' => null,
    ],
    'meta' => [],
];

$savedDraft = $pages->saveDraft(
    (int) $workspaceA['id'],
    (int) $rootPage['id'],
    0,
    $draftPage,
    $userId
);
$assert($savedDraft['hasDraft'] === true, 'Autosave muss einen Entwurf erzeugen.');
$assert($savedDraft['draftRevision'] === 1, 'Die erste Entwurfsrevision muss 1 sein.');
$assert(
    is_file($layout->pageAutosaveJson((int) $workspaceA['id'], (int) $rootPage['id'])),
    'autosave.json muss geschrieben werden.'
);

$conflictDetected = false;
try {
    $pages->saveDraft(
        (int) $workspaceA['id'],
        (int) $rootPage['id'],
        0,
        $draftPage,
        $userId
    );
} catch (\BKB\HttpException $exception) {
    $conflictDetected = $exception->status === 409 && $exception->errorCode === 'DRAFT_CONFLICT';
}
$assert($conflictDetected, 'Eine veraltete draftRevision muss mit 409 abgewiesen werden.');

$versioned = $pages->saveVersion(
    (int) $workspaceA['id'],
    (int) $rootPage['id'],
    1,
    'Ersten Block ergänzt',
    $userId
);
$assert($versioned['publishedRevision'] === 2, 'Die erste gespeicherte Änderung muss Version 2 erzeugen.');
$assert($versioned['hasDraft'] === false, 'Der übernommene Entwurf muss entfernt werden.');
$assert(
    is_file($layout->pageVersionJson((int) $workspaceA['id'], (int) $rootPage['id'], 2)),
    'Die unveränderliche Versionsdatei muss existieren.'
);

$renamed = $pages->rename(
    (int) $workspaceA['id'],
    (int) $rootPage['id'],
    'Projekt Beta',
    $userId
);
$assert($renamed['publishedRevision'] === 3, 'Umbenennen muss eine dauerhafte Revision erzeugen.');
$assert($renamed['page']['title'] === 'Projekt Beta', 'Der neue Titel muss in der Seite stehen.');
$indexEntry = $renamed['workspace']['pageIndex']['pages'][(string) $rootPage['id']];
$assert($indexEntry['title'] === 'Projekt Beta', 'Der Titel muss gemeinsam im Workspace-Index aktualisiert werden.');

$moved = $pages->move(
    (int) $workspaceA['id'],
    (int) $rootPage['id'],
    (int) $workspaceB['id'],
    null,
    null,
    $userId
);
$assert($moved['workspaceId'] === (int) $workspaceB['id'], 'Der Ziel-Workspace muss zurückgegeben werden.');
$assert(
    !is_dir($layout->pageDir((int) $workspaceA['id'], (int) $rootPage['id']))
    && is_dir($layout->pageDir((int) $workspaceB['id'], (int) $rootPage['id'])),
    'Der Seitenordner muss physisch in den Ziel-Workspace verschoben werden.'
);
$assert(
    is_dir($layout->pageDir((int) $workspaceB['id'], (int) $childPage['id'])),
    'Unterseiten müssen gemeinsam mit der Wurzelseite verschoben werden.'
);
$assert(
    $workspaces->locatePage((int) $rootPage['id']) === (int) $workspaceB['id'],
    'Die global eindeutige Page-ID muss im neuen Workspace auflösbar sein.'
);
$assert(
    glob($layout->transactionsDir() . '/workspace-move-*.json') === [],
    'Erfolgreich abgeschlossene Verschiebejournale müssen entfernt werden.'
);

$restored = $pages->restore(
    (int) $workspaceB['id'],
    (int) $rootPage['id'],
    2,
    $userId
);
$assert($restored['publishedRevision'] === 4, 'Wiederherstellen muss eine neue Revision erzeugen.');
$assert(
    $restored['page']['title'] === 'Projekt Alpha',
    'Die wiederhergestellte Version muss den damaligen Titel enthalten.'
);

$versions = $pages->listVersions((int) $workspaceB['id'], (int) $rootPage['id']);
$assert(count($versions) === 4, 'Die Versionshistorie muss alle vier dauerhaften Revisionen enthalten.');

$deleted = $pages->delete(
    (int) $workspaceB['id'],
    (int) $rootPage['id'],
    $userId
);
$assert(count($deleted['deletedPageIds']) === 2, 'Löschen muss den gesamten Unterbaum erfassen.');
$assert($deleted['recoverable'] === true, 'Gelöschte Seiten müssen im dateibasierten Papierkorb bleiben.');
$assert($workspaces->locatePage((int) $rootPage['id']) === null, 'Gelöschte Seiten dürfen nicht mehr aktiv auflösbar sein.');

$workspaceBAfterDelete = $workspaces->get((int) $workspaceB['id']);
$retired = array_map('intval', $workspaceBAfterDelete['pageIndex']['retiredPageIds']);
$assert(in_array((int) $rootPage['id'], $retired, true), 'Gelöschte Page-IDs müssen stillgelegt werden.');
$assert(in_array((int) $childPage['id'], $retired, true), 'Auch gelöschte Unterseiten-IDs müssen stillgelegt werden.');

echo "OK – {$assertions} Assertions erfolgreich.\n";
