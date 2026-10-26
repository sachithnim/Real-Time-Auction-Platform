<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

// 'role' is deliberately not fillable: a request can never promote itself to admin.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Mirrors the column default so a freshly created model has its role in memory.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'BIDDER',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /** Auctions this bidder follows (their watchlist). */
    public function watchedAuctions(): BelongsToMany
    {
        return $this->belongsToMany(Auction::class, 'watchlists')->withTimestamps();
    }

    /**
     * Adds the auction to the watchlist. Safe to repeat and to race: the unique
     * (user_id, auction_id) index decides, and a duplicate is simply ignored.
     */
    public function watch(Auction $auction): void
    {
        $now = now();

        DB::table('watchlists')->insertOrIgnore([
            'user_id' => $this->id,
            'auction_id' => $auction->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Removes the auction from the watchlist; a no-op if it was not there. */
    public function unwatch(Auction $auction): void
    {
        $this->watchedAuctions()->detach($auction->id);
    }

    public function isWatching(Auction $auction): bool
    {
        return $this->watchedAuctions()->whereKey($auction->id)->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBidder(): bool
    {
        return $this->role === UserRole::Bidder;
    }
}
