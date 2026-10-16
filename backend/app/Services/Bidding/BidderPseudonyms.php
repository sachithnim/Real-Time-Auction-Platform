<?php

namespace App\Services\Bidding;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Per-auction display names: "Bidder N", where N is the order of the
 * bidder's first bid in that auction. Bids are insert-only, so a number never
 * changes once given, and the same person has unrelated numbers in different
 * auctions. The viewer sees "You" for themselves. No user id or email is
 * ever derivable from a pseudonym.
 */
class BidderPseudonyms
{
    /** @var array<int, array<int, int>> auction id → (user id → bidder number) */
    private array $numbers = [];

    public function nameFor(Auction $auction, ?int $userId, ?User $viewer): ?string
    {
        if ($userId === null) {
            return null;
        }

        if ($viewer !== null && $viewer->id === $userId) {
            return 'You';
        }

        $number = $this->numbersFor($auction)[$userId] ?? null;

        if ($number === null) {
            // A bidder new since the numbers were cached (e.g. later in the same request).
            unset($this->numbers[$auction->id]);
            $number = $this->numbersFor($auction)[$userId] ?? null;
        }

        return $number === null ? null : "Bidder {$number}";
    }

    /**
     * @return array<int, int> user id → bidder number
     */
    public function numbersFor(Auction $auction): array
    {
        return $this->numbers[$auction->id] ??= DB::table('bids')
            ->where('auction_id', $auction->id)
            ->groupBy('user_id')
            ->orderByRaw('MIN(id)')
            ->pluck('user_id')
            ->values()
            ->mapWithKeys(fn ($userId, $index) => [(int) $userId => $index + 1])
            ->all();
    }
}
