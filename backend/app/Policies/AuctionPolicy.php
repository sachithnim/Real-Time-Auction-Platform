<?php

namespace App\Policies;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\User;

/**
 * Who may see and manage auctions. Admin routes are also behind the
 * role:ADMIN middleware; the policy is the second, model-level check.
 */
class AuctionPolicy
{
    /** Drafts are visible only to admins; everything else is public. */
    public function view(?User $user, Auction $auction): bool
    {
        return $auction->status !== AuctionStatus::Draft || $user?->isAdmin() === true;
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }
}
