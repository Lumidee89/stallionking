<?php

namespace App\Models;

use App\Enums\ParcelStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ParcelEvent extends Model
{
    public const UPDATED_AT = null;
    protected $fillable = ['parcel_id', 'status', 'actor_id', 'actor_role', 'note'];

    protected function casts(): array
    {
        return ['status' => ParcelStatus::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Parcel events are append-only.'));
        // Events go only when their parcel is deleted (via the database cascade), never one by one.
        static::deleting(fn () => throw new RuntimeException('Parcel events are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
