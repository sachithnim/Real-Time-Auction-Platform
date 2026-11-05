<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuctionStatus;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Bid;
use App\Services\Bidding\BidderPseudonyms;
use App\Support\CsvDownload;
use App\Support\ServerTime;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spreadsheet downloads for admins. Admins see real identities and the reserve,
 * as on their own pages; these files are never offered to bidders.
 */
class ExportController extends Controller
{
    /** Every accepted bid on one auction, in the order it was accepted. */
    public function bids(Auction $auction, BidderPseudonyms $pseudonyms): StreamedResponse
    {
        $numbers = $pseudonyms->numbersFor($auction);

        $rows = (function () use ($auction, $numbers) {
            foreach ($auction->bids()->with('user:id,name,email')->lazyById(500) as $bid) {
                /** @var Bid $bid */
                yield [
                    $bid->id,
                    ServerTime::format($bid->created_at),
                    $bid->user->name,
                    $bid->user->email,
                    'Bidder '.$numbers[$bid->user_id],
                    $bid->type->value,
                    $bid->amount,
                ];
            }
        })();

        return CsvDownload::make(
            "auction-{$auction->id}-bids.csv",
            ['Bid ID', 'Time (UTC)', 'Bidder', 'Email', 'Pseudonym', 'Type', 'Amount (Rs.)'],
            $rows,
        );
    }

    /** Every finished auction (completed or cancelled) with its outcome and payment. */
    public function results(): StreamedResponse
    {
        $rows = (function () {
            $auctions = Auction::query()
                ->whereIn('status', [AuctionStatus::Completed, AuctionStatus::Cancelled])
                ->with(['category:id,name', 'winner:id,name,email', 'payment'])
                ->withCount('bids')
                ->lazyById(200);

            foreach ($auctions as $auction) {
                /** @var Auction $auction */
                yield [
                    $auction->id,
                    $auction->title,
                    $auction->category?->name,
                    $auction->status->value,
                    $auction->result?->value,
                    $auction->starting_price,
                    $auction->reserve_price,
                    $auction->final_price,
                    $auction->bids_count,
                    $auction->winner?->name,
                    $auction->winner?->email,
                    $auction->payment?->status->value,
                    ServerTime::format($auction->payment?->paid_at),
                    ServerTime::format($auction->ended_at ?? $auction->cancelled_at),
                ];
            }
        })();

        return CsvDownload::make(
            'auction-results-'.now()->utc()->format('Y-m-d').'.csv',
            ['Auction ID', 'Title', 'Category', 'Status', 'Result', 'Starting price (Rs.)', 'Reserve (Rs.)', 'Final price (Rs.)',
                'Bids', 'Winner', 'Winner email', 'Payment', 'Paid at (UTC)', 'Closed at (UTC)'],
            $rows,
        );
    }
}
