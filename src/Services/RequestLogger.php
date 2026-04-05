<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Repositories\LogRepository;
use Access402\Repositories\SettingsRepository;

final class RequestLogger
{
    public function __construct(
        private readonly LogRepository $logs,
        private readonly SettingsRepository $settings
    ) {
    }

    public function maybe_log(array $data): void
    {
        if (! $this->settings->get('enable_logging', 1)) {
            return;
        }

        $this->logs->insert($data);
    }
}
