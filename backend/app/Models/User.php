<?php

namespace App\Models;

use App\Enums\EmailType;
use App\Enums\UserRole;
use App\Notifications\ResetPasswordEmail;
use App\Notifications\VerifyEmailNotice;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 'role' is deliberately not fillable: a request can never promote itself to admin.
#[Fillable(['first_name', 'last_name', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
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
            'email_preferences' => 'array',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    /**
     * The inbox under the bell, newest first. Replaces Laravel's default so the
     * entries use the prunable Notice model.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notice::class, 'notifiable')->latest('updated_at');
    }

    /** Every bid this user placed. */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    /** Auctions this user won. */
    public function wonAuctions(): HasMany
    {
        return $this->hasMany(Auction::class, 'winner_id');
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

    /** The display name kept in "name": first and last name joined by a space. */
    public static function fullName(string $firstName, string $lastName): string
    {
        return trim($firstName.' '.$lastName);
    }

    /** Every email is on until the user switches it off. */
    public function wantsEmail(EmailType $type): bool
    {
        return ($this->email_preferences[$type->value] ?? true) === true;
    }

    /**
     * Every email type with its on/off state.
     *
     * @return array<string, bool>
     */
    public function emailPreferences(): array
    {
        return collect(EmailType::cases())->mapWithKeys(fn (EmailType $type) => [$type->value => $this->wantsEmail($type)])->all();
    }

    /** @param  array<string, bool>  $changes  Only the types being changed. */
    public function updateEmailPreferences(array $changes): void
    {
        $this->email_preferences = [...$this->emailPreferences(), ...$changes];
        $this->save();
    }

    /** "Confirm your email": branded, queued, with a signed link that needs no login. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotice);
    }

    /** The "Forgot password?" email: branded, queued, and linking to the web app. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordEmail($token));
    }

    /**
     * Signs the user out everywhere except the given session (none: everywhere).
     * A new remember token voids "remember me" cookies on other devices; deleting
     * the stored sessions ends the ones still open.
     */
    public function signOutOtherSessions(?string $keepSessionId = null): void
    {
        $this->setRememberToken(Str::random(60));
        $this->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $this->id)
                ->when($keepSessionId, fn ($sessions, $id) => $sessions->where('id', '!=', $id))
                ->delete();
        }
    }

    /** Blocked by an admin: cannot log in or use the API. */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
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
