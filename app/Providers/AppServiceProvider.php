<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Brute-force protection keyed on staff ID + IP.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('staff_id')) . '|' . $r->ip()));
        RateLimiter::for('tracking', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('booking', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('scan', fn (Request $r) => Limit::perMinute(40)->by($r->user()?->id ?: $r->ip()));
    }
}
