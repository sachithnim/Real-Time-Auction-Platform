<?php

namespace Tests\Feature\Admin;

use App\Enums\AuctionEventType;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\AuctionImage;
use App\Models\User;
use App\Services\AuctionImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuctionImageApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AuctionImageService::DISK);
        $this->admin = User::factory()->admin()->create();
    }

    /** @param  list<UploadedFile>  $files */
    private function upload(Auction $auction, array $files, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->post("/api/admin/auctions/{$auction->id}/images", ['images' => $files], ['Accept' => 'application/json']);
    }

    private function photo(string $name = 'item.jpg', int $width = 800, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    /** @return list<int> */
    private function idsOf(Auction $auction): array
    {
        return $auction->images()->pluck('id')->all();
    }

    // --- Upload ---------------------------------------------------------------

    public function test_admin_uploads_photos_in_order_with_the_first_as_cover(): void
    {
        $auction = Auction::factory()->create();

        $response = $this->upload($auction, [$this->photo('front.jpg'), $this->photo('back.png', 400, 400)])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.width', 800)
            ->assertJsonPath('data.0.height', 600);

        $images = $auction->images()->get();
        $this->assertSame([0, 1], $images->pluck('position')->all());
        $this->assertSame(['image/jpeg', 'image/png'], $images->pluck('mime_type')->all());
        foreach ($images as $image) {
            Storage::disk(AuctionImageService::DISK)->assertExists($image->path);
            // Stored under a random name, never the uploader's.
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.(jpg|png)$/', $image->filename);
        }
        $this->assertStringEndsWith("/api/auction-images/{$images[0]->filename}", $response->json('data.0.url'));
        $response->assertJsonMissingPath('data.0.path');
    }

    public function test_uploads_are_added_after_the_existing_photos(): void
    {
        $auction = Auction::factory()->create();
        $this->upload($auction, [$this->photo()]);

        $this->upload($auction, [$this->photo(), $this->photo()])->assertCreated()->assertJsonCount(3, 'data');

        $this->assertSame([0, 1, 2], $auction->images()->pluck('position')->all());
    }

    public function test_an_auction_holds_at_most_eight_photos(): void
    {
        $auction = Auction::factory()->create();
        $this->upload($auction, array_map(fn () => $this->photo(), range(1, 6)))->assertCreated();

        $this->upload($auction, [$this->photo(), $this->photo(), $this->photo()])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['images']]]]);

        // Nothing from the refused upload is kept: no rows and no stray files.
        $this->assertSame(6, $auction->images()->count());
        $this->assertCount(6, Storage::disk(AuctionImageService::DISK)->allFiles());
    }

    public function test_only_real_photos_of_a_sensible_size_are_accepted(): void
    {
        $auction = Auction::factory()->create();

        $cases = [
            'a document' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            'an svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'a gif' => UploadedFile::fake()->image('anim.gif', 400, 400),
            'too small' => $this->photo('tiny.jpg', 100, 100),
            'too large' => UploadedFile::fake()->image('huge.jpg', 800, 600)->size(6000),
        ];

        foreach ($cases as $case => $file) {
            $this->upload($auction, [$file])->assertUnprocessable();
            $this->assertSame(0, $auction->images()->count(), "{$case} was accepted.");
        }
    }

    public function test_photos_are_editable_while_scheduled_but_locked_once_live(): void
    {
        $this->upload(Auction::factory()->scheduled()->create(), [$this->photo()])->assertCreated();

        $live = Auction::factory()->live()->create();
        $this->upload($live, [$this->photo()])
            ->assertConflict()
            ->assertJsonPath('error.code', 'AUCTION_NOT_EDITABLE');

        $this->assertSame(0, $live->images()->count());
        $this->assertSame([], Storage::disk(AuctionImageService::DISK)->allFiles('auctions/'.$live->id));
    }

    public function test_bidders_and_guests_cannot_manage_photos(): void
    {
        $auction = Auction::factory()->create();

        $this->upload($auction, [$this->photo()], User::factory()->create())->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->post("/api/admin/auctions/{$auction->id}/images", ['images' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    // --- Remove and reorder -----------------------------------------------------

    public function test_removing_a_photo_deletes_its_file_and_closes_the_gap(): void
    {
        $auction = Auction::factory()->create();
        $this->upload($auction, [$this->photo(), $this->photo(), $this->photo()]);
        [$first, $second, $third] = $auction->images()->get();

        $this->actingAs($this->admin)->deleteJson("/api/admin/auctions/{$auction->id}/images/{$first->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id);

        Storage::disk(AuctionImageService::DISK)->assertMissing($first->path);
        $this->assertSame([$second->id, $third->id], $this->idsOf($auction));
        $this->assertSame([0, 1], $auction->images()->pluck('position')->all());
    }

    public function test_a_photo_can_only_be_removed_through_its_own_auction(): void
    {
        $mine = Auction::factory()->create();
        $other = Auction::factory()->create();
        $this->upload($other, [$this->photo()]);
        $photo = $other->images()->first();

        $this->actingAs($this->admin)->deleteJson("/api/admin/auctions/{$mine->id}/images/{$photo->id}")->assertNotFound();
        $this->assertSame(1, $other->images()->count());
    }

    public function test_reordering_sets_the_new_cover(): void
    {
        $auction = Auction::factory()->create();
        $this->upload($auction, [$this->photo(), $this->photo(), $this->photo()]);
        [$a, $b, $c] = $this->idsOf($auction);

        $this->actingAs($this->admin)->putJson("/api/admin/auctions/{$auction->id}/images/order", ['ids' => [$c, $a, $b]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $c);

        $this->assertSame([$c, $a, $b], $this->idsOf($auction));
    }

    public function test_reordering_must_list_every_photo_exactly_once(): void
    {
        $auction = Auction::factory()->create();
        $this->upload($auction, [$this->photo(), $this->photo()]);
        [$a, $b] = $this->idsOf($auction);
        $foreign = AuctionImage::query()->max('id') + 100;

        foreach ([[$a], [$a, $b, $foreign], [$a, $a]] as $ids) {
            $this->actingAs($this->admin)->putJson("/api/admin/auctions/{$auction->id}/images/order", ['ids' => $ids])->assertUnprocessable();
        }
        $this->assertSame([$a, $b], $this->idsOf($auction));
    }

    // --- Audit and public view ---------------------------------------------------

    public function test_every_photo_change_is_in_the_audit_log(): void
    {
        $auction = Auction::factory()->create();
        $this->upload($auction, [$this->photo(), $this->photo()]);
        [$a, $b] = $this->idsOf($auction);
        $this->actingAs($this->admin)->putJson("/api/admin/auctions/{$auction->id}/images/order", ['ids' => [$b, $a]]);
        $this->actingAs($this->admin)->deleteJson("/api/admin/auctions/{$auction->id}/images/{$b}");

        $events = AuctionEvent::where('auction_id', $auction->id)->where('type', AuctionEventType::PhotosChanged)->orderBy('id')->get();

        $this->assertSame(['added', 'reordered', 'removed'], $events->pluck('payload.action')->all());
        $this->assertSame($this->admin->id, $events[0]->actor_id);
        // assertEquals: MySQL stores JSON keys in its own order.
        $this->assertEquals(['action' => 'removed', 'image_ids' => [$b], 'count_before' => 2, 'count_after' => 1, 'was_cover' => true], $events[2]->payload);
    }

    public function test_bidders_see_the_photos_and_can_load_the_files(): void
    {
        $auction = Auction::factory()->scheduled()->create();
        $this->upload($auction, [$this->photo()]);
        $image = $auction->images()->first();

        $this->getJson('/api/auctions')->assertOk()->assertJsonPath('data.0.images.0.id', $image->id);
        $url = $this->getJson("/api/auctions/{$auction->id}")->assertOk()->json('data.images.0.url');

        $this->app['auth']->forgetGuards();
        $this->get(parse_url($url, PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_an_unknown_photo_file_is_not_found(): void
    {
        $this->getJson('/api/auction-images/'.str_repeat('a', 40).'.jpg')->assertNotFound();
        $this->getJson('/api/auction-images/..%2F..%2F.env')->assertNotFound();
    }
}
