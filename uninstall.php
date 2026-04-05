<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Support/Autoloader.php';

\Access402\Support\Autoloader::register();
\Access402\Plugin::uninstall();
