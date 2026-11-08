<?php

namespace Tests\Feature\Admin;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\User;
use App\Services\AuctionImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RelistAuctionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AuctionImageService::DISK);
        $this->admin = User::factory()->admin()->create();
    }

    private function unsold(array $attributes = []): Auction
    {
        return Auction::factory()->completed(AuctionResult::ReserveNotMet)->create([
            'title' => 'Brass Telescope', 'starting_price' => 15_000, 'reserve_price' => 30_000, 'min_increment' => 500,
            'anti_snipe_seconds' => 90, 'extension_seconds' => 60, 'max_extensions' => 2, ...$attributes,
        ]);
    }

    private function relist(Auction $auction, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/admin/auctions/{$auction->id}/relist");
    }

    public function test_an_unsold_auction_becomes_a_new_draft_with_the_same_details(): void
    {
        $source = $this->unsold();

        $response = $this->relist($source)
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.title', 'Brass Telescope')
            ->assertJsonPath('data.reserve_price', 30_000)
            ->assertJsonPath('data.max_extensions', 2)
            ->assertJsonPath('data.bids_count', 0);

        $draft = Auction::findOrFail($response->json('data.id'));
        $this->assertNotSame($source->id, $draft->id);
        $this->assertNull($draft->current_price);
        // Tomorrow on the hour, as long as the original.
        $this->assertEquals(now()->addDay()->startOfHour(), $draft->start_at);
        $this->assertSame(
            $source->original_end_at->getTimestamp() - $source->start_at->getTimestamp(),
            $draft->end_at->getTimestamp() - $draft->start_at->getTimestamp(),
        );
    }

    public function test_both_audit_trails_point_at_each_other(): void
    {
        $source = $this->unsold();
        $draftId = $this->relist($source)->json('data.id');

        $relisted = AuctionEvent::where('auction_id', $source->id)->where('type', AuctionEventType::AuctionRelisted)->sole();
        $this->assertSame($draftId, $relisted->payload['new_auction_id']);
        $created = AuctionEvent::where('auction_id', $draftId)->where('type', AuctionEventType::AuctionCreated)->sole();
        $this->assertSame($source->id, $created->payload['relisted_from']);
    }

    public function test_photos_are_copied_as_separate_files_in_the_same_order(): void
    {
        $source = Auction::factory()->create();
        app(AuctionImageService::class)->add($source, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')], $this->admin);
        $source->forceFill(['status' => 'COMPLETED', 'result' => AuctionResult::NoBids])->save();
        $originals = $source->images()->get();

        $draft = Auction::findOrFail($this->relist($source)->assertCreated()->json('data.id'));
        $copies = $draft->images()->get();

        $this->assertCount(2, $copies);
        foreach ($copies as $i => $copy) {
            $this->assertNotSame($originals[$i]->filename, $copy->filename);
            $this->assertSame($originals[$i]->position, $copy->position);
            Storage::disk(AuctionImageService::DISK)->assertExists($copy->path);
        }

        // Removing a copy leaves the original's file alone.
        app(AuctionImageService::class)->remove($draft, $copies[0], $this->admin);
        Storage::disk(AuctionImageService::DISK)->assertExists($originals[0]->path);
    }

    public function test_a_cancelled_auction_can_be_relisted(): void
    {
        $this->relist(Auction::factory()->cancelled()->create())->assertCreated();
    }

    public function test_sold_open_and_draft_auctions_cannot_be_relisted(): void
    {
        $this->relist(Auction::factory()->completed(AuctionResult::Sold)->create())->assertConflict()->assertJsonPath('error.code', 'INVALID_TRANSITION');
        $this->relist(Auction::factory()->live()->create())->assertConflict();
        $this->relist(Auction::factory()->create())->assertConflict();

        $this->assertSame(3, Auction::count());
    }

    public function test_bidders_cannot_relist(): void
    {
        $this->relist($this->unsold(), User::factory()->create())->assertForbidden();
    }
}
