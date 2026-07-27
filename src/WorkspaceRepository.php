<?php

declare(strict_types=1);

namespace BKB\Domain;

use BKB\HttpException;
use BKB\Storage\AtomicJsonStore;
use BKB\Storage\DataLayout;
use BKB\Text;

final class WorkspaceRepository
{
    public function __construct(
        private readonly DataLayout $layout,
        private readonly AtomicJsonStore $store
    ) {
    }

    /**
     * @return list<int>
     */
    public function listWorkspaceIds(): array
    {
        $ids = [];
        $entries = glob($this->layout->workspacesDir() . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($entries as $directory) {
            $name = basename($directory);
            if (!ctype_digit($name)) {
                continue;
            }

            $id = (int) $name;
            if ($id > 100 && is_file($this->layout->workspaceJson($id))) {
                $ids[] = $id;
            }
        }

        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @return list<array{id:int,title:string,createdAt:string,updatedAt:string,pageCount:int}>
     */
    public function list(): array
    {
        $workspaces = [];

        foreach ($this->listWorkspaceIds() as $workspaceId) {
            $workspace = $this->get($workspaceId);
            $workspaces[] = [
                'id' => $workspaceId,
                'title' => (string) $workspace['title'],
                'createdAt' => (string) $workspace['createdAt'],
                'updatedAt' => (string) $workspace['updatedAt'],
                'pageCount' => count($workspace['pageIndex']['pages']),
            ];
        }

        usort(
            $workspaces,
            static fn (array $left, array $right): int => strcasecmp($left['title'], $right['title'])
        );

        return $workspaces;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $workspaceId): array
    {
        try {
            DataLayout::assertNumericId($workspaceId);
        } catch (\InvalidArgumentException) {
            throw new HttpException(404, 'WORKSPACE_NOT_FOUND', 'Der Workspace wurde nicht gefunden.');
        }

        $workspace = $this->store->read($this->layout->workspaceJson($workspaceId), false);
        if ($workspace === null) {
            throw new HttpException(404, 'WORKSPACE_NOT_FOUND', 'Der Workspace wurde nicht gefunden.');
        }

        $this->validate($workspace, $workspaceId);
        return $workspace;
    }

    /**
     * Must be called while the global workspace-ID lock is held.
     *
     * @return array<string, mixed>
     */
    public function createWithId(int $workspaceId, string $title, string $userId): array
    {
        DataLayout::assertNumericId($workspaceId);
        $title = Text::requiredString($title, 'title', 120);

        if (is_dir($this->layout->workspaceDir($workspaceId))) {
            throw new HttpException(409, 'WORKSPACE_ID_COLLISION', 'Die erzeugte Workspace-ID ist bereits vergeben.');
        }

        $now = Text::now();
        $workspace = [
            'schemaVersion' => 1,
            'id' => $workspaceId,
            'title' => $title,
            'createdAt' => $now,
            'createdBy' => $userId,
            'updatedAt' => $now,
            'updatedBy' => $userId,
            'pageIndex' => [
                'rootPageIds' => [],
                'pages' => [],
                'retiredPageIds' => [],
            ],
        ];

        return $this->store->withLock(
            $this->layout->workspaceLock($workspaceId),
            function () use ($workspaceId, $workspace): array {
                foreach (
                    [
                        $this->layout->workspaceDir($workspaceId),
                        $this->layout->pagesDir($workspaceId),
                        $this->layout->workspaceDir($workspaceId) . '/assets',
                        $this->layout->workspaceDir($workspaceId) . '/shares',
                        $this->layout->workspaceDir($workspaceId) . '/devices',
                        $this->layout->workspaceDir($workspaceId) . '/logs',
                        $this->layout->workspaceDir($workspaceId) . '/trash/pages',
                    ] as $directory
                ) {
                    $this->store->ensureDirectory($directory);
                }

                $this->store->write($this->layout->workspaceJson($workspaceId), $workspace);
                return $workspace;
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rename(int $workspaceId, string $title, string $userId): array
    {
        $title = Text::requiredString($title, 'title', 120);

        return $this->withWorkspaceLocks(
            [$workspaceId],
            function () use ($workspaceId, $title, $userId): array {
                $workspace = $this->get($workspaceId);
                $workspace['title'] = $title;
                $workspace['updatedAt'] = Text::now();
                $workspace['updatedBy'] = $userId;
                $this->write($workspaceId, $workspace);
                return $workspace;
            }
        );
    }

    /**
     * @param list<int> $workspaceIds
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withWorkspaceLocks(array $workspaceIds, callable $callback): mixed
    {
        $workspaceIds = array_values(array_unique(array_map('intval', $workspaceIds)));
        sort($workspaceIds, SORT_NUMERIC);

        $acquire = function (int $index) use (&$acquire, $workspaceIds, $callback): mixed {
            if (!isset($workspaceIds[$index])) {
                return $callback();
            }

            return $this->store->withLock(
                $this->layout->workspaceLock($workspaceIds[$index]),
                static fn () => $acquire($index + 1)
            );
        };

        return $acquire(0);
    }

    /**
     * Caller must hold the matching workspace lock.
     *
     * @param array<string, mixed> $workspace
     */
    public function write(int $workspaceId, array $workspace): void
    {
        $workspace['updatedAt'] = $workspace['updatedAt'] ?? Text::now();
        $this->validate($workspace, $workspaceId);
        $this->store->write(
            $this->layout->workspaceJson($workspaceId),
            $workspace,
            $this->layout->workspacePreviousJson($workspaceId)
        );
    }

    public function containsPage(array $workspace, int $pageId): bool
    {
        return array_key_exists((string) $pageId, $workspace['pageIndex']['pages'])
            || array_key_exists($pageId, $workspace['pageIndex']['pages']);
    }

    public function locatePage(int $pageId): ?int
    {
        foreach ($this->listWorkspaceIds() as $workspaceId) {
            $workspace = $this->get($workspaceId);
            if ($this->containsPage($workspace, $pageId)) {
                return $workspaceId;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $workspace
     */
    public function parentOf(array $workspace, int $pageId): ?int
    {
        foreach ($workspace['pageIndex']['rootPageIds'] as $rootId) {
            if ((int) $rootId === $pageId) {
                return null;
            }
        }

        foreach ($workspace['pageIndex']['pages'] as $candidateId => $entry) {
            foreach (($entry['children'] ?? []) as $childId) {
                if ((int) $childId === $pageId) {
                    return (int) $candidateId;
                }
            }
        }

        throw new HttpException(500, 'PAGE_INDEX_ORPHAN', 'Die Seite ist im Workspace-Index verwaist.');
    }

    /**
     * @param array<string, mixed> $workspace
     * @return list<int>
     */
    public function subtreeIds(array $workspace, int $rootPageId): array
    {
        if (!$this->containsPage($workspace, $rootPageId)) {
            throw new HttpException(404, 'PAGE_NOT_FOUND', 'Die Seite wurde im Workspace nicht gefunden.');
        }

        $result = [];
        $walk = function (int $pageId) use (&$walk, &$result, $workspace): void {
            $result[] = $pageId;
            $entry = $workspace['pageIndex']['pages'][(string) $pageId]
                ?? $workspace['pageIndex']['pages'][$pageId]
                ?? null;

            if (!is_array($entry)) {
                throw new HttpException(500, 'INVALID_PAGE_INDEX', 'Der Workspace-Index ist beschädigt.');
            }

            foreach (($entry['children'] ?? []) as $childId) {
                $walk((int) $childId);
            }
        };

        $walk($rootPageId);
        return $result;
    }

    /**
     * @param array<string, mixed> $workspace
     */
    public function removePlacement(array &$workspace, int $pageId): void
    {
        $workspace['pageIndex']['rootPageIds'] = array_values(
            array_filter(
                $workspace['pageIndex']['rootPageIds'],
                static fn (mixed $candidate): bool => (int) $candidate !== $pageId
            )
        );

        foreach ($workspace['pageIndex']['pages'] as &$entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entry['children'] = array_values(
                array_filter(
                    $entry['children'] ?? [],
                    static fn (mixed $candidate): bool => (int) $candidate !== $pageId
                )
            );
        }
        unset($entry);
    }

    /**
     * @param array<string, mixed> $workspace
     */
    public function insertPlacement(
        array &$workspace,
        int $pageId,
        ?int $parentPageId,
        ?int $targetIndex
    ): void {
        if ($parentPageId === null) {
            $siblings =& $workspace['pageIndex']['rootPageIds'];
        } else {
            if (!$this->containsPage($workspace, $parentPageId)) {
                throw new HttpException(422, 'TARGET_PARENT_NOT_FOUND', 'Die gewählte Zielseite wurde nicht gefunden.');
            }

            $key = array_key_exists((string) $parentPageId, $workspace['pageIndex']['pages'])
                ? (string) $parentPageId
                : $parentPageId;
            $siblings =& $workspace['pageIndex']['pages'][$key]['children'];
        }

        $position = $targetIndex === null
            ? count($siblings)
            : max(0, min($targetIndex, count($siblings)));
        array_splice($siblings, $position, 0, [$pageId]);
    }

    /**
     * @param array<string, mixed> $workspace
     */
    public function validate(array $workspace, int $expectedId): void
    {
        if ((int) ($workspace['id'] ?? 0) !== $expectedId) {
            throw new HttpException(500, 'WORKSPACE_ID_MISMATCH', 'Die Workspace-Datei enthält eine falsche ID.');
        }

        if (!isset($workspace['title']) || !is_string($workspace['title']) || trim($workspace['title']) === '') {
            throw new HttpException(500, 'INVALID_WORKSPACE_TITLE', 'Der Workspace besitzt keinen gültigen Titel.');
        }

        $index = $workspace['pageIndex'] ?? null;
        if (!is_array($index) || array_is_list($index)) {
            throw new HttpException(500, 'INVALID_PAGE_INDEX', 'Der Workspace besitzt keinen gültigen Seitenindex.');
        }

        $roots = $index['rootPageIds'] ?? null;
        $pages = $index['pages'] ?? null;
        $retired = $index['retiredPageIds'] ?? null;
        if (
            !is_array($roots)
            || !array_is_list($roots)
            || !is_array($pages)
            || !is_array($retired)
            || !array_is_list($retired)
        ) {
            throw new HttpException(500, 'INVALID_PAGE_INDEX', 'Der Workspace-Seitenindex ist unvollständig.');
        }

        $normalizedPages = [];
        foreach ($pages as $key => $entry) {
            if ((!is_string($key) && !is_int($key)) || !ctype_digit((string) $key)) {
                throw new HttpException(500, 'INVALID_PAGE_INDEX_KEY', 'Der Seitenindex enthält eine ungültige Page-ID.');
            }
            $pageId = (int) $key;
            try {
                DataLayout::assertNumericId($pageId);
            } catch (\InvalidArgumentException) {
                throw new HttpException(500, 'INVALID_PAGE_INDEX_KEY', 'Der Seitenindex enthält eine ungültige Page-ID.');
            }

            if (
                !is_array($entry)
                || !isset($entry['title'], $entry['slug'], $entry['children'])
                || !is_string($entry['title'])
                || !is_string($entry['slug'])
                || !is_array($entry['children'])
                || !array_is_list($entry['children'])
            ) {
                throw new HttpException(500, 'INVALID_PAGE_INDEX_ENTRY', 'Ein Seiteneintrag im Workspace-Index ist ungültig.');
            }

            $normalizedPages[$pageId] = $entry;
        }

        $placements = [];
        foreach ($roots as $rootId) {
            $this->registerPlacement($rootId, $normalizedPages, $placements);
        }

        foreach ($normalizedPages as $entry) {
            foreach ($entry['children'] as $childId) {
                $this->registerPlacement($childId, $normalizedPages, $placements);
            }
        }

        foreach (array_keys($normalizedPages) as $pageId) {
            if (($placements[$pageId] ?? 0) !== 1) {
                throw new HttpException(
                    500,
                    'INVALID_PAGE_PLACEMENT',
                    'Jede Page-ID muss im Workspace-Index genau einmal eingeordnet sein.',
                    ['pageId' => $pageId]
                );
            }
        }

        $visiting = [];
        $visited = [];
        $visit = function (int $pageId) use (&$visit, &$visiting, &$visited, $normalizedPages): void {
            if (isset($visiting[$pageId])) {
                throw new HttpException(500, 'PAGE_INDEX_CYCLE', 'Der Seitenindex enthält einen Zyklus.');
            }
            if (isset($visited[$pageId])) {
                return;
            }

            $visiting[$pageId] = true;
            foreach ($normalizedPages[$pageId]['children'] as $childId) {
                $visit((int) $childId);
            }
            unset($visiting[$pageId]);
            $visited[$pageId] = true;
        };

        foreach ($roots as $rootId) {
            $visit((int) $rootId);
        }

        $retiredSeen = [];
        foreach ($retired as $retiredId) {
            if (!is_int($retiredId) && !(is_string($retiredId) && ctype_digit($retiredId))) {
                throw new HttpException(500, 'INVALID_RETIRED_PAGE_ID', 'Eine stillgelegte Page-ID ist ungültig.');
            }
            $retiredId = (int) $retiredId;
            try {
                DataLayout::assertNumericId($retiredId);
            } catch (\InvalidArgumentException) {
                throw new HttpException(500, 'INVALID_RETIRED_PAGE_ID', 'Eine stillgelegte Page-ID ist ungültig.');
            }
            if (isset($normalizedPages[$retiredId]) || isset($retiredSeen[$retiredId])) {
                throw new HttpException(500, 'INVALID_RETIRED_PAGE_ID', 'Eine stillgelegte Page-ID ist ungültig oder doppelt.');
            }
            $retiredSeen[$retiredId] = true;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @param array<int, int> $placements
     */
    private function registerPlacement(mixed $candidate, array $pages, array &$placements): void
    {
        if (!is_int($candidate) && !(is_string($candidate) && ctype_digit($candidate))) {
            throw new HttpException(500, 'INVALID_PAGE_REFERENCE', 'Der Seitenindex enthält eine ungültige Referenz.');
        }

        $pageId = (int) $candidate;
        if (!isset($pages[$pageId])) {
            throw new HttpException(
                500,
                'MISSING_PAGE_INDEX_ENTRY',
                'Der Seitenindex verweist auf eine unbekannte Seite.',
                ['pageId' => $pageId]
            );
        }

        $placements[$pageId] = ($placements[$pageId] ?? 0) + 1;
        if ($placements[$pageId] > 1) {
            throw new HttpException(
                500,
                'DUPLICATE_PAGE_PLACEMENT',
                'Eine Page-ID ist im Workspace-Index mehrfach eingeordnet.',
                ['pageId' => $pageId]
            );
        }
    }
}
