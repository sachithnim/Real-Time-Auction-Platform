<?php

namespace App\Services\Reporting;

use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use App\Enums\BidType;
use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide figures for the admin statistics page. Read-only aggregate
 * queries over existing data: nothing is stored, so the numbers are always
 * current. Money stays in whole rupees (averages are rounded to the rupee);
 * a figure that has nothing to measure yet is null rather than zero.
 */
class AuctionStatistics
{
    /** How many bidders the "most active" list shows. */
    public const TOP_BIDDERS = 5;

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'auctions' => $this->auctions(),
            'results' => $this->results(),
            'revenue' => $this->revenue(),
            'bids' => $this->bids(),
            'anti_sniping' => $this->antiSniping(),
            'top_bidders' => $this->topBidders(),
        ];
    }

    /**
     * @return array{total: int, by_status: array<string, int>}
     */
    private function auctions(): array
    {
        $counts = Auction::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Every status is listed, including those with no auctions yet.
        $byStatus = [];
        foreach (AuctionStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return ['total' => array_sum($byStatus), 'by_status' => $byStatus];
    }

    /**
     * Outcomes of completed auctions. Sell-through = sold / completed.
     *
     * @return array<string, int|float|null>
     */
    private function results(): array
    {
        $counts = Auction::query()
            ->where('status', AuctionStatus::Completed)
            ->selectRaw('result, COUNT(*) AS total')
            ->groupBy('result')
            ->pluck('total', 'result');

        $sold = (int) ($counts[AuctionResult::Sold->value] ?? 0);
        $reserveNotMet = (int) ($counts[AuctionResult::ReserveNotMet->value] ?? 0);
        $noBids = (int) ($counts[AuctionResult::NoBids->value] ?? 0);
        $completed = $sold + $reserveNotMet + $noBids;

        return [
            'completed' => $completed,
            'sold' => $sold,
            'reserve_not_met' => $reserveNotMet,
            'no_bids' => $noBids,
            'sell_through_percent' => $completed > 0 ? round($sold * 100 / $completed, 1) : null,
        ];
    }

    /**
     * Final prices of sold auctions only.
     *
     * @return array{total: int, average: int|null, highest: int|null}
     */
    private function revenue(): array
    {
        $row = Auction::query()
            ->where('result', AuctionResult::Sold)
            ->selectRaw('COALESCE(SUM(final_price), 0) AS total, AVG(final_price) AS average, MAX(final_price) AS highest')
            ->toBase()
            ->first();

        return [
            'total' => (int) $row->total,
            'average' => $row->average === null ? null : (int) round((float) $row->average),
            'highest' => $row->highest === null ? null : (int) $row->highest,
        ];
    }

    /**
     * Accepted bids (rejected attempts live in the audit log, not here).
     *
     * @return array<string, int|float|null>
     */
    private function bids(): array
    {
        $row = Bid::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(type = ?), 0) AS manual', [BidType::Manual->value])
            ->selectRaw('COALESCE(SUM(type = ?), 0) AS auto', [BidType::Auto->value])
            ->selectRaw('COUNT(DISTINCT user_id) AS bidders')
            ->selectRaw('COUNT(DISTINCT auction_id) AS auctions_with_bids')
            ->toBase()
            ->first();

        $total = (int) $row->total;
        $auctionsWithBids = (int) $row->auctions_with_bids;

        return [
            'total' => $total,
            'manual' => (int) $row->manual,
            'auto' => (int) $row->auto,
            'bidders' => (int) $row->bidders,
            'auctions_with_bids' => $auctionsWithBids,
            'average_per_auction' => $auctionsWithBids > 0 ? round($total / $auctionsWithBids, 1) : null,
        ];
    }

    /**
     * @return array{extended_auctions: int, total_extensions: int}
     */
    private function antiSniping(): array
    {
        $row = Auction::query()
            ->where('extensions_count', '>', 0)
            ->selectRaw('COUNT(*) AS auctions, COALESCE(SUM(extensions_count), 0) AS extensions')
            ->toBase()
            ->first();

        return ['extended_auctions' => (int) $row->auctions, 'total_extensions' => (int) $row->extensions];
    }

    /**
     * Bidders with the most accepted bids. Ties go to the earlier account, so
     * the order is stable. Admin-only data: real names and emails are shown.
     *
     * @return list<array{id: int, name: string, email: string, bids: int, auctions: int, wins: int}>
     */
    private function topBidders(): array
    {
        return DB::table('bids')
            ->join('users', 'users.id', '=', 'bids.user_id')
            ->select('users.id', 'users.name', 'users.email')
            ->selectRaw('COUNT(*) AS bids')
            ->selectRaw('COUNT(DISTINCT bids.auction_id) AS auctions')
            ->selectRaw('(SELECT COUNT(*) FROM auctions WHERE auctions.winner_id = users.id) AS wins')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('bids')
            ->orderBy('users.id')
            ->limit(self::TOP_BIDDERS)
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'bids' => (int) $row->bids,
                'auctions' => (int) $row->auctions,
                'wins' => (int) $row->wins,
            ])
            ->all();
    }
}
