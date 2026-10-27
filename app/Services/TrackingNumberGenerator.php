<?php

namespace App\Services;

use App\Models\Parcel;
use App\Models\Setting;

final class TrackingNumberGenerator
{
    // No 0/O/1/I so numbers survive being read aloud or hand-copied.
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function generate(int $attempts = 8): string
    {
        $prefix = Setting::get('prefix') ?: 'STK';
        for ($i = 0; $i < $attempts; $i++) {
            $code = $prefix . '-';
            for ($j = 0; $j < 8; $j++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (!Parcel::where('tracking_number', $code)->exists()) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not allocate a unique tracking number.');
    }
}
