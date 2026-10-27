<?php

namespace App\Enums;

enum Role: string
{
    case Staff = 'staff';
    case Dispatch = 'dispatch';
    case Admin = 'admin';
    case Superadmin = 'superadmin';

    /** Roles allowed to book parcels at the warehouse. */
    public static function warehouse(): array
    {
        return [self::Staff, self::Admin, self::Superadmin];
    }

    /** Roles allowed to assign riders and confirm deliveries. */
    public static function dispatch(): array
    {
        return [self::Dispatch, self::Admin, self::Superadmin];
    }

    public function isAdmin(): bool
    {
        return in_array($this, [self::Admin, self::Superadmin], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Admins manage staff and dispatch logins; only a superadmin can create or change admins. */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Superadmin => self::cases(),
            self::Admin => [self::Staff, self::Dispatch],
            default => [],
        };
    }
}
