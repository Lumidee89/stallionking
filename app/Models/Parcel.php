<?php

namespace App\Models;

use App\Enums\ParcelStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Parcel extends Model
{
    public const WEIGHTS = [
        'lt1' => 'Under 1kg', '1-3' => '1kg – 3kg', '3-5' => '3kg – 5kg',
        '5-10' => '5kg – 10kg', '10-20' => '10kg – 20kg', 'gt20' => 'Above 20kg',
    ];
    public const SERVICES = ['Standard', 'Express'];

    protected $fillable = [
        'tracking_number', 'idempotency_key', 'status', 'client_id', 'sender',
        'receiver_name', 'receiver_phone', 'receiver_email', 'receiver_address',
        'receiver_city', 'receiver_state', 'receiver_landmark', 'weight_range',
        'description', 'service_type', 'tracking_emails',
        'receiver_source', 'rider_id', 'booked_by',
    ];

    protected function casts(): array
    {
        return ['status' => ParcelStatus::class, 'sender' => 'array', 'tracking_emails' => 'array'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ParcelEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function weightLabel(): string
    {
        return self::WEIGHTS[$this->weight_range] ?? $this->weight_range;
    }

    public function senderCity(): string
    {
        return (string) ($this->sender['city'] ?? '') ?: '—';
    }

    public function route(): string
    {
        return $this->senderCity() . ' → ' . $this->receiver_city;
    }
}
