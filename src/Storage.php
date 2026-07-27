<?php

declare(strict_types=1);

namespace BKB\Storage;

use BKB\HttpException;

final class AtomicJsonStore
{
    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $path, bool $required = true): ?array
    {
        if (!is_file($path)) {
            if ($required) {
                throw new HttpException(404, 'FILE_NOT_FOUND', 'Die angeforderte Datendatei wurde nicht gefunden.');
            }

            return null;
        }

        $size = filesize($path);
        if ($size === false || $size > $this->maxBytes) {
            throw new HttpException(500, 'JSON_FILE_TOO_LARGE', 'Eine Datendatei überschreitet die erlaubte Größe.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new HttpException(500, 'JSON_READ_FAILED', 'Eine Datendatei konnte nicht gelesen werden.');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new HttpException(
                500,
                'INVALID_STORED_JSON',
                'Eine gespeicherte Datendatei enthält ungültiges JSON.',
                ['file' => basename($path), 'jsonError' => $exception->getMessage()]
            );
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new HttpException(500, 'INVALID_STORED_OBJECT', 'Eine gespeicherte Datendatei ist kein JSON-Objekt.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function write(string $path, array $data, ?string $previousPath = null): void
    {
        $bytes = $this->encode($data);

        if ($previousPath !== null && is_file($path)) {
            $previousBytes = file_get_contents($path);
            if ($previousBytes === false) {
                throw new HttpException(500, 'BACKUP_READ_FAILED', 'Die vorherige Datendatei konnte nicht gesichert werden.');
            }

            $this->replaceBytes($previousPath, $previousBytes);
        }

        $this->replaceBytes($path, $bytes);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function writeImmutable(string $path, array $data): void
    {
        if (file_exists($path)) {
            throw new HttpException(409, 'IMMUTABLE_FILE_EXISTS', 'Die unveränderliche Datendatei existiert bereits.');
        }

        $this->replaceBytes($path, $this->encode($data), true);
    }

    /**
     * @template T
     * @param callable(resource):T $callback
     * @return T
     */
    public function withLock(string $lockPath, callable $callback): mixed
    {
        $this->ensureDirectory(dirname($lockPath));

        $handle = fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new HttpException(500, 'LOCK_OPEN_FAILED', 'Eine Sperrdatei konnte nicht geöffnet werden.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new HttpException(503, 'LOCK_FAILED', 'Die Datensperre konnte nicht gesetzt werden.');
            }

            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new HttpException(500, 'DIRECTORY_CREATE_FAILED', 'Ein Datenverzeichnis konnte nicht angelegt werden.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        try {
            $bytes = json_encode(
                $data,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRETTY_PRINT
            ) . "\n";
        } catch (\JsonException $exception) {
            throw new HttpException(
                500,
                'JSON_ENCODE_FAILED',
                'Die Datendatei konnte nicht als JSON erzeugt werden.',
                ['jsonError' => $exception->getMessage()]
            );
        }

        if (strlen($bytes) > $this->maxBytes) {
            throw new HttpException(413, 'JSON_FILE_TOO_LARGE', 'Die Datendatei überschreitet die erlaubte Größe.');
        }

        return $bytes;
    }

    private function replaceBytes(string $path, string $bytes, bool $mustNotExist = false): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);

        if ($mustNotExist && file_exists($path)) {
            throw new HttpException(409, 'IMMUTABLE_FILE_EXISTS', 'Die unveränderliche Datendatei existiert bereits.');
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        $temporaryPath = $directory . '/.' . basename($path) . '.' . $suffix . '.tmp';
        $handle = fopen($temporaryPath, 'x+b');
        if ($handle === false) {
            throw new HttpException(500, 'TEMP_FILE_CREATE_FAILED', 'Eine temporäre Datendatei konnte nicht erstellt werden.');
        }

        try {
            $offset = 0;
            $length = strlen($bytes);

            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new HttpException(500, 'TEMP_FILE_WRITE_FAILED', 'Eine Datendatei konnte nicht vollständig geschrieben werden.');
                }
                $offset += $written;
            }

            if (!fflush($handle)) {
                throw new HttpException(500, 'TEMP_FILE_FLUSH_FAILED', 'Eine Datendatei konnte nicht synchronisiert werden.');
            }

            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            fclose($handle);
        }

        try {
            $validation = file_get_contents($temporaryPath);
            if ($validation === false) {
                throw new HttpException(500, 'TEMP_FILE_VERIFY_FAILED', 'Die temporäre Datendatei konnte nicht geprüft werden.');
            }
            json_decode($validation, true, 512, JSON_THROW_ON_ERROR);

            if ($mustNotExist && file_exists($path)) {
                throw new HttpException(409, 'IMMUTABLE_FILE_EXISTS', 'Die unveränderliche Datendatei existiert bereits.');
            }

            if (!rename($temporaryPath, $path)) {
                throw new HttpException(500, 'ATOMIC_RENAME_FAILED', 'Die Datendatei konnte nicht atomar ersetzt werden.');
            }
        } catch (\JsonException $exception) {
            throw new HttpException(
                500,
                'TEMP_FILE_INVALID_JSON',
                'Die geschriebene Datendatei hat die JSON-Prüfung nicht bestanden.',
                ['jsonError' => $exception->getMessage()]
            );
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}

final class DataLayout
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function root(): string
    {
        return $this->dataDir;
    }

    public function ensureBaseStructure(): void
    {
        foreach (
            [
                $this->dataDir,
                $this->workspacesDir(),
                $this->locksDir(),
                $this->transactionsDir(),
                $this->usersDir(),
                $this->trashDir(),
                $this->dataDir . '/auth',
                $this->dataDir . '/auth/sessions',
                $this->dataDir . '/logs',
            ] as $directory
        ) {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException('Datenverzeichnis konnte nicht angelegt werden: ' . $directory);
            }
        }
    }

    public function workspacesDir(): string
    {
        return $this->dataDir . '/workspaces';
    }

    public function workspaceDir(int $workspaceId): string
    {
        self::assertNumericId($workspaceId);
        return $this->workspacesDir() . '/' . $workspaceId;
    }

    public function workspaceJson(int $workspaceId): string
    {
        return $this->workspaceDir($workspaceId) . '/workspace.json';
    }

    public function workspacePreviousJson(int $workspaceId): string
    {
        return $this->workspaceDir($workspaceId) . '/workspace.previous.json';
    }

    public function workspaceLock(int $workspaceId): string
    {
        return $this->locksDir() . '/workspace-' . $workspaceId . '.lock';
    }

    public function workspaceIdLock(): string
    {
        return $this->locksDir() . '/workspace-id.lock';
    }

    public function pageIdLock(): string
    {
        return $this->locksDir() . '/page-id.lock';
    }

    public function workspaceMoveLock(): string
    {
        return $this->locksDir() . '/workspace-move.lock';
    }

    public function pagesDir(int $workspaceId): string
    {
        return $this->workspaceDir($workspaceId) . '/pages';
    }

    public function pageDir(int $workspaceId, int $pageId): string
    {
        self::assertNumericId($pageId);
        return $this->pagesDir($workspaceId) . '/' . $pageId;
    }

    public function pageJson(int $workspaceId, int $pageId): string
    {
        return $this->pageDir($workspaceId, $pageId) . '/page.json';
    }

    public function pageAutosaveJson(int $workspaceId, int $pageId): string
    {
        return $this->pageDir($workspaceId, $pageId) . '/autosave.json';
    }

    public function pageAutosavePreviousJson(int $workspaceId, int $pageId): string
    {
        return $this->pageDir($workspaceId, $pageId) . '/autosave.previous.json';
    }

    public function pageVersionsDir(int $workspaceId, int $pageId): string
    {
        return $this->pageDir($workspaceId, $pageId) . '/versions';
    }

    public function pageVersionJson(int $workspaceId, int $pageId, int $revision): string
    {
        if ($revision < 1) {
            throw new \InvalidArgumentException('Revisionen beginnen bei 1.');
        }

        return $this->pageVersionsDir($workspaceId, $pageId)
            . '/'
            . str_pad((string) $revision, 6, '0', STR_PAD_LEFT)
            . '.json';
    }

    public function pageLock(int $pageId): string
    {
        self::assertNumericId($pageId);
        return $this->locksDir() . '/page-' . $pageId . '.lock';
    }

    public function usersDir(): string
    {
        return $this->dataDir . '/users';
    }

    public function usersJson(): string
    {
        return $this->usersDir() . '/users.json';
    }

    public function usersPreviousJson(): string
    {
        return $this->usersDir() . '/users.previous.json';
    }

    public function usersLock(): string
    {
        return $this->locksDir() . '/users.lock';
    }

    public function locksDir(): string
    {
        return $this->dataDir . '/locks';
    }

    public function transactionsDir(): string
    {
        return $this->dataDir . '/transactions';
    }

    public function transactionJson(string $transactionId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $transactionId)) {
            throw new \InvalidArgumentException('Ungültige Transaktions-ID.');
        }

        return $this->transactionsDir() . '/workspace-move-' . $transactionId . '.json';
    }

    public function trashDir(): string
    {
        return $this->dataDir . '/trash';
    }

    public static function assertNumericId(int $id): void
    {
        if ($id <= 100 || $id > 999_999_999_999) {
            throw new \InvalidArgumentException('Ungültige numerische ID.');
        }
    }
}
