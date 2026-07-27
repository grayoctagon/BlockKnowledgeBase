<?php

declare(strict_types=1);

namespace BKB\Domain;

use BKB\HttpException;
use BKB\Storage\AtomicJsonStore;
use BKB\Storage\DataLayout;
use BKB\Text;

final class PageRepository
{
    public function __construct(
        private readonly DataLayout $layout,
        private readonly AtomicJsonStore $store,
        private readonly WorkspaceRepository $workspaces,
        private readonly IdAllocator $ids,
        private readonly PageValidator $validator
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        int $workspaceId,
        string $title,
        ?int $parentPageId,
        string $userId
    ): array {
        $title = Text::requiredString($title, 'title', 180);

        return $this->ids->withNewPageId(
            function (int $pageId) use ($workspaceId, $title, $parentPageId, $userId): array {
                return $this->workspaces->withWorkspaceLocks(
                    [$workspaceId],
                    function () use ($workspaceId, $pageId, $title, $parentPageId, $userId): array {
                        $workspace = $this->workspaces->get($workspaceId);
                        if ($parentPageId !== null && !$this->workspaces->containsPage($workspace, $parentPageId)) {
                            throw new HttpException(422, 'PARENT_PAGE_NOT_FOUND', 'Die gewählte Elternseite wurde nicht gefunden.');
                        }

                        $now = Text::now();
                        $page = [
                            'schemaVersion' => 1,
                            'id' => $pageId,
                            'title' => $title,
                            'slug' => Text::slug($title, $pageId),
                            'revision' => 1,
                            'draftRevision' => 0,
                            'createdAt' => $now,
                            'createdBy' => $userId,
                            'updatedAt' => $now,
                            'updatedBy' => $userId,
                            'labels' => [],
                            'blocks' => [],
                        ];

                        $pageDirectory = $this->layout->pageDir($workspaceId, $pageId);
                        $this->store->ensureDirectory($this->layout->pageVersionsDir($workspaceId, $pageId));

                        try {
                            $this->store->write($this->layout->pageJson($workspaceId, $pageId), $page);
                            $this->store->writeImmutable(
                                $this->layout->pageVersionJson($workspaceId, $pageId, 1),
                                $this->versionRecord($page, 1, 'Seite erstellt', 'web', $userId)
                            );

                            $workspace['pageIndex']['pages'][(string) $pageId] = [
                                'title' => $title,
                                'slug' => $page['slug'],
                                'children' => [],
                            ];
                            $this->workspaces->insertPlacement($workspace, $pageId, $parentPageId, null);
                            $workspace['updatedAt'] = $now;
                            $workspace['updatedBy'] = $userId;
                            $this->workspaces->write($workspaceId, $workspace);
                        } catch (\Throwable $exception) {
                            if (is_dir($pageDirectory)) {
                                $orphanDirectory = $this->layout->trashDir()
                                    . '/orphan-page-'
                                    . $workspaceId
                                    . '-'
                                    . $pageId
                                    . '-'
                                    . gmdate('YmdHis');
                                @rename($pageDirectory, $orphanDirectory);
                            }
                            throw $exception;
                        }

                        return [
                            'workspace' => $workspace,
                            'page' => $page,
                            'path' => '/workspaces/' . $workspaceId . '/pages/' . $pageId,
                        ];
                    }
                );
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function editorState(int $workspaceId, int $pageId): array
    {
        $workspace = $this->requirePageInWorkspace($workspaceId, $pageId);
        return $this->editorStateUnlocked($workspaceId, $pageId, $workspace);
    }

    /**
     * @param array<string, mixed> $incomingPage
     * @return array<string, mixed>
     */
    public function saveDraft(
        int $workspaceId,
        int $pageId,
        int $baseDraftRevision,
        array $incomingPage,
        string $userId
    ): array {
        $this->requirePageInWorkspace($workspaceId, $pageId);

        return $this->store->withLock(
            $this->layout->pageLock($pageId),
            function () use (
                $workspaceId,
                $pageId,
                $baseDraftRevision,
                $incomingPage,
                $userId
            ): array {
                $workspace = $this->requirePageInWorkspace($workspaceId, $pageId);
                $published = $this->readPublished($workspaceId, $pageId);
                $autosave = $this->readAutosave($workspaceId, $pageId);
                $currentDraftRevision = (int) ($autosave['draftRevision'] ?? 0);

                if ($baseDraftRevision !== $currentDraftRevision) {
                    throw new HttpException(
                        409,
                        'DRAFT_CONFLICT',
                        'Diese Seite wurde inzwischen an anderer Stelle geändert.',
                        [
                            'expectedDraftRevision' => $baseDraftRevision,
                            'currentDraftRevision' => $currentDraftRevision,
                        ]
                    );
                }

                if (
                    $autosave !== null
                    && (int) ($autosave['baseRevision'] ?? 0) !== (int) $published['revision']
                ) {
                    throw new HttpException(
                        409,
                        'BASE_REVISION_CONFLICT',
                        'Der Entwurf basiert nicht mehr auf der aktuellen Seitenversion.',
                        [
                            'draftBaseRevision' => (int) ($autosave['baseRevision'] ?? 0),
                            'currentRevision' => (int) $published['revision'],
                        ]
                    );
                }

                $basis = is_array($autosave['page'] ?? null) ? $autosave['page'] : $published;
                $normalized = $this->validator->normalizeDraft($incomingPage, $basis, $pageId, $userId);
                $normalized['title'] = (string) $basis['title'];
                $normalized['slug'] = (string) $basis['slug'];
                $normalized['revision'] = (int) $published['revision'];
                $normalized['draftRevision'] = $currentDraftRevision + 1;

                $record = [
                    'schemaVersion' => 1,
                    'pageId' => $pageId,
                    'baseRevision' => (int) $published['revision'],
                    'draftRevision' => $currentDraftRevision + 1,
                    'savedAt' => Text::now(),
                    'savedBy' => $userId,
                    'page' => $normalized,
                ];

                $this->store->write(
                    $this->layout->pageAutosaveJson($workspaceId, $pageId),
                    $record,
                    $this->layout->pageAutosavePreviousJson($workspaceId, $pageId)
                );

                return $this->editorStateUnlocked($workspaceId, $pageId, $workspace);
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function discardDraft(int $workspaceId, int $pageId): array
    {
        $this->requirePageInWorkspace($workspaceId, $pageId);

        return $this->store->withLock(
            $this->layout->pageLock($pageId),
            function () use ($workspaceId, $pageId): array {
                $workspace = $this->requirePageInWorkspace($workspaceId, $pageId);
                $path = $this->layout->pageAutosaveJson($workspaceId, $pageId);
                if (is_file($path) && !unlink($path)) {
                    throw new HttpException(500, 'DRAFT_DELETE_FAILED', 'Der Entwurf konnte nicht verworfen werden.');
                }

                return $this->editorStateUnlocked($workspaceId, $pageId, $workspace);
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function saveVersion(
        int $workspaceId,
        int $pageId,
        int $baseDraftRevision,
        ?string $message,
        string $userId,
        string $source = 'web'
    ): array {
        $message = Text::optionalString($message, 'message', 500);
        $this->requirePageInWorkspace($workspaceId, $pageId);

        return $this->store->withLock(
            $this->layout->pageLock($pageId),
            function () use (
                $workspaceId,
                $pageId,
                $baseDraftRevision,
                $message,
                $userId,
                $source
            ): array {
                $workspace = $this->requirePageInWorkspace($workspaceId, $pageId);
                $published = $this->readPublished($workspaceId, $pageId);
                $autosave = $this->readAutosave($workspaceId, $pageId);

                if ($autosave === null) {
                    throw new HttpException(422, 'NO_DRAFT', 'Es gibt keine ungespeicherten Änderungen.');
                }

                $currentDraftRevision = (int) ($autosave['draftRevision'] ?? 0);
                if ($baseDraftRevision !== $currentDraftRevision) {
                    throw new HttpException(
                        409,
                        'DRAFT_CONFLICT',
                        'Diese Seite wurde inzwischen an anderer Stelle geändert.',
                        [
                            'expectedDraftRevision' => $baseDraftRevision,
                            'currentDraftRevision' => $currentDraftRevision,
                        ]
                    );
                }

                if ((int) ($autosave['baseRevision'] ?? 0) !== (int) $published['revision']) {
                    throw new HttpException(
                        409,
                        'BASE_REVISION_CONFLICT',
                        'Der Entwurf basiert nicht mehr auf der aktuellen Seitenversion.'
                    );
                }

                $page = $autosave['page'] ?? null;
                if (!is_array($page)) {
                    throw new HttpException(500, 'INVALID_DRAFT', 'Der gespeicherte Entwurf ist ungültig.');
                }

                $newRevision = (int) $published['revision'] + 1;
                $page['revision'] = $newRevision;
                $page['draftRevision'] = 0;
                $page['updatedAt'] = Text::now();
                $page['updatedBy'] = $userId;
                $versionPath = $this->layout->pageVersionJson($workspaceId, $pageId, $newRevision);

                $this->store->writeImmutable(
                    $versionPath,
                    $this->versionRecord(
                        $page,
                        $newRevision,
                        $message ?? 'Änderungen gespeichert',
                        $source,
                        $userId
                    )
                );

                try {
                    $this->store->write($this->layout->pageJson($workspaceId, $pageId), $page);
                } catch (\Throwable $exception) {
                    @unlink($versionPath);
                    throw $exception;
                }

                $autosavePath = $this->layout->pageAutosaveJson($workspaceId, $pageId);
                if (is_file($autosavePath) && !unlink($autosavePath)) {
                    throw new HttpException(500, 'DRAFT_DELETE_FAILED', 'Die Version wurde gespeichert, der Entwurf aber nicht entfernt.');
                }

                return $this->editorStateUnlocked($workspaceId, $pageId, $workspace);
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rename(
        int $workspaceId,
        int $pageId,
        string $title,
        string $userId
    ): array {
        $title = Text::requiredString($title, 'title', 180);

        return $this->workspaces->withWorkspaceLocks(
            [$workspaceId],
            function () use ($workspaceId, $pageId, $title, $userId): array {
                return $this->store->withLock(
                    $this->layout->pageLock($pageId),
                    function () use ($workspaceId, $pageId, $title, $userId): array {
                        $workspace = $this->requirePageInWorkspace($workspaceId, $pageId);
                        $published = $this->readPublished($workspaceId, $pageId);
                        $autosave = $this->readAutosave($workspaceId, $pageId);

                        if ((string) $published['title'] === $title) {
                            return $this->editorStateUnlocked($workspaceId, $pageId, $workspace);
                        }

                        $oldWorkspace = $workspace;
                        $oldPublished = $published;
                        $oldAutosave = $autosave;
                        $newRevision = (int) $published['revision'] + 1;
                        $slug = Text::slug($title, $pageId);
                        $now = Text::now();
                        $published['title'] = $title;
                        $published['slug'] = $slug;
                        $published['revision'] = $newRevision;
                        $published['updatedAt'] = $now;
                        $published['updatedBy'] = $userId;

                        $versionPath = $this->layout->pageVersionJson(
                            $workspaceId,
                            $pageId,
                            $newRevision
                        );
                        $versionCreated = false;

                        try {
                            $this->store->writeImmutable(
                                $versionPath,
                                $this->versionRecord(
                                    $published,
                                    $newRevision,
                                    'Seite umbenannt',
                                    'web',
                                    $userId
                                )
                            );
                            $versionCreated = true;
                            $this->store->write(
                                $this->layout->pageJson($workspaceId, $pageId),
                                $published
                            );

                            $key = array_key_exists((string) $pageId, $workspace['pageIndex']['pages'])
                                ? (string) $pageId
                                : $pageId;
                            $workspace['pageIndex']['pages'][$key]['title'] = $title;
                            $workspace['pageIndex']['pages'][$key]['slug'] = $slug;
                            $workspace['updatedAt'] = $now;
                            $workspace['updatedBy'] = $userId;
                            $this->workspaces->write($workspaceId, $workspace);

                            if ($autosave !== null && is_array($autosave['page'] ?? null)) {
                                $autosave['baseRevision'] = $newRevision;
                                $autosave['draftRevision'] = (int) $autosave['draftRevision'] + 1;
                                $autosave['savedAt'] = $now;
                                $autosave['savedBy'] = $userId;
                                $autosave['page']['title'] = $title;
                                $autosave['page']['slug'] = $slug;
                                $autosave['page']['revision'] = $newRevision;
                                $autosave['page']['draftRevision'] = $autosave['draftRevision'];
                                $this->store->write(
                                    $this->layout->pageAutosaveJson($workspaceId, $pageId),
                                    $autosave,
                                    $this->layout->pageAutosavePreviousJson($workspaceId, $pageId)
                                );
                            }
                        } catch (\Throwable $exception) {
                            try {
                                $this->store->write($this->layout->pageJson($workspaceId, $pageId), $oldPublished);
                                $this->workspaces->write($workspaceId, $oldWorkspace);
                                if ($oldAutosave !== null) {
                                    $this->store->write(
                                        $this->layout->pageAutosaveJson($workspaceId, $pageId),
                                        $oldAutosave
                                    );
                                }
                                if ($versionCreated) {
                                    @unlink($versionPath);
                                }
                            } catch (\Throwable) {
                                // The original exception remains the actionable failure.
                            }
                            throw $exception;
                        }

                        return $this->editorStateUnlocked($workspaceId, $pageId, $workspace);
                    }
                );
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function move(
        int $sourceWorkspaceId,
        int $pageId,
        int $targetWorkspaceId,
        ?int $targetParentPageId,
        ?int $targetIndex,
        string $userId
    ): array {
        $this->recoverPendingMoves();

        if ($sourceWorkspaceId === $targetWorkspaceId) {
            return $this->moveInsideWorkspace(
                $sourceWorkspaceId,
                $pageId,
                $targetParentPageId,
                $targetIndex,
                $userId
            );
        }

        return $this->store->withLock(
            $this->layout->workspaceMoveLock(),
            function () use (
                $sourceWorkspaceId,
                $pageId,
                $targetWorkspaceId,
                $targetParentPageId,
                $targetIndex,
                $userId
            ): array {
                return $this->workspaces->withWorkspaceLocks(
                    [$sourceWorkspaceId, $targetWorkspaceId],
                    function () use (
                        $sourceWorkspaceId,
                        $pageId,
                        $targetWorkspaceId,
                        $targetParentPageId,
                        $targetIndex,
                        $userId
                    ): array {
                        $source = $this->requirePageInWorkspace($sourceWorkspaceId, $pageId);
                        $target = $this->workspaces->get($targetWorkspaceId);
                        if (
                            $targetParentPageId !== null
                            && !$this->workspaces->containsPage($target, $targetParentPageId)
                        ) {
                            throw new HttpException(422, 'TARGET_PARENT_NOT_FOUND', 'Die gewählte Zielseite wurde nicht gefunden.');
                        }

                        $pageIds = $this->workspaces->subtreeIds($source, $pageId);
                        foreach ($pageIds as $subtreePageId) {
                            if ($this->workspaces->containsPage($target, $subtreePageId)) {
                                throw new HttpException(
                                    409,
                                    'PAGE_ID_COLLISION',
                                    'Im Ziel-Workspace existiert bereits eine Seite aus dem verschobenen Unterbaum.'
                                );
                            }
                        }

                        return $this->withPageLocks(
                            $pageIds,
                            function () use (
                                $sourceWorkspaceId,
                                $pageId,
                                $targetWorkspaceId,
                                $targetParentPageId,
                                $targetIndex,
                                $userId,
                                $source,
                                $target,
                                $pageIds
                            ): array {
                                $sourceAfter = $source;
                                $targetAfter = $target;
                                $this->workspaces->removePlacement($sourceAfter, $pageId);

                                foreach ($pageIds as $subtreePageId) {
                                    $key = array_key_exists((string) $subtreePageId, $sourceAfter['pageIndex']['pages'])
                                        ? (string) $subtreePageId
                                        : $subtreePageId;
                                    $entry = $sourceAfter['pageIndex']['pages'][$key] ?? null;
                                    if (!is_array($entry)) {
                                        throw new HttpException(500, 'INVALID_PAGE_INDEX', 'Der Quellindex ist unvollständig.');
                                    }
                                    $targetAfter['pageIndex']['pages'][(string) $subtreePageId] = $entry;
                                    unset($sourceAfter['pageIndex']['pages'][$key]);
                                }

                                $this->workspaces->insertPlacement(
                                    $targetAfter,
                                    $pageId,
                                    $targetParentPageId,
                                    $targetIndex
                                );
                                $now = Text::now();
                                $sourceAfter['updatedAt'] = $now;
                                $sourceAfter['updatedBy'] = $userId;
                                $targetAfter['updatedAt'] = $now;
                                $targetAfter['updatedBy'] = $userId;
                                $this->workspaces->validate($sourceAfter, $sourceWorkspaceId);
                                $this->workspaces->validate($targetAfter, $targetWorkspaceId);

                                $transactionId = bin2hex(random_bytes(16));
                                $transactionPath = $this->layout->transactionJson($transactionId);
                                $transaction = [
                                    'schemaVersion' => 1,
                                    'id' => $transactionId,
                                    'type' => 'workspace_page_move',
                                    'status' => 'prepared',
                                    'createdAt' => $now,
                                    'sourceWorkspaceId' => $sourceWorkspaceId,
                                    'targetWorkspaceId' => $targetWorkspaceId,
                                    'rootPageId' => $pageId,
                                    'pageIds' => $pageIds,
                                    'sourceAfter' => $sourceAfter,
                                    'targetAfter' => $targetAfter,
                                ];
                                $this->store->writeImmutable($transactionPath, $transaction);

                                $this->completeMoveTransaction($transaction, $transactionPath);

                                return [
                                    'sourceWorkspace' => $sourceAfter,
                                    'targetWorkspace' => $targetAfter,
                                    'workspaceId' => $targetWorkspaceId,
                                    'pageId' => $pageId,
                                    'path' => '/workspaces/' . $targetWorkspaceId . '/pages/' . $pageId,
                                ];
                            }
                        );
                    }
                );
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $workspaceId, int $pageId, string $userId): array
    {
        return $this->workspaces->withWorkspaceLocks(
            [$workspaceId],
            function () use ($workspaceId, $pageId, $userId): array {
                $workspace = $this->requirePageInWorkspace($workspaceId, $pageId);
                $pageIds = $this->workspaces->subtreeIds($workspace, $pageId);

                return $this->withPageLocks(
                    $pageIds,
                    function () use ($workspaceId, $pageId, $pageIds, $workspace, $userId): array {
                        $updated = $workspace;
                        $this->workspaces->removePlacement($updated, $pageId);
                        foreach ($pageIds as $subtreePageId) {
                            $key = array_key_exists((string) $subtreePageId, $updated['pageIndex']['pages'])
                                ? (string) $subtreePageId
                                : $subtreePageId;
                            unset($updated['pageIndex']['pages'][$key]);
                            $updated['pageIndex']['retiredPageIds'][] = $subtreePageId;
                        }
                        $updated['pageIndex']['retiredPageIds'] = array_values(
                            array_unique(array_map('intval', $updated['pageIndex']['retiredPageIds']))
                        );
                        $updated['updatedAt'] = Text::now();
                        $updated['updatedBy'] = $userId;
                        $this->workspaces->validate($updated, $workspaceId);

                        $trashRoot = $this->layout->workspaceDir($workspaceId)
                            . '/trash/pages/deleted-'
                            . $pageId
                            . '-'
                            . gmdate('YmdHis');
                        $this->store->ensureDirectory($trashRoot);
                        $moved = [];
                        $indexWritten = false;

                        try {
                            $this->workspaces->write($workspaceId, $updated);
                            $indexWritten = true;

                            foreach ($pageIds as $subtreePageId) {
                                $source = $this->layout->pageDir($workspaceId, $subtreePageId);
                                $destination = $trashRoot . '/' . $subtreePageId;
                                if (is_dir($source)) {
                                    if (!rename($source, $destination)) {
                                        throw new HttpException(500, 'PAGE_TRASH_FAILED', 'Eine Seite konnte nicht in den Papierkorb verschoben werden.');
                                    }
                                    $moved[$subtreePageId] = $destination;
                                }
                            }
                        } catch (\Throwable $exception) {
                            foreach (array_reverse($moved, true) as $subtreePageId => $destination) {
                                @rename($destination, $this->layout->pageDir($workspaceId, (int) $subtreePageId));
                            }
                            if ($indexWritten) {
                                try {
                                    $this->workspaces->write($workspaceId, $workspace);
                                } catch (\Throwable) {
                                    // Preserve the original exception.
                                }
                            }
                            throw $exception;
                        }

                        return [
                            'workspace' => $updated,
                            'deletedPageIds' => $pageIds,
                            'recoverable' => true,
                        ];
                    }
                );
            }
        );
    }

    public function newBlockId(int $workspaceId, int $pageId): string
    {
        $this->requirePageInWorkspace($workspaceId, $pageId);
        $state = $this->editorState($workspaceId, $pageId);
        $known = [];
        $this->collectBlockIds($state['page']['blocks'] ?? [], $known);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $id = $this->ids->blockId($pageId);
            if (!isset($known[$id])) {
                return $id;
            }
        }

        throw new HttpException(503, 'BLOCK_ID_ALLOCATION_FAILED', 'Es konnte keine freie Block-ID erzeugt werden.');
    }

    /**
     * @param mixed $blocks
     * @param array<string, bool> $known
     */
    private function collectBlockIds(mixed $blocks, array &$known): void
    {
        if (!is_array($blocks)) {
            return;
        }

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (is_string($block['id'] ?? null)) {
                $known[$block['id']] = true;
            }
            $this->collectBlockIds($block['children'] ?? null, $known);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(int $workspaceId, int $pageId): array
    {
        $this->requirePageInWorkspace($workspaceId, $pageId);
        $files = glob($this->layout->pageVersionsDir($workspaceId, $pageId) . '/*.json') ?: [];
        rsort($files, SORT_NATURAL);
        $versions = [];

        foreach (array_slice($files, 0, 200) as $file) {
            $record = $this->store->read($file);
            if ($record === null) {
                continue;
            }
            $versions[] = [
                'revision' => (int) ($record['revision'] ?? 0),
                'message' => (string) ($record['message'] ?? ''),
                'source' => (string) ($record['source'] ?? 'web'),
                'createdAt' => (string) ($record['createdAt'] ?? ''),
                'createdBy' => (string) ($record['createdBy'] ?? ''),
            ];
        }

        $autosave = $this->readAutosave($workspaceId, $pageId);
        if ($autosave !== null) {
            array_unshift(
                $versions,
                [
                    'revision' => null,
                    'draftRevision' => (int) ($autosave['draftRevision'] ?? 0),
                    'baseRevision' => (int) ($autosave['baseRevision'] ?? 0),
                    'message' => 'Entwurf nach Version ' . (int) ($autosave['baseRevision'] ?? 0),
                    'source' => 'autosave',
                    'createdAt' => (string) ($autosave['savedAt'] ?? ''),
                    'createdBy' => (string) ($autosave['savedBy'] ?? ''),
                ]
            );
        }

        return $versions;
    }

    /**
     * @return array<string, mixed>
     */
    public function version(int $workspaceId, int $pageId, int $revision): array
    {
        $this->requirePageInWorkspace($workspaceId, $pageId);
        $record = $this->store->read(
            $this->layout->pageVersionJson($workspaceId, $pageId, $revision),
            false
        );

        if ($record === null) {
            throw new HttpException(404, 'VERSION_NOT_FOUND', 'Die Seitenversion wurde nicht gefunden.');
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(
        int $workspaceId,
        int $pageId,
        int $revision,
        string $userId
    ): array {
        return $this->workspaces->withWorkspaceLocks(
            [$workspaceId],
            function () use ($workspaceId, $pageId, $revision, $userId): array {
                return $this->store->withLock(
                    $this->layout->pageLock($pageId),
                    function () use ($workspaceId, $pageId, $revision, $userId): array {
                        $workspace = $this->requirePageInWorkspace($workspaceId, $pageId);
                        $record = $this->version($workspaceId, $pageId, $revision);
                        $snapshot = $record['page'] ?? null;
                        if (!is_array($snapshot)) {
                            throw new HttpException(500, 'INVALID_VERSION', 'Die Seitenversion enthält keinen gültigen Snapshot.');
                        }

                        $published = $this->readPublished($workspaceId, $pageId);
                        $oldWorkspace = $workspace;
                        $oldAutosave = $this->readAutosave($workspaceId, $pageId);
                        $newRevision = (int) $published['revision'] + 1;
                        $snapshot['id'] = $pageId;
                        $snapshot['revision'] = $newRevision;
                        $snapshot['draftRevision'] = 0;
                        $snapshot['updatedAt'] = Text::now();
                        $snapshot['updatedBy'] = $userId;
                        $newVersionPath = $this->layout->pageVersionJson(
                            $workspaceId,
                            $pageId,
                            $newRevision
                        );

                        try {
                            $this->store->writeImmutable(
                                $newVersionPath,
                                $this->versionRecord(
                                    $snapshot,
                                    $newRevision,
                                    'Version ' . $revision . ' wiederhergestellt',
                                    'restore',
                                    $userId
                                )
                            );
                            $this->store->write(
                                $this->layout->pageJson($workspaceId, $pageId),
                                $snapshot
                            );

                            $key = array_key_exists((string) $pageId, $workspace['pageIndex']['pages'])
                                ? (string) $pageId
                                : $pageId;
                            $workspace['pageIndex']['pages'][$key]['title'] = (string) $snapshot['title'];
                            $workspace['pageIndex']['pages'][$key]['slug'] = (string) $snapshot['slug'];
                            $workspace['updatedAt'] = Text::now();
                            $workspace['updatedBy'] = $userId;
                            $this->workspaces->write($workspaceId, $workspace);

                            $autosavePath = $this->layout->pageAutosaveJson($workspaceId, $pageId);
                            if ($oldAutosave !== null) {
                                $this->store->write(
                                    $this->layout->pageAutosavePreviousJson($workspaceId, $pageId),
                                    $oldAutosave
                                );
                            }
                            if (is_file($autosavePath)) {
                                @unlink($autosavePath);
                            }
                        } catch (\Throwable $exception) {
                            try {
                                $this->store->write(
                                    $this->layout->pageJson($workspaceId, $pageId),
                                    $published
                                );
                                $this->workspaces->write($workspaceId, $oldWorkspace);
                                if ($oldAutosave !== null) {
                                    $this->store->write(
                                        $this->layout->pageAutosaveJson($workspaceId, $pageId),
                                        $oldAutosave
                                    );
                                }
                                @unlink($newVersionPath);
                            } catch (\Throwable) {
                                // Preserve the original exception.
                            }
                            throw $exception;
                        }

                        return $this->editorStateUnlocked($workspaceId, $pageId, $workspace);
                    }
                );
            }
        );
    }

    public function recoverPendingMoves(): void
    {
        $files = glob($this->layout->transactionsDir() . '/workspace-move-*.json') ?: [];
        if ($files === []) {
            return;
        }

        $this->store->withLock(
            $this->layout->workspaceMoveLock(),
            function () use ($files): void {
                foreach ($files as $file) {
                    $transaction = $this->store->read($file, false);
                    if ($transaction === null || ($transaction['type'] ?? null) !== 'workspace_page_move') {
                        continue;
                    }

                    $sourceId = (int) ($transaction['sourceWorkspaceId'] ?? 0);
                    $targetId = (int) ($transaction['targetWorkspaceId'] ?? 0);
                    $pageIds = array_map('intval', $transaction['pageIds'] ?? []);

                    $this->workspaces->withWorkspaceLocks(
                        [$sourceId, $targetId],
                        function () use ($transaction, $file, $pageIds): void {
                            $this->withPageLocks(
                                $pageIds,
                                fn () => $this->completeMoveTransaction($transaction, $file)
                            );
                        }
                    );
                }
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function moveInsideWorkspace(
        int $workspaceId,
        int $pageId,
        ?int $targetParentPageId,
        ?int $targetIndex,
        string $userId
    ): array {
        return $this->workspaces->withWorkspaceLocks(
            [$workspaceId],
            function () use (
                $workspaceId,
                $pageId,
                $targetParentPageId,
                $targetIndex,
                $userId
            ): array {
                $workspace = $this->requirePageInWorkspace($workspaceId, $pageId);
                $subtree = $this->workspaces->subtreeIds($workspace, $pageId);
                if ($targetParentPageId !== null && in_array($targetParentPageId, $subtree, true)) {
                    throw new HttpException(
                        422,
                        'PAGE_MOVE_CYCLE',
                        'Eine Seite kann nicht in ihren eigenen Unterbaum verschoben werden.'
                    );
                }

                $this->workspaces->removePlacement($workspace, $pageId);
                $this->workspaces->insertPlacement(
                    $workspace,
                    $pageId,
                    $targetParentPageId,
                    $targetIndex
                );
                $workspace['updatedAt'] = Text::now();
                $workspace['updatedBy'] = $userId;
                $this->workspaces->write($workspaceId, $workspace);

                return [
                    'sourceWorkspace' => $workspace,
                    'targetWorkspace' => $workspace,
                    'workspaceId' => $workspaceId,
                    'pageId' => $pageId,
                    'path' => '/workspaces/' . $workspaceId . '/pages/' . $pageId,
                ];
            }
        );
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function completeMoveTransaction(array $transaction, string $transactionPath): void
    {
        $sourceWorkspaceId = (int) $transaction['sourceWorkspaceId'];
        $targetWorkspaceId = (int) $transaction['targetWorkspaceId'];
        $pageIds = array_map('intval', $transaction['pageIds'] ?? []);
        $sourceAfter = $transaction['sourceAfter'] ?? null;
        $targetAfter = $transaction['targetAfter'] ?? null;

        if (!is_array($sourceAfter) || !is_array($targetAfter) || $pageIds === []) {
            throw new HttpException(500, 'INVALID_MOVE_TRANSACTION', 'Ein Verschiebejournal ist unvollständig.');
        }

        $this->store->ensureDirectory($this->layout->pagesDir($targetWorkspaceId));

        foreach ($pageIds as $pageId) {
            $source = $this->layout->pageDir($sourceWorkspaceId, $pageId);
            $target = $this->layout->pageDir($targetWorkspaceId, $pageId);

            if (is_dir($target) && !is_dir($source)) {
                continue;
            }
            if (is_dir($target) && is_dir($source)) {
                throw new HttpException(500, 'MOVE_DIRECTORY_COLLISION', 'Eine Seite liegt gleichzeitig in Quell- und Ziel-Workspace.');
            }
            if (!is_dir($source)) {
                throw new HttpException(500, 'MOVE_SOURCE_MISSING', 'Ein Seitenordner aus dem Verschiebejournal fehlt.');
            }
            if (!rename($source, $target)) {
                throw new HttpException(500, 'MOVE_DIRECTORY_FAILED', 'Ein Seitenordner konnte nicht verschoben werden.');
            }
        }

        $this->workspaces->write($sourceWorkspaceId, $sourceAfter);
        $this->workspaces->write($targetWorkspaceId, $targetAfter);

        if (is_file($transactionPath) && !unlink($transactionPath)) {
            throw new HttpException(
                500,
                'MOVE_JOURNAL_DELETE_FAILED',
                'Die Verschiebung wurde abgeschlossen, das Journal konnte aber nicht entfernt werden.'
            );
        }
    }

    /**
     * @param list<int> $pageIds
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withPageLocks(array $pageIds, callable $callback): mixed
    {
        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
        sort($pageIds, SORT_NUMERIC);

        $acquire = function (int $index) use (&$acquire, $pageIds, $callback): mixed {
            if (!isset($pageIds[$index])) {
                return $callback();
            }

            return $this->store->withLock(
                $this->layout->pageLock($pageIds[$index]),
                static fn () => $acquire($index + 1)
            );
        };

        return $acquire(0);
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePageInWorkspace(int $workspaceId, int $pageId): array
    {
        $workspace = $this->workspaces->get($workspaceId);
        if ($this->workspaces->containsPage($workspace, $pageId)) {
            return $workspace;
        }

        $currentWorkspaceId = $this->workspaces->locatePage($pageId);
        if ($currentWorkspaceId !== null) {
            throw new HttpException(
                409,
                'PAGE_MOVED',
                'Die Seite wurde in einen anderen Workspace verschoben.',
                [
                    'currentWorkspaceId' => $currentWorkspaceId,
                    'pageId' => $pageId,
                    'path' => '/workspaces/' . $currentWorkspaceId . '/pages/' . $pageId,
                ]
            );
        }

        throw new HttpException(404, 'PAGE_NOT_FOUND', 'Die Seite wurde nicht gefunden.');
    }

    /**
     * @return array<string, mixed>
     */
    private function readPublished(int $workspaceId, int $pageId): array
    {
        $page = $this->store->read($this->layout->pageJson($workspaceId, $pageId), false);
        if ($page === null) {
            throw new HttpException(500, 'PAGE_FILE_MISSING', 'Die Seitendatei fehlt.');
        }
        if ((int) ($page['id'] ?? 0) !== $pageId) {
            throw new HttpException(500, 'PAGE_FILE_ID_MISMATCH', 'Die Seitendatei enthält eine falsche ID.');
        }

        return $page;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readAutosave(int $workspaceId, int $pageId): ?array
    {
        return $this->store->read(
            $this->layout->pageAutosaveJson($workspaceId, $pageId),
            false
        );
    }

    /**
     * @param array<string, mixed> $workspace
     * @return array<string, mixed>
     */
    private function editorStateUnlocked(
        int $workspaceId,
        int $pageId,
        array $workspace
    ): array {
        $published = $this->readPublished($workspaceId, $pageId);
        $autosave = $this->readAutosave($workspaceId, $pageId);

        if (
            $autosave !== null
            && (int) ($autosave['baseRevision'] ?? 0) !== (int) $published['revision']
        ) {
            throw new HttpException(
                409,
                'BASE_REVISION_CONFLICT',
                'Der gespeicherte Entwurf basiert nicht mehr auf der aktuellen Seitenversion.',
                [
                    'draftBaseRevision' => (int) ($autosave['baseRevision'] ?? 0),
                    'currentRevision' => (int) $published['revision'],
                ]
            );
        }

        $page = $autosave['page'] ?? $published;
        if (!is_array($page)) {
            throw new HttpException(500, 'INVALID_PAGE_STATE', 'Der Seitenzustand ist ungültig.');
        }

        return [
            'workspace' => $workspace,
            'page' => $page,
            'hasDraft' => $autosave !== null,
            'publishedRevision' => (int) $published['revision'],
            'draftRevision' => (int) ($autosave['draftRevision'] ?? 0),
            'draftSavedAt' => $autosave['savedAt'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function versionRecord(
        array $page,
        int $revision,
        string $message,
        string $source,
        string $userId
    ): array {
        return [
            'schemaVersion' => 1,
            'pageId' => (int) $page['id'],
            'revision' => $revision,
            'message' => $message,
            'source' => $source,
            'createdAt' => Text::now(),
            'createdBy' => $userId,
            'page' => $page,
        ];
    }
}
