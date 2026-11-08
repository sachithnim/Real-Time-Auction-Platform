<?php

namespace App\Services;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * "Relist": an item that did not sell goes up again as a new draft with the same
 * details and photos. The old auction stays as it was (its history is final), and
 * both audit trails point at each other.
 */
class AuctionRelister
{
    public function __construct(
        private readonly AuctionLifecycleService $lifecycle,
        private readonly AuctionImageService $images,
        private readonly AuctionEventRecorder $events,
    ) {}

    public function relist(Auction $source, User $admin): Auction
    {
        $this->ensureUnsold($source);

        $copied = [];

        try {
            return DB::transaction(function () use ($source, $admin, &$copied) {
                // Tomorrow on the hour, for the same length as before: a starting point the
                // admin is expected to check before scheduling.
                $start = now()->addDay()->startOfHour();
                $length = max(3600, ($source->original_end_at ?? $source->end_at)->getTimestamp() - $source->start_at->getTimestamp());

                $draft = $this->lifecycle->create([
                    'title' => $source->title,
                    'description' => $source->description,
                    'category_id' => $source->category_id,
                    'starting_price' => $source->starting_price,
                    'reserve_price' => $source->reserve_price,
                    'min_increment' => $source->min_increment,
                    'start_at' => $start,
                    'end_at' => $start->copy()->addSeconds($length),
                    'anti_snipe_seconds' => $source->anti_snipe_seconds,
                    'extension_seconds' => $source->extension_seconds,
                    'max_extensions' => $source->max_extensions,
                ], $admin, eventNote: ['relisted_from' => $source->id]);

                $copied = $this->images->copyAll($source, $draft);

                $this->events->record($source, AuctionEventType::AuctionRelisted, $admin, ['new_auction_id' => $draft->id]);

                return $draft;
            });
        } catch (Throwable $e) {
            Storage::disk(AuctionImageService::DISK)->delete($copied);
            throw $e;
        }
    }

    /** Cancelled, or closed without a sale (reserve not met, or no bids). A sold item is gone. */
    private function ensureUnsold(Auction $auction): void
    {
        $unsold = $auction->status === AuctionStatus::Cancelled
            || ($auction->status === AuctionStatus::Completed && $auction->result !== AuctionResult::Sold);

        if (! $unsold) {
            throw new ApiException(
                ErrorCode::InvalidTransition,
                'Only an auction that has ended without selling, or was cancelled, can be relisted.',
                ['status' => $auction->status->value, 'result' => $auction->result?->value],
            );
        }
    }
}
