<?php

declare(strict_types=1);

namespace Access402\Support;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        $path = ACCESS402_PLUGIN_DIR . 'templates/' . ltrim($template, '/') . '.php';

        if (! is_readable($path)) {
            return;
        }

        extract($data, EXTR_SKIP);
        include $path;
    }
}
