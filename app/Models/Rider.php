<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rider extends Model
{
    protected $fillable = ['name', 'phone', 'vehicle', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }
}
