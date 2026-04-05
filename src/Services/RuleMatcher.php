<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Repositories\RuleRepository;
use Access402\Support\Helpers;

final class RuleMatcher
{
    public function __construct(private readonly RuleRepository $rules)
    {
    }

    public function match(string $path): ?array
    {
        $path = Helpers::normalize_path($path);

        foreach ($this->rules->all_active_ordered() as $rule) {
            if ($this->matches($path, (string) ($rule['path_pattern'] ?? ''))) {
                return $rule;
            }
        }

        return null;
    }

    public function matches(string $path, string $pattern): bool
    {
        $path    = Helpers::normalize_path($path);
        $pattern = Helpers::normalize_path_pattern($pattern);

        if ($pattern === '') {
            return false;
        }

        if (! str_contains($pattern, '*')) {
            return $path === $pattern;
        }

        $quoted = preg_quote($pattern, '#');
        $regex  = '#^' . str_replace('\*', '.*', $quoted) . '$#i';

        return (bool) preg_match($regex, $path);
    }
}
