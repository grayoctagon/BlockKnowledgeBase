<?php

declare(strict_types=1);

namespace BKB;

use BKB\Domain\IdAllocator;
use BKB\Domain\PageRepository;
use BKB\Domain\WorkspaceRepository;

final class ApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly WorkspaceRepository $workspaces,
        private readonly PageRepository $pages,
        private readonly IdAllocator $ids
    ) {
    }

    public function handle(): never
    {
        try {
            $this->dispatch();
        } catch (HttpException $exception) {
            Response::error(
                $exception->status,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details
            );
        } catch (\InvalidArgumentException $exception) {
            Response::error(422, 'INVALID_ARGUMENT', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log(
                sprintf(
                    '[BKB] %s in %s:%d',
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                )
            );
            Response::error(500, 'INTERNAL_ERROR', 'Die Anfrage konnte wegen eines internen Fehlers nicht abgeschlossen werden.');
        }
    }

    private function dispatch(): never
    {
        $method = Request::method();
        $path = Request::path();

        if ($method === 'GET' && $path === '/api/session') {
            $user = $this->auth->currentUser();
            Response::success([
                'configured' => $this->auth->isConfigured(),
                'authenticated' => $user !== null,
                'user' => $user,
                'csrfToken' => $this->auth->csrfToken(),
            ]);
        }

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->auth->requireValidCsrf(Request::header('X-CSRF-Token'));
        }

        if ($method === 'POST' && $path === '/api/setup') {
            $body = Request::json();
            $user = $this->auth->setupInitialAdmin(
                (string) ($body['username'] ?? ''),
                (string) ($body['displayName'] ?? ''),
                (string) ($body['password'] ?? '')
            );

            $workspace = $this->ids->withNewWorkspaceId(
                fn (int $workspaceId): array => $this->workspaces->createWithId(
                    $workspaceId,
                    'Privat',
                    $user['id']
                )
            );
            $pageResult = $this->pages->create(
                (int) $workspace['id'],
                'Willkommen',
                null,
                $user['id']
            );

            Response::success(
                [
                    'user' => $user,
                    'csrfToken' => $this->auth->csrfToken(),
                    'workspace' => $workspace,
                    'page' => $pageResult['page'],
                    'path' => $pageResult['path'],
                ],
                201
            );
        }

        if ($method === 'POST' && $path === '/api/login') {
            $body = Request::json();
            $user = $this->auth->login(
                (string) ($body['username'] ?? ''),
                (string) ($body['password'] ?? '')
            );
            Response::success([
                'user' => $user,
                'csrfToken' => $this->auth->csrfToken(),
            ]);
        }

        if ($method === 'POST' && $path === '/api/logout') {
            $this->auth->logout();
            Response::success(['loggedOut' => true]);
        }

        $user = $this->auth->requireUser();
        $this->pages->recoverPendingMoves();

        if ($method === 'GET' && $path === '/api/v1/workspaces') {
            Response::success(['workspaces' => $this->workspaces->list()]);
        }

        if ($method === 'POST' && $path === '/api/v1/workspaces') {
            $body = Request::json();
            $title = Text::requiredString($body['title'] ?? '', 'title', 120);
            $workspace = $this->ids->withNewWorkspaceId(
                fn (int $workspaceId): array => $this->workspaces->createWithId(
                    $workspaceId,
                    $title,
                    $user['id']
                )
            );
            Response::success(['workspace' => $workspace], 201);
        }

        if (preg_match('#^/api/v1/workspaces/([0-9]+)$#', $path, $matches)) {
            $workspaceId = $this->numericId($matches[1]);

            if ($method === 'GET') {
                Response::success(['workspace' => $this->workspaces->get($workspaceId)]);
            }

            if ($method === 'PATCH') {
                $body = Request::json();
                Response::success([
                    'workspace' => $this->workspaces->rename(
                        $workspaceId,
                        (string) ($body['title'] ?? ''),
                        $user['id']
                    ),
                ]);
            }
        }

        if (preg_match('#^/api/v1/workspaces/([0-9]+)/pages$#', $path, $matches)) {
            $workspaceId = $this->numericId($matches[1]);

            if ($method === 'POST') {
                $body = Request::json();
                $parentPageId = $this->nullableNumericId($body['parentPageId'] ?? null);
                Response::success(
                    $this->pages->create(
                        $workspaceId,
                        (string) ($body['title'] ?? ''),
                        $parentPageId,
                        $user['id']
                    ),
                    201
                );
            }
        }

        if (preg_match('#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)$#', $path, $matches)) {
            $workspaceId = $this->numericId($matches[1]);
            $pageId = $this->numericId($matches[2]);

            if ($method === 'GET') {
                Response::success($this->pages->editorState($workspaceId, $pageId));
            }

            if ($method === 'PATCH') {
                $body = Request::json();
                if (!array_key_exists('title', $body)) {
                    throw new HttpException(422, 'MISSING_FIELD', 'Für diese Änderung wird das Feld „title“ benötigt.');
                }
                Response::success(
                    $this->pages->rename(
                        $workspaceId,
                        $pageId,
                        (string) $body['title'],
                        $user['id']
                    )
                );
            }

            if ($method === 'DELETE') {
                Response::success($this->pages->delete($workspaceId, $pageId, $user['id']));
            }
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/move$#',
                $path,
                $matches
            )
            && $method === 'POST'
        ) {
            $body = Request::json();
            $targetIndex = $body['targetIndex'] ?? null;
            if ($targetIndex !== null && (!is_int($targetIndex) || $targetIndex < 0)) {
                throw new HttpException(422, 'INVALID_TARGET_INDEX', 'Die Zielposition muss eine nichtnegative Ganzzahl sein.');
            }

            Response::success(
                $this->pages->move(
                    $this->numericId($matches[1]),
                    $this->numericId($matches[2]),
                    $this->numericId((string) ($body['targetWorkspaceId'] ?? '')),
                    $this->nullableNumericId($body['targetParentPageId'] ?? null),
                    $targetIndex,
                    $user['id']
                )
            );
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/draft$#',
                $path,
                $matches
            )
        ) {
            $workspaceId = $this->numericId($matches[1]);
            $pageId = $this->numericId($matches[2]);

            if ($method === 'PATCH') {
                $body = Request::json();
                if (!is_int($body['baseDraftRevision'] ?? null)) {
                    throw new HttpException(422, 'INVALID_DRAFT_REVISION', 'Die Basis-Entwurfsrevision fehlt oder ist ungültig.');
                }
                if (!is_array($body['page'] ?? null) || array_is_list($body['page'])) {
                    throw new HttpException(422, 'INVALID_PAGE', 'Der Seitenentwurf muss ein JSON-Objekt sein.');
                }

                Response::success(
                    $this->pages->saveDraft(
                        $workspaceId,
                        $pageId,
                        $body['baseDraftRevision'],
                        $body['page'],
                        $user['id']
                    )
                );
            }

            if ($method === 'DELETE') {
                Response::success($this->pages->discardDraft($workspaceId, $pageId));
            }
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/versions$#',
                $path,
                $matches
            )
        ) {
            $workspaceId = $this->numericId($matches[1]);
            $pageId = $this->numericId($matches[2]);

            if ($method === 'GET') {
                Response::success([
                    'versions' => $this->pages->listVersions($workspaceId, $pageId),
                ]);
            }

            if ($method === 'POST') {
                $body = Request::json();
                if (!is_int($body['baseDraftRevision'] ?? null)) {
                    throw new HttpException(422, 'INVALID_DRAFT_REVISION', 'Die Basis-Entwurfsrevision fehlt oder ist ungültig.');
                }
                Response::success(
                    $this->pages->saveVersion(
                        $workspaceId,
                        $pageId,
                        $body['baseDraftRevision'],
                        isset($body['message']) ? (string) $body['message'] : null,
                        $user['id']
                    ),
                    201
                );
            }
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/versions/([0-9]+)$#',
                $path,
                $matches
            )
            && $method === 'GET'
        ) {
            Response::success([
                'version' => $this->pages->version(
                    $this->numericId($matches[1]),
                    $this->numericId($matches[2]),
                    $this->revision($matches[3])
                ),
            ]);
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/versions/([0-9]+)/restore$#',
                $path,
                $matches
            )
            && $method === 'POST'
        ) {
            Response::success(
                $this->pages->restore(
                    $this->numericId($matches[1]),
                    $this->numericId($matches[2]),
                    $this->revision($matches[3]),
                    $user['id']
                ),
                201
            );
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/block-ids$#',
                $path,
                $matches
            )
            && $method === 'POST'
        ) {
            Response::success(
                [
                    'blockId' => $this->pages->newBlockId(
                        $this->numericId($matches[1]),
                        $this->numericId($matches[2])
                    ),
                ],
                201
            );
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/blocks$#',
                $path,
                $matches
            )
            && $method === 'GET'
        ) {
            $state = $this->pages->editorState(
                $this->numericId($matches[1]),
                $this->numericId($matches[2])
            );
            $blocks = $state['page']['blocks'] ?? [];
            $type = isset($_GET['type']) ? trim((string) $_GET['type']) : null;
            if ($type !== null && $type !== '') {
                $blocks = array_values(
                    array_filter(
                        $blocks,
                        static fn (mixed $block): bool => is_array($block)
                            && ($block['type'] ?? null) === $type
                    )
                );
            }

            $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 500;
            Response::success([
                'workspaceId' => (int) $matches[1],
                'pageId' => (int) $matches[2],
                'blocks' => array_slice($blocks, 0, $limit),
            ]);
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/blocks/([a-f0-9]{64})$#',
                $path,
                $matches
            )
            && $method === 'GET'
        ) {
            $state = $this->pages->editorState(
                $this->numericId($matches[1]),
                $this->numericId($matches[2])
            );
            $block = $this->findBlock($state['page']['blocks'] ?? [], $matches[3]);
            if ($block === null) {
                throw new HttpException(404, 'BLOCK_NOT_FOUND', 'Der Block wurde nicht gefunden.');
            }
            Response::success(['block' => $block]);
        }

        if (
            preg_match(
                '#^/api/v1/workspaces/([0-9]+)/pages/([0-9]+)/blocks/([a-f0-9]{64})/content$#',
                $path,
                $matches
            )
            && $method === 'GET'
        ) {
            $state = $this->pages->editorState(
                $this->numericId($matches[1]),
                $this->numericId($matches[2])
            );
            $block = $this->findBlock($state['page']['blocks'] ?? [], $matches[3]);
            if ($block === null) {
                throw new HttpException(404, 'BLOCK_NOT_FOUND', 'Der Block wurde nicht gefunden.');
            }
            if (($block['type'] ?? null) !== 'raw_text') {
                throw new HttpException(406, 'NOT_RAW_TEXT', 'Dieser Endpunkt liefert ausschließlich Raw-Text-Blöcke.');
            }

            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo (string) ($block['content'] ?? '');
            exit;
        }

        throw new HttpException(404, 'ROUTE_NOT_FOUND', 'Der API-Endpunkt wurde nicht gefunden.');
    }

    private function numericId(string $value): int
    {
        if (!preg_match('/^[0-9]{3,12}$/', $value)) {
            throw new HttpException(422, 'INVALID_ID', 'Eine numerische ID ist ungültig.');
        }

        $id = (int) $value;
        if ($id <= 100 || $id > 999_999_999_999) {
            throw new HttpException(422, 'INVALID_ID', 'Eine numerische ID ist ungültig.');
        }

        return $id;
    }

    private function nullableNumericId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new HttpException(422, 'INVALID_ID', 'Eine numerische ID ist ungültig.');
        }

        return $this->numericId((string) $value);
    }

    private function revision(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new HttpException(422, 'INVALID_REVISION', 'Die Revisionsnummer ist ungültig.');
        }

        return (int) $value;
    }

    /**
     * @param mixed $blocks
     * @return array<string, mixed>|null
     */
    private function findBlock(mixed $blocks, string $blockId): ?array
    {
        if (!is_array($blocks)) {
            return null;
        }

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $blockId) {
                return $block;
            }
            $nested = $this->findBlock($block['children'] ?? null, $blockId);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }
}
