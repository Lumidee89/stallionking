<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'staff_id', 'password', 'role', 'active'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function hasRole(array $roles): bool
    {
        return $this->active && in_array($this->role, $roles, true);
    }

    /** Where this user lands after signing in. */
    public function homeRoute(): string
    {
        return $this->role === Role::Dispatch ? route('dispatch.index') : route('warehouse.create');
    }

    /** Whether this user may edit, deactivate or delete $other's login. */
    public function canManage(User $other): bool
    {
        return $this->active && in_array($other->role, $this->role->assignableRoles(), true);
    }
}
