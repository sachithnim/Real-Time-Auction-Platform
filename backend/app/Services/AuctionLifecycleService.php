<?php

namespace App\Services;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Jobs\CloseAuctionJob;
use App\Jobs\StartAuctionJob;
use App\Models\Auction;
use App\Models\User;
use App\Services\Realtime\AuctionBroadcaster;
use App\Support\ServerTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin-driven lifecycle changes: create, edit, schedule and cancel.
 *
 * Every change runs in a transaction that first locks the auction row, so it
 * is serialized with bids and the closing job, then validates the transition
 * through AuctionStatus and records the audit event in the same transaction.
 */
class AuctionLifecycleService
{
    public function __construct(
        private readonly AuctionEventRecorder $events,
        private readonly AuctionBroadcaster $broadcaster,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated, admin-editable fields (times in UTC).
     */
    public function create(array $data, User $admin, bool $schedule = false): Auction
    {
        return DB::transaction(function () use ($data, $admin, $schedule) {
            $auction = new Auction($data);
            $auction->forceFill([
                'status' => AuctionStatus::Draft,
                'created_by' => $admin->id,
                'original_end_at' => $auction->end_at,
            ])->save();

            $this->events->record($auction, AuctionEventType::AuctionCreated, $admin, $this->snapshot($auction));

            if ($schedule) {
                $this->applySchedule($auction, $admin);
            }

            return $auction;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated, admin-editable fields (times in UTC).
     */
    public function update(Auction $auction, array $data, User $admin): Auction
    {
        return DB::transaction(function () use ($auction, $data, $admin) {
            $auction = $this->lock($auction);

            if (! $auction->status->isEditable()) {
                throw new ApiException(
                    ErrorCode::AuctionNotEditable,
                    'Only draft auctions can be edited.',
                    ['status' => $auction->status->value],
                );
            }

            $before = $this->snapshot($auction);
            $auction->fill($data);
            // A draft has never run, so its original end is simply its end.
            $auction->original_end_at = $auction->end_at;
            $auction->save();

            $changes = $this->diff($before, $this->snapshot($auction));
            if ($changes !== []) {
                $this->events->record($auction, AuctionEventType::AuctionUpdated, $admin, ['changes' => $changes]);
            }

            return $auction;
        });
    }

    public function schedule(Auction $auction, User $admin): Auction
    {
        return DB::transaction(function () use ($auction, $admin) {
            $auction = $this->lock($auction);
            $this->applySchedule($auction, $admin);

            return $auction;
        });
    }

    public function cancel(Auction $auction, User $admin, ?string $reason = null): Auction
    {
        return DB::transaction(function () use ($auction, $admin, $reason) {
            $auction = $this->lock($auction);
            $from = $auction->status;

            $this->transition($auction, AuctionStatus::Cancelled);
            $auction->cancelled_at = now();
            $auction->save();

            $this->events->record($auction, AuctionEventType::AuctionCancelled, $admin, [
                'from_status' => $from->value,
                'reason' => $reason,
                'current_price' => $auction->current_price,
                'leader_id' => $auction->leader_id,
            ]);

            $this->broadcaster->cancelled($auction);

            return $auction;
        });
    }

    /**
     * System action (start job or sweep): SCHEDULED → LIVE once start_at has
     * passed. Returns false, changing nothing, when the auction is not due or
     * no longer scheduled, so running it twice is harmless.
     */
    public function start(int $auctionId): bool
    {
        return DB::transaction(function () use ($auctionId) {
            $auction = Auction::whereKey($auctionId)->lockForUpdate()->first();

            if ($auction === null || $auction->status !== AuctionStatus::Scheduled || now()->lt($auction->start_at)) {
                return false;
            }

            $this->transition($auction, AuctionStatus::Live);
            $auction->started_at = now();
            $auction->save();

            $this->events->record($auction, AuctionEventType::AuctionStarted, null, [
                'start_at' => ServerTime::format($auction->start_at),
                'started_at' => ServerTime::format($auction->started_at),
                'end_at' => ServerTime::format($auction->end_at),
            ]);

            $this->broadcaster->started($auction);

            // The close job is queued only after this transaction commits.
            CloseAuctionJob::scheduleFor($auction);

            return true;
        });
    }

    /** Must be called inside a transaction, on a locked row. */
    private function applySchedule(Auction $auction, User $admin): void
    {
        $this->transition($auction, AuctionStatus::Scheduled);

        if ($auction->start_at->lte(now())) {
            throw ValidationException::withMessages([
                'start_at' => 'The start time must be in the future to schedule the auction.',
            ]);
        }

        $auction->save();

        $this->events->record($auction, AuctionEventType::AuctionScheduled, $admin, [
            'start_at' => $auction->start_at->toIso8601ZuluString(),
            'end_at' => $auction->end_at->toIso8601ZuluString(),
        ]);

        // Queued only after the surrounding transaction commits.
        StartAuctionJob::scheduleFor($auction);
    }

    private function transition(Auction $auction, AuctionStatus $to): void
    {
        if (! $auction->status->canTransitionTo($to)) {
            throw new ApiException(
                ErrorCode::InvalidTransition,
                "An auction cannot move from {$auction->status->value} to {$to->value}.",
                ['from' => $auction->status->value, 'to' => $to->value],
            );
        }

        $auction->status = $to;
    }

    /** Re-reads the auction with an exclusive row lock held until the transaction ends. */
    private function lock(Auction $auction): Auction
    {
        return Auction::whereKey($auction->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Auction $auction): array
    {
        return [
            'title' => $auction->title,
            'description' => $auction->description,
            'starting_price' => $auction->starting_price,
            'reserve_price' => $auction->reserve_price,
            'min_increment' => $auction->min_increment,
            'start_at' => $auction->start_at?->toIso8601ZuluString(),
            'end_at' => $auction->end_at?->toIso8601ZuluString(),
            'anti_snipe_seconds' => $auction->anti_snipe_seconds,
            'extension_seconds' => $auction->extension_seconds,
            'max_extensions' => $auction->max_extensions,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['from' => $before[$field], 'to' => $value];
            }
        }

        return $changes;
    }
}
