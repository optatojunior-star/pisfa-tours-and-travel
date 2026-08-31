<?php

namespace App\Enums;

final class StaffRoles
{
    /** @return list<UserRole> */
    public static function all(): array
    {
        return [
            UserRole::SuperAdmin,
            UserRole::Manager,
            UserRole::Staff,
            UserRole::Driver,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (UserRole $role): string => $role->value,
            self::all(),
        );
    }

    public static function isManageable(UserRole $role): bool
    {
        return in_array($role, self::all(), true);
    }

    public static function requiresTwoFactor(UserRole $role): bool
    {
        return in_array($role, [UserRole::SuperAdmin, UserRole::Manager], true);
    }
}
