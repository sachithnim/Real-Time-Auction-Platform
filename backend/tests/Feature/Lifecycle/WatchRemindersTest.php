<?php

namespace Tests\Feature\Lifecycle;

use App\Events\User\UserNotification;
use App\Events\User\WatchedAuctionReminder;
use App\Models\Auction;
use App\Models\User;
use App\Services\Lifecycle\WatchReminders;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WatchRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([WatchedAuctionReminder::class]);
        $this->freezeSecond();
    }

    private function sweep(): int
    {
        return app(WatchReminders::class)->sendDue();
    }

    private function startingIn(int $minutes, array $attributes = []): Auction
    {
        return Auction::factory()->scheduled()->create(['start_at' => now()->addMinutes($minutes), ...$attributes]);
    }

    private function endingIn(int $minutes): Auction
    {
        return Auction::factory()->live()->create([
            'end_at' => now()->addMinutes($minutes), 'original_end_at' => now()->addMinutes($minutes),
        ]);
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}> [user id, kind, minutes left] per reminder sent
     */
    private function sent(): array
    {
        return Event::dispatched(WatchedAuctionReminder::class)
            ->map(fn (array $args) => [$args[0]->userId, $args[0]->data['kind'], $args[0]->data['minutes_left']])
            ->sortBy(fn (array $row) => $row[0])
            ->values()
            ->all();
    }

    public function test_watchers_are_told_an_auction_starts_soon(): void
    {
        $auction = $this->startingIn(8, ['title' => 'Signed Cricket Bat']);
        [$a, $b] = User::factory()->count(2)->create()->all();
        $a->watch($auction);
        $b->watch($auction);
        User::factory()->create(); // not watching: not reminded

        $this->assertSame(2, $this->sweep());

        $this->assertSame([[$a->id, 'STARTING_SOON', 8], [$b->id, 'STARTING_SOON', 8]], $this->sent());
        Event::assertDispatched(WatchedAuctionReminder::class, fn (WatchedAuctionReminder $event) => $event->userId === $a->id
            && $event->broadcastOn()[0]->name === "private-user.{$a->id}"
            && $event->broadcastAs() === 'WatchedAuctionReminder'
            && $event->broadcastWith()['auction_id'] === $auction->id
            && $event->broadcastWith()['title'] === 'Signed Cricket Bat'
            && $event->broadcastWith()['at'] === $auction->start_at->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_watchers_are_told_a_live_auction_ends_soon(): void
    {
        $auction = $this->endingIn(4);
        $bidder = User::factory()->create();
        $bidder->watch($auction);

        $this->sweep();

        $this->assertSame([[$bidder->id, 'ENDING_SOON', 4]], $this->sent());
    }

    public function test_nothing_is_sent_before_the_window_opens(): void
    {
        $bidder = User::factory()->create();
        $bidder->watch($this->startingIn(11));
        $bidder->watch($this->endingIn(6));

        $this->assertSame(0, $this->sweep());
        Event::assertNotDispatched(WatchedAuctionReminder::class);
    }

    public function test_each_reminder_is_sent_once_however_often_the_sweep_runs(): void
    {
        $auction = $this->endingIn(5);
        $bidder = User::factory()->create();
        $bidder->watch($auction);

        $this->sweep();
        $this->sweep();
        $this->travel(1)->minutes();
        $this->sweep();

        Event::assertDispatchedTimes(WatchedAuctionReminder::class, 1);
        $this->assertSame(1, DB::table('watch_reminders')->count());
    }

    public function test_the_same_auction_can_remind_once_before_the_start_and_once_before_the_end(): void
    {
        $auction = $this->startingIn(5);
        $bidder = User::factory()->create();
        $bidder->watch($auction);
        $this->sweep();

        // Later: it has opened and is now close to its end.
        $this->travel(6)->minutes();
        $auction->forceFill(['status' => 'LIVE', 'end_at' => now()->addMinutes(3)])->save();
        $this->sweep();

        $this->assertSame([[$bidder->id, 'STARTING_SOON', 5], [$bidder->id, 'ENDING_SOON', 3]], $this->sent());
    }

    public function test_cancelled_finished_and_overdue_auctions_send_nothing(): void
    {
        $bidder = User::factory()->create();
        $bidder->watch($this->startingIn(5, ['status' => 'CANCELLED']));
        $bidder->watch(Auction::factory()->completed()->create());
        // Past its end but not closed yet: too late for "ends soon".
        $overdue = $this->endingIn(5);
        $overdue->forceFill(['end_at' => now()->subSecond()])->save();
        $bidder->watch($overdue);

        $this->assertSame(0, $this->sweep());
    }

    public function test_the_scheduled_command_runs_the_sweep(): void
    {
        User::factory()->create()->watch($this->endingIn(2));

        $this->artisan('auctions:remind-watchers')
            ->expectsOutput('Sent 1 reminder(s).')
            ->assertSuccessful();
    }

    public function test_reminders_are_private_and_sent_only_after_commit(): void
    {
        $this->assertTrue(is_subclass_of(WatchedAuctionReminder::class, UserNotification::class));
        $this->assertTrue(is_subclass_of(WatchedAuctionReminder::class, ShouldDispatchAfterCommit::class));
    }
}
