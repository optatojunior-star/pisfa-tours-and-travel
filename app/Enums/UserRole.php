<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case Staff = 'staff';
    case Driver = 'driver';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrator',
            self::Manager => 'Manager',
            self::Staff => 'Staff',
            self::Driver => 'Driver',
            self::Customer => 'Customer',
        };
    }

    public function canAccessAdministration(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Manager, self::Staff => true,
            self::Driver, self::Customer => false,
        };
    }
}
