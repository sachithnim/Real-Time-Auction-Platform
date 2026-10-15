<?php

namespace App\Services;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\User;
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
    public function __construct(private readonly AuctionEventRecorder $events) {}

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

            return $auction;
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
