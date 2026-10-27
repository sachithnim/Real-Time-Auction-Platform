<?php

namespace Tests\Feature;

use App\Enums\AuctionEventType;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function category(string $name): Category
    {
        return Category::create(['name' => $name]);
    }

    // --- Public ------------------------------------------------------------------

    public function test_anyone_can_list_categories_with_counts_that_hide_drafts(): void
    {
        $art = $this->category('Art');
        $this->category('Antiques');
        Auction::factory()->live()->for($art)->create();
        Auction::factory()->completed()->for($art)->create();
        Auction::factory()->for($art)->create(); // draft: invisible to bidders

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data', [
                ['id' => Category::where('slug', 'antiques')->value('id'), 'name' => 'Antiques', 'slug' => 'antiques', 'auctions_count' => 0],
                ['id' => $art->id, 'name' => 'Art', 'slug' => 'art', 'auctions_count' => 2],
            ]);
    }

    public function test_the_auction_list_filters_by_category_and_shows_it(): void
    {
        $art = $this->category('Art');
        $mask = Auction::factory()->live()->for($art)->create(['title' => 'Kandyan Mask']);
        Auction::factory()->live()->for($this->category('Antiques'))->create();
        Auction::factory()->live()->create(); // no category

        $this->getJson('/api/auctions?category=art')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mask->id)
            ->assertJsonPath('data.0.category', ['id' => $art->id, 'name' => 'Art', 'slug' => 'art']);

        $this->getJson("/api/auctions/{$mask->id}")->assertJsonPath('data.category.slug', 'art');
    }

    public function test_an_unknown_category_filter_is_a_validation_error(): void
    {
        $this->getJson('/api/auctions?category=does-not-exist')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    // --- Admin management -------------------------------------------------------------

    public function test_an_admin_creates_renames_and_deletes_a_category(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/categories', ['name' => 'Jewellery & watches'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'jewellery-watches')
            ->assertJsonPath('data.auctions_count', 0)
            ->json('data.id');

        $this->actingAs($this->admin)->putJson("/api/admin/categories/{$id}", ['name' => 'Watches'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Watches')
            ->assertJsonPath('data.slug', 'watches');

        $this->actingAs($this->admin)->deleteJson("/api/admin/categories/{$id}")->assertNoContent();
        $this->assertSame(0, Category::count());
    }

    public function test_names_and_their_slugs_must_be_unique(): void
    {
        $art = $this->category('Art');

        $this->actingAs($this->admin)->postJson('/api/admin/categories', ['name' => 'Art'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->actingAs($this->admin)->postJson('/api/admin/categories', ['name' => 'art!'])
            ->assertUnprocessable()->assertJsonPath('error.details.errors.name.0', 'A category with a very similar name already exists.');
        $this->actingAs($this->admin)->postJson('/api/admin/categories', ['name' => '!!!'])
            ->assertUnprocessable()->assertJsonPath('error.details.errors.name.0', 'Use at least one letter or number in the name.');

        // Renaming a category to its own name is fine.
        $this->actingAs($this->admin)->putJson("/api/admin/categories/{$art->id}", ['name' => 'Art'])->assertOk();
    }

    public function test_a_category_still_used_by_an_auction_cannot_be_deleted(): void
    {
        $art = $this->category('Art');
        Auction::factory()->completed()->for($art)->create();

        $this->actingAs($this->admin)->deleteJson("/api/admin/categories/{$art->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'CATEGORY_IN_USE')
            ->assertJsonPath('error.details.auctions_count', 1);

        $this->assertTrue($art->exists());
    }

    public function test_the_admin_list_counts_every_auction_including_drafts(): void
    {
        $art = $this->category('Art');
        Auction::factory()->for($art)->create();

        $this->actingAs($this->admin)->getJson('/api/admin/categories')->assertOk()->assertJsonPath('data.0.auctions_count', 1);
    }

    public function test_bidders_and_guests_cannot_manage_categories(): void
    {
        $art = $this->category('Art');
        $bidder = User::factory()->create();

        $this->actingAs($bidder)->postJson('/api/admin/categories', ['name' => 'Coins'])->assertForbidden();
        $this->actingAs($bidder)->deleteJson("/api/admin/categories/{$art->id}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/admin/categories', ['name' => 'Coins'])->assertUnauthorized();

        $this->assertSame(1, Category::count());
    }

    // --- Auctions with a category --------------------------------------------------------

    public function test_an_admin_gives_an_auction_a_category_and_the_change_is_audited(): void
    {
        $art = $this->category('Art');
        $antiques = $this->category('Antiques');

        $fields = [
            'title' => 'Kandyan Mask', 'description' => 'Carved kaduru wood.',
            'starting_price' => 5_000, 'reserve_price' => null, 'min_increment' => 100,
            'start_at' => now()->addDay()->startOfMinute()->toIso8601ZuluString(),
            'end_at' => now()->addDays(2)->startOfMinute()->toIso8601ZuluString(),
            'anti_snipe_seconds' => 120, 'extension_seconds' => 120, 'max_extensions' => 3,
        ];

        $id = $this->actingAs($this->admin)->postJson('/api/admin/auctions', [...$fields, 'category_id' => $art->id])
            ->assertCreated()
            ->assertJsonPath('data.category.slug', 'art')
            ->json('data.id');

        $this->actingAs($this->admin)->putJson("/api/admin/auctions/{$id}", [...$fields, 'category_id' => $antiques->id])
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Antiques');

        $update = AuctionEvent::where('auction_id', $id)->where('type', AuctionEventType::AuctionUpdated)->sole();
        // MySQL JSON columns store object keys in their own order, so compare by key.
        $this->assertEquals(['from' => $art->id, 'to' => $antiques->id], $update->payload['changes']['category_id']);
        $this->assertSame(['category_id'], array_keys($update->payload['changes']), 'Only the category changed.');
    }

    public function test_an_unknown_category_id_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/auctions', [
            'title' => 'X', 'description' => 'Y', 'category_id' => 999_999, 'starting_price' => 1_000, 'min_increment' => 100,
            'start_at' => now()->addDay()->toIso8601ZuluString(), 'end_at' => now()->addDays(2)->toIso8601ZuluString(),
            'anti_snipe_seconds' => 0, 'extension_seconds' => 0, 'max_extensions' => 0,
        ])->assertUnprocessable()->assertJsonPath('error.details.errors.category_id.0', 'The selected category id is invalid.');
    }

    public function test_the_default_categories_seed_once(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame(count(CategorySeeder::DEFAULTS), Category::count());
        $this->assertTrue(Category::where('slug', 'sports-memorabilia')->exists());
    }
}
