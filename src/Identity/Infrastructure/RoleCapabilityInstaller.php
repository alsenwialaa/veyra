<?php

declare(strict_types=1);

namespace Veyra\Identity\Infrastructure;

use Veyra\Identity\Domain\CapabilityRegistry;

final class RoleCapabilityInstaller
{
    public static function install(): void
    {
        $administrator = get_role('administrator');

        if (!$administrator instanceof \WP_Role) {
            return;
        }

        foreach (CapabilityRegistry::names() as $capability) {
            $administrator->add_cap($capability, true);
        }
    }

    public static function removeFromAllRoles(): void
    {
        $roles = function_exists('wp_roles') ? wp_roles() : null;

        if (!$roles instanceof \WP_Roles) {
            return;
        }

        foreach (array_keys($roles->roles) as $roleName) {
            $role = get_role((string) $roleName);

            if (!$role instanceof \WP_Role) {
                continue;
            }

            foreach (CapabilityRegistry::names() as $capability) {
                $role->remove_cap($capability);
            }
        }
    }
}

