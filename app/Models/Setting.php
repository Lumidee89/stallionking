<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const DEFAULTS = [
        'name' => 'StallionKing Logistics',
        'tagline' => 'Parcel tracking, from hub to doorstep',
        'prefix' => 'STK',
    ];

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    private static ?array $cache = null;

    /** All company settings, with defaults for anything never saved. */
    public static function values(): array
    {
        return self::$cache ??= array_merge(self::DEFAULTS, self::pluck('value', 'key')->filter()->all());
    }

    public static function get(string $key): string
    {
        return (string) (self::values()[$key] ?? '');
    }

    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            self::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        self::$cache = null;
    }
}
