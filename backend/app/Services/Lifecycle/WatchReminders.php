<?php

namespace App\Services\Lifecycle;

use App\Enums\AuctionStatus;
use App\Enums\WatchReminder;
use App\Events\User\WatchedAuctionReminder;
use App\Models\Auction;
use App\Support\ServerTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reminds watchers that an auction is about to start or end (bonus challenge:
 * scheduled notifications). Run by a scheduler sweep every minute.
 *
 * Each reminder is sent at most once per bidder, auction and kind: it is first
 * claimed with an insert into watch_reminders (unique key), and only the run
 * that inserted the row sends it. Running the sweep twice, or two sweeps at
 * once, therefore sends nothing extra.
 */
class WatchReminders
{
    /**
     * @return int how many reminders were sent
     */
    public function sendDue(): int
    {
        $now = now();

        $startingSoon = Auction::query()
            ->where('status', AuctionStatus::Scheduled)
            ->where('start_at', '>', $now)
            ->where('start_at', '<=', $now->copy()->addMinutes((int) config('auction.remind_starting_within_minutes')));

        $endingSoon = Auction::query()
            ->where('status', AuctionStatus::Live)
            ->where('end_at', '>', $now)
            ->where('end_at', '<=', $now->copy()->addMinutes((int) config('auction.remind_ending_within_minutes')));

        return $this->remind($startingSoon, WatchReminder::StartingSoon)
            + $this->remind($endingSoon, WatchReminder::EndingSoon);
    }

    /**
     * @param  Builder<Auction>  $auctions
     */
    private function remind(Builder $auctions, WatchReminder $kind): int
    {
        $sent = 0;

        foreach ($auctions->get() as $auction) {
            $at = $kind === WatchReminder::StartingSoon ? $auction->start_at : $auction->end_at;
            $minutesLeft = max(1, (int) ceil(now()->diffInSeconds($at) / 60));

            foreach ($auction->watchers()->pluck('users.id') as $userId) {
                if ($this->claim($userId, $auction, $kind)) {
                    $sent += $this->send($userId, $auction, $kind, ServerTime::format($at), $minutesLeft);
                }
            }
        }

        return $sent;
    }

    /** True only for the run that recorded this reminder first. */
    private function claim(int $userId, Auction $auction, WatchReminder $kind): bool
    {
        return DB::table('watch_reminders')->insertOrIgnore([
            'user_id' => $userId,
            'auction_id' => $auction->id,
            'kind' => $kind->value,
            'sent_at' => now(),
        ]) === 1;
    }

    private function send(int $userId, Auction $auction, WatchReminder $kind, string $at, int $minutesLeft): int
    {
        try {
            WatchedAuctionReminder::dispatch($userId, ServerTime::now(), [
                'auction_id' => $auction->id,
                'title' => $auction->title,
                'kind' => $kind->value,
                'at' => $at,
                'minutes_left' => $minutesLeft,
            ]);

            return 1;
        } catch (Throwable $e) {
            // Not delivered: release the claim so the next sweep tries again.
            DB::table('watch_reminders')
                ->where(['user_id' => $userId, 'auction_id' => $auction->id, 'kind' => $kind->value])
                ->delete();
            Log::warning('Could not send a watchlist reminder.', ['auction_id' => $auction->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }
}
