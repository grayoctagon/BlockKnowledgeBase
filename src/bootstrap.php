<?php

declare(strict_types=1);

use BKB\ApiController;
use BKB\AuthService;
use BKB\Config;
use BKB\Domain\IdAllocator;
use BKB\Domain\PageRepository;
use BKB\Domain\PageValidator;
use BKB\Domain\WorkspaceRepository;
use BKB\Storage\AtomicJsonStore;
use BKB\Storage\DataLayout;

define('BKB_ROOT', dirname(__DIR__));

require_once BKB_ROOT . '/src/Config.php';
require_once BKB_ROOT . '/src/Support.php';
require_once BKB_ROOT . '/src/Storage.php';
require_once BKB_ROOT . '/src/Domain.php';
require_once BKB_ROOT . '/src/Auth.php';
require_once BKB_ROOT . '/src/WorkspaceRepository.php';
require_once BKB_ROOT . '/src/PageRepository.php';
require_once BKB_ROOT . '/src/ApiController.php';

$config = Config::load(BKB_ROOT);
date_default_timezone_set($config['timezone']);

$layout = new DataLayout($config['data_dir']);
$layout->ensureBaseStructure();

$store = new AtomicJsonStore($config['max_json_bytes']);
$validator = new PageValidator($config['max_block_content_bytes']);
$workspaces = new WorkspaceRepository($layout, $store);
$ids = new IdAllocator($layout, $store, $workspaces);
$pages = new PageRepository($layout, $store, $workspaces, $ids, $validator);
$auth = new AuthService($layout, $store);

return [
    'config' => $config,
    'layout' => $layout,
    'store' => $store,
    'validator' => $validator,
    'workspaces' => $workspaces,
    'ids' => $ids,
    'pages' => $pages,
    'auth' => $auth,
    'api' => new ApiController($auth, $workspaces, $pages, $ids),
];
