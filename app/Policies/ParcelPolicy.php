<?php

namespace App\Policies;

use App\Enums\ParcelStatus;
use App\Enums\Role;
use App\Models\Parcel;
use App\Models\User;

class ParcelPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->active;
    }

    public function view(User $u, Parcel $p): bool
    {
        return $u->active;
    }

    public function create(User $u): bool
    {
        return $u->active && in_array($u->role, Role::warehouse(), true);
    }

    /** Warehouse steps (received/packaged/scanned) vs dispatch steps vs re-book. */
    public function advanceTo(User $u, Parcel $p, ParcelStatus $next): bool
    {
        if (!$u->active) {
            return false;
        }
        return match ($next) {
            ParcelStatus::Received, ParcelStatus::Packaged, ParcelStatus::Scanned
                => in_array($u->role, Role::warehouse(), true),
            ParcelStatus::OutForDelivery, ParcelStatus::Delivered, ParcelStatus::Exception
                => in_array($u->role, [Role::Dispatch, Role::Admin, Role::Superadmin], true),
            ParcelStatus::Booked => $u->isAdmin(),
        };
    }
}
