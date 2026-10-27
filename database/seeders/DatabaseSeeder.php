<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Set SEED_ADMIN_STAFF_ID / SEED_ADMIN_PASSWORD in .env. No default password is ever created. */
    public function run(): void
    {
        $staffId = env('SEED_ADMIN_STAFF_ID');
        $password = env('SEED_ADMIN_PASSWORD');
        if (!$staffId || !$password || strlen($password) < 12) {
            $this->command?->error('Set SEED_ADMIN_STAFF_ID and a SEED_ADMIN_PASSWORD of 12+ characters in .env first.');
            return;
        }
        User::updateOrCreate(['staff_id' => $staffId], [
            'name' => 'Superadmin',
            'email' => env('SEED_ADMIN_EMAIL', strtolower($staffId) . '@stallionking.local'),
            'password' => $password,
            'role' => Role::Superadmin,
            'active' => true,
        ]);
    }
}
