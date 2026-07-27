<?php

declare(strict_types=1);

namespace BKB;

final class Config
{
    /**
     * @return array{
     *   name:string,
     *   timezone:string,
     *   data_dir:string,
     *   session_name:string,
     *   max_json_bytes:int,
     *   max_block_content_bytes:int
     * }
     */
    public static function load(string $root): array
    {
        $config = require $root . '/config/app.php';

        if (!is_array($config)) {
            throw new \RuntimeException('Die Anwendungskonfiguration ist ungültig.');
        }

        $dataDir = rtrim((string) ($config['data_dir'] ?? ''), DIRECTORY_SEPARATOR);
        if ($dataDir === '') {
            throw new \RuntimeException('Das Datenverzeichnis darf nicht leer sein.');
        }

        return [
            'name' => (string) ($config['name'] ?? 'BlockKnowledgeBase'),
            'timezone' => (string) ($config['timezone'] ?? 'Europe/Vienna'),
            'data_dir' => $dataDir,
            'session_name' => (string) ($config['session_name'] ?? 'BKBSESSID'),
            'max_json_bytes' => max(1024, (int) ($config['max_json_bytes'] ?? 8 * 1024 * 1024)),
            'max_block_content_bytes' => max(
                1024,
                (int) ($config['max_block_content_bytes'] ?? 1024 * 1024)
            ),
        ];
    }
}
