<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;
    protected $fillable = ['actor_id', 'actor_name', 'actor_staff_id', 'actor_role', 'action', 'summary', 'target_type', 'target_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public static function record(?User $actor, string $action, string $summary, ?string $targetType = null, ?string $targetId = null): void
    {
        self::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_staff_id' => $actor?->staff_id,
            'actor_role' => $actor?->role?->value,
            'action' => $action,
            'summary' => mb_substr($summary, 0, 500),
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);
    }
}
