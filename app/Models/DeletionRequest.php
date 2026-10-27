<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletionRequest extends Model
{
    protected $fillable = ['parcel_id', 'tracking_number', 'reason', 'status', 'requested_by', 'resolved_by', 'resolved_at', 'resolution_note'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
