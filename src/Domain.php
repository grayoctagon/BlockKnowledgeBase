<?php

declare(strict_types=1);

namespace BKB\Domain;

use BKB\HttpException;
use BKB\Storage\AtomicJsonStore;
use BKB\Storage\DataLayout;
use BKB\Text;

final class PageValidator
{
    private const ALLOWED_TYPES = ['heading', 'raw_text', 'markdown'];

    public function __construct(private readonly int $maxBlockContentBytes)
    {
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $publishedPage
     * @return array<string, mixed>
     */
    public function normalizeDraft(
        array $page,
        array $publishedPage,
        int $pageId,
        string $userId
    ): array {
        if ((int) ($page['id'] ?? 0) !== $pageId) {
            throw new HttpException(422, 'PAGE_ID_MISMATCH', 'Die Seiten-ID im Entwurf stimmt nicht mit dem Pfad überein.');
        }

        $title = Text::requiredString($page['title'] ?? '', 'title', 180);
        $labels = $this->normalizeLabels($page['labels'] ?? []);
        $blocks = $page['blocks'] ?? null;

        if (!is_array($blocks) || !array_is_list($blocks)) {
            throw new HttpException(422, 'INVALID_BLOCK_LIST', 'Die Blöcke müssen als JSON-Liste übertragen werden.');
        }

        $existingBlocks = [];
        foreach (($publishedPage['blocks'] ?? []) as $existingBlock) {
            if (is_array($existingBlock) && isset($existingBlock['id']) && is_string($existingBlock['id'])) {
                $existingBlocks[$existingBlock['id']] = $existingBlock;
            }
        }

        $normalizedBlocks = [];
        $seenIds = [];
        foreach ($blocks as $position => $block) {
            if (!is_array($block) || array_is_list($block)) {
                throw new HttpException(
                    422,
                    'INVALID_BLOCK',
                    'Jeder Block muss ein JSON-Objekt sein.',
                    ['position' => $position]
                );
            }

            $normalized = $this->normalizeBlock($block, $existingBlocks, $userId, $position);
            if (isset($seenIds[$normalized['id']])) {
                throw new HttpException(
                    422,
                    'DUPLICATE_BLOCK_ID',
                    'Eine Block-ID kommt innerhalb der Seite mehrfach vor.',
                    ['blockId' => $normalized['id']]
                );
            }

            $seenIds[$normalized['id']] = true;
            $normalizedBlocks[] = $normalized;
        }

        return [
            'schemaVersion' => 1,
            'id' => $pageId,
            'title' => $title,
            'slug' => Text::slug($title, $pageId),
            'revision' => (int) ($publishedPage['revision'] ?? 1),
            'draftRevision' => max(0, (int) ($page['draftRevision'] ?? 0)),
            'createdAt' => (string) ($publishedPage['createdAt'] ?? Text::now()),
            'createdBy' => (string) ($publishedPage['createdBy'] ?? $userId),
            'updatedAt' => Text::now(),
            'updatedBy' => $userId,
            'labels' => $labels,
            'blocks' => $normalizedBlocks,
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, array<string, mixed>> $existingBlocks
     * @return array<string, mixed>
     */
    private function normalizeBlock(
        array $block,
        array $existingBlocks,
        string $userId,
        int $position
    ): array {
        $id = $block['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/', $id)) {
            throw new HttpException(
                422,
                'INVALID_BLOCK_ID',
                'Block-IDs müssen aus 64 kleingeschriebenen Hexadezimalzeichen bestehen.',
                ['position' => $position]
            );
        }

        $type = $block['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::ALLOWED_TYPES, true)) {
            throw new HttpException(
                422,
                'UNSUPPORTED_BLOCK_TYPE',
                'Dieser Blocktyp ist in der ersten Version nicht freigeschaltet.',
                ['position' => $position, 'type' => $type]
            );
        }

        $content = $block['content'] ?? '';
        if (!is_string($content)) {
            throw new HttpException(
                422,
                'INVALID_BLOCK_CONTENT',
                'Der Blockinhalt muss Text sein.',
                ['blockId' => $id]
            );
        }
        if (strlen($content) > $this->maxBlockContentBytes) {
            throw new HttpException(
                413,
                'BLOCK_CONTENT_TOO_LARGE',
                'Der Blockinhalt überschreitet die erlaubte Größe.',
                ['blockId' => $id]
            );
        }

        $settings = $block['settings'] ?? [];
        if (!is_array($settings) || array_is_list($settings)) {
            throw new HttpException(422, 'INVALID_BLOCK_SETTINGS', 'Die Blockeinstellungen müssen ein JSON-Objekt sein.');
        }

        $normalizedSettings = match ($type) {
            'heading' => [
                'level' => $this->integerInRange($settings['level'] ?? 1, 1, 6, 'level'),
                'includeInToc' => (bool) ($settings['includeInToc'] ?? true),
                'anchor' => Text::optionalString($settings['anchor'] ?? null, 'anchor', 120),
            ],
            'raw_text' => [
                'wrap' => (bool) ($settings['wrap'] ?? true),
            ],
            'markdown' => [
                'editorMode' => $this->enum(
                    $settings['editorMode'] ?? 'split',
                    ['raw', 'split', 'preview'],
                    'editorMode'
                ),
            ],
        };

        $now = Text::now();
        $existing = $existingBlocks[$id] ?? null;
        $createdAt = is_array($existing)
            ? (string) (($existing['meta']['createdAt'] ?? null) ?: $now)
            : $now;
        $createdBy = is_array($existing)
            ? (string) (($existing['meta']['createdBy'] ?? null) ?: $userId)
            : $userId;

        $unchanged = is_array($existing)
            && ($existing['type'] ?? null) === $type
            && ($existing['content'] ?? null) === $content
            && ($existing['settings'] ?? null) === $normalizedSettings;

        return [
            'id' => $id,
            'type' => $type,
            'content' => $content,
            'settings' => $normalizedSettings,
            'meta' => [
                'createdAt' => $createdAt,
                'createdBy' => $createdBy,
                'updatedAt' => $unchanged
                    ? (string) (($existing['meta']['updatedAt'] ?? null) ?: $createdAt)
                    : $now,
                'updatedBy' => $unchanged
                    ? (string) (($existing['meta']['updatedBy'] ?? null) ?: $createdBy)
                    : $userId,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeLabels(mixed $labels): array
    {
        if (!is_array($labels) || !array_is_list($labels)) {
            throw new HttpException(422, 'INVALID_LABELS', 'Labels müssen als Liste übertragen werden.');
        }

        $normalized = [];
        foreach ($labels as $label) {
            if (!is_string($label)) {
                throw new HttpException(422, 'INVALID_LABEL', 'Jedes Label muss Text sein.');
            }

            $label = trim($label);
            if ($label === '') {
                continue;
            }
            if (Text::length($label) > 64) {
                throw new HttpException(422, 'INVALID_LABEL', 'Ein Label darf höchstens 64 Zeichen lang sein.');
            }
            $normalized[$label] = true;
        }

        return array_keys($normalized);
    }

    private function integerInRange(mixed $value, int $min, int $max, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new HttpException(422, 'INVALID_FIELD', "Das Feld „{$field}“ muss eine Ganzzahl sein.");
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new HttpException(
                422,
                'INVALID_FIELD',
                "Das Feld „{$field}“ muss zwischen {$min} und {$max} liegen."
            );
        }

        return $number;
    }

    /**
     * @param list<string> $allowed
     */
    private function enum(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new HttpException(
                422,
                'INVALID_FIELD',
                "Das Feld „{$field}“ enthält keinen erlaubten Wert."
            );
        }

        return $value;
    }
}

final class IdAllocator
{
    public function __construct(
        private readonly DataLayout $layout,
        private readonly AtomicJsonStore $store,
        private readonly WorkspaceRepository $workspaces
    ) {
    }

    /**
     * The callback runs while the global workspace-ID lock is held.
     *
     * @template T
     * @param callable(int):T $callback
     * @return T
     */
    public function withNewWorkspaceId(callable $callback): mixed
    {
        return $this->store->withLock(
            $this->layout->workspaceIdLock(),
            function () use ($callback): mixed {
                $used = [];
                foreach ($this->workspaces->listWorkspaceIds() as $workspaceId) {
                    $used[$workspaceId] = true;
                }

                return $callback($this->randomUnusedId($used));
            }
        );
    }

    /**
     * The callback runs while the global page-ID lock is held.
     *
     * @template T
     * @param callable(int):T $callback
     * @return T
     */
    public function withNewPageId(callable $callback): mixed
    {
        return $this->store->withLock(
            $this->layout->pageIdLock(),
            function () use ($callback): mixed {
                $used = [];
                foreach ($this->workspaces->listWorkspaceIds() as $workspaceId) {
                    $workspace = $this->workspaces->get($workspaceId);
                    foreach (array_keys($workspace['pageIndex']['pages'] ?? []) as $pageId) {
                        $used[(int) $pageId] = true;
                    }
                    foreach (($workspace['pageIndex']['retiredPageIds'] ?? []) as $pageId) {
                        $used[(int) $pageId] = true;
                    }
                }

                return $callback($this->randomUnusedId($used));
            }
        );
    }

    public function blockId(int $pageId): string
    {
        DataLayout::assertNumericId($pageId);
        return hash('sha256', $pageId . '|' . hrtime(true) . '|' . random_bytes(32));
    }

    /**
     * @param array<int, bool> $used
     */
    private function randomUnusedId(array $used): int
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $candidate = random_int(101, 999_999_999_999);
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }

        throw new HttpException(503, 'ID_ALLOCATION_FAILED', 'Es konnte keine freie ID erzeugt werden.');
    }
}
