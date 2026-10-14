<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo accounts. Every account uses the password "password" (local demo only).
 */
class UserSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@auction.test';

    public const BIDDER_COUNT = 5;

    public function run(): void
    {
        $this->seedUser('Platform Admin', self::ADMIN_EMAIL, UserRole::Admin);

        for ($i = 1; $i <= self::BIDDER_COUNT; $i++) {
            $this->seedUser("Bidder {$i}", "bidder{$i}@auction.test", UserRole::Bidder);
        }
    }

    private function seedUser(string $name, string $email, UserRole $role): void
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ])->save();
    }
}
