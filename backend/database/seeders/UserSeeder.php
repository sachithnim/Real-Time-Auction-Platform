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

    /**
     * Real-looking names, so they are never mistaken for the per-auction
     * pseudonyms ("Bidder 2") that bidders see instead of names.
     */
    public const BIDDER_NAMES = [
        'Asha Perera',
        'Nimal Silva',
        'Kavindi Fernando',
        'Ruwan Jayasinghe',
        'Dilini Wickramasinghe',
    ];

    public function run(): void
    {
        $this->seedUser('Platform Admin', self::ADMIN_EMAIL, UserRole::Admin);

        foreach (self::BIDDER_NAMES as $i => $name) {
            $this->seedUser($name, 'bidder'.($i + 1).'@auction.test', UserRole::Bidder);
        }
    }

    private function seedUser(string $name, string $email, UserRole $role): void
    {
        $user = User::firstOrNew(['email' => $email]);
        [$first, $last] = explode(' ', $name, 2);
        $user->forceFill([
            'first_name' => $first,
            'last_name' => $last,
            'name' => $name,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ])->save();
    }
}
