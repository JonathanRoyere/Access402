<?php

declare(strict_types=1);

namespace Access402;

final class Capabilities
{
    public const MANAGE = 'manage_access402';

    public static function grant(): void
    {
        $role = get_role('administrator');

        if ($role instanceof \WP_Role) {
            $role->add_cap(self::MANAGE);
        }
    }

    public static function revoke(): void
    {
        $role = get_role('administrator');

        if ($role instanceof \WP_Role) {
            $role->remove_cap(self::MANAGE);
        }
    }
}
