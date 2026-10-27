<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'city', 'state', 'created_by'];

    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }
}
