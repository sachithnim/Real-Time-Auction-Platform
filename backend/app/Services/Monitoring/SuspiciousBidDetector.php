<?php

namespace App\Services\Monitoring;

use App\Enums\AuctionEventType;
use App\Enums\BidType;
use App\Enums\FlagRule;
use App\Enums\FlagStatus;
use App\Models\AuctionEvent;
use App\Models\Bid;
use Illuminate\Support\Facades\DB;

/**
 * Flags bidding that looks unusual, for an admin to review (bonus challenge:
 * suspicious bidding detection). Runs after the bid response has been sent,
 * outside the auction row lock, and never blocks or changes a bid.
 *
 * Each pattern is one row in bid_flags, keyed by rule, auction and bidders:
 * seeing it again updates that row (and reopens it if it was reviewed).
 */
class SuspiciousBidDetector
{
    /**
     * Checks one bidder's activity on one auction after a bid or auto-bid request.
     */
    public function scan(int $auctionId, int $userId, ?string $ip): void
    {
        if ($ip !== null && $ip !== '') {
            $this->recordIp($auctionId, $userId, $ip);
        }

        $this->checkRapidBidding($auctionId, $userId);
        $this->checkRejections($auctionId, $userId);
        $this->checkSharedIp($auctionId, $userId);
        $this->checkAlternatingLeaders($auctionId);
    }

    private function recordIp(int $auctionId, int $userId, string $ip): void
    {
        $now = now();

        DB::table('bidder_ips')->upsert(
            [['auction_id' => $auctionId, 'user_id' => $userId, 'ip_address' => $ip, 'first_seen_at' => $now, 'last_seen_at' => $now]],
            ['auction_id', 'user_id', 'ip_address'],
            ['last_seen_at'],
        );
    }

    private function checkRapidBidding(int $auctionId, int $userId): void
    {
        $window = (int) config('auction.suspicious.rapid_window_seconds');
        $bids = Bid::query()
            ->where('auction_id', $auctionId)
            ->where('user_id', $userId)
            ->where('type', BidType::Manual) // automatic answers are the system's, not the bidder's
            ->where('created_at', '>=', now()->subSeconds($window))
            ->count();

        if ($bids >= (int) config('auction.suspicious.rapid_bids')) {
            $this->flag(FlagRule::RapidBidding, $auctionId, [$userId], ['bids' => $bids, 'window_seconds' => $window]);
        }
    }

    private function checkRejections(int $auctionId, int $userId): void
    {
        $window = (int) config('auction.suspicious.rejection_window_minutes');
        $rejections = AuctionEvent::query()
            ->where('auction_id', $auctionId)
            ->where('type', AuctionEventType::BidRejected)
            ->where('actor_id', $userId)
            ->where('created_at', '>=', now()->subMinutes($window))
            ->get(['payload']);

        if ($rejections->count() >= (int) config('auction.suspicious.rejections')) {
            $this->flag(FlagRule::ManyRejections, $auctionId, [$userId], [
                'rejections' => $rejections->count(),
                'window_minutes' => $window,
                'reasons' => $rejections->countBy(fn (AuctionEvent $event) => $event->payload['reason'] ?? 'UNKNOWN')->all(),
            ]);
        }
    }

    /**
     * Another account that also has accepted bids on this auction, and used one
     * of this bidder's IP addresses on it.
     */
    private function checkSharedIp(int $auctionId, int $userId): void
    {
        if (! $this->hasBids($auctionId, $userId)) {
            return;
        }

        $myIps = DB::table('bidder_ips')->where('auction_id', $auctionId)->where('user_id', $userId)->pluck('ip_address');
        if ($myIps->isEmpty()) {
            return;
        }

        $others = DB::table('bidder_ips')
            ->where('auction_id', $auctionId)
            ->whereIn('ip_address', $myIps)
            ->where('user_id', '!=', $userId)
            ->get(['user_id', 'ip_address'])
            ->groupBy('user_id');

        foreach ($others as $otherId => $rows) {
            if ($this->hasBids($auctionId, (int) $otherId)) {
                $this->flag(FlagRule::SharedIp, $auctionId, [$userId, (int) $otherId], [
                    'ip_addresses' => $rows->pluck('ip_address')->unique()->sort()->values()->all(),
                ]);
            }
        }
    }

    /** Exactly two bidders on the auction, and the lead has changed hands many times. */
    private function checkAlternatingLeaders(int $auctionId): void
    {
        $bidders = Bid::query()->where('auction_id', $auctionId)->distinct()->pluck('user_id');
        if ($bidders->count() !== 2) {
            return;
        }

        $leadChanges = AuctionEvent::query()
            ->where('auction_id', $auctionId)
            ->where('type', AuctionEventType::LeaderChanged)
            ->count();

        if ($leadChanges >= (int) config('auction.suspicious.alternating_lead_changes')) {
            $this->flag(FlagRule::AlternatingLeaders, $auctionId, $bidders->map(fn ($id) => (int) $id)->all(), [
                'lead_changes' => $leadChanges,
                'bidders' => 2,
            ]);
        }
    }

    private function hasBids(int $auctionId, int $userId): bool
    {
        return Bid::query()->where('auction_id', $auctionId)->where('user_id', $userId)->exists();
    }

    /**
     * Creates the flag, or updates the existing one for the same pattern. One
     * statement (INSERT … ON DUPLICATE KEY UPDATE), so concurrent scans cannot
     * create duplicates.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $details
     */
    private function flag(FlagRule $rule, int $auctionId, array $userIds, array $details): void
    {
        sort($userIds);
        $now = now();

        DB::table('bid_flags')->upsert(
            [[
                'dedupe_key' => "{$rule->value}:{$auctionId}:".implode('-', $userIds),
                'auction_id' => $auctionId,
                'rule' => $rule->value,
                'user_id' => $userIds[0],
                'related_user_id' => $userIds[1] ?? null,
                'details' => json_encode($details),
                'status' => FlagStatus::Open->value,
                'occurrences' => 1,
                'first_detected_at' => $now,
                'last_detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['dedupe_key'],
            [
                'details' => json_encode($details),
                'status' => FlagStatus::Open->value, // seen again after review: reopen
                'occurrences' => DB::raw('occurrences + 1'),
                'last_detected_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
}
