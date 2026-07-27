<?php

declare(strict_types=1);

return [
    'name' => 'BlockKnowledgeBase',
    'timezone' => getenv('BKB_TIMEZONE') ?: 'Europe/Vienna',
    'data_dir' => getenv('BKB_DATA_DIR') ?: dirname(__DIR__) . '/data',
    'session_name' => getenv('BKB_SESSION_NAME') ?: 'BKBSESSID',
    'max_json_bytes' => (int) (getenv('BKB_MAX_JSON_BYTES') ?: 8 * 1024 * 1024),
    'max_block_content_bytes' => (int) (getenv('BKB_MAX_BLOCK_CONTENT_BYTES') ?: 1024 * 1024),
];
