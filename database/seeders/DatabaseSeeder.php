<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@krotze.com');
        $admin = User::where('email', $email)->first();

        // Re-seeding must never quietly reset a password an admin chose for
        // themselves. `password_changed_at` is what tells the two apart, and it
        // is left null on purpose: the panel demands a real password before it
        // lets anybody in (see AdminAuth).
        if ($admin && ! $admin->mustChangePassword()) {
            $this->command?->info('Admin account already has a password of its own — left untouched.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'ChangeMe!Krotze2026')),
                'password_changed_at' => null,
            ],
        );

        $this->command?->warn('Admin seeded with a temporary password; the panel will ask for a new one at first sign-in.');
    }
}
