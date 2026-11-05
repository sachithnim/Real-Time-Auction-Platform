<?php

namespace Tests\Feature\Admin;

use App\Models\Auction;
use App\Models\User;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use App\Support\CsvDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /** @return list<list<string>> */
    private function csv(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = preg_split('/\R/', trim(substr($content, 3)));

        return array_map(fn ($line) => str_getcsv($line, escape: ''), $lines);
    }

    public function test_the_bids_export_lists_every_bid_in_order_with_identities(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => null]);
        $asha = User::factory()->create(['name' => 'Asha Perera', 'email' => 'asha@example.com']);
        $ravi = User::factory()->create(['name' => 'Ravi Kumar', 'email' => 'ravi@example.com']);
        app(BidService::class)->placeBid($auction, $asha, 10_000);
        app(BidService::class)->configureAutoBid($auction, $ravi, 15_000);

        $response = $this->actingAs($this->admin)->get("/api/admin/auctions/{$auction->id}/bids/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload("auction-{$auction->id}-bids.csv");

        $rows = $this->csv($response->streamedContent());
        $this->assertSame(['Bid ID', 'Time (UTC)', 'Bidder', 'Email', 'Pseudonym', 'Type', 'Amount (Rs.)'], $rows[0]);
        $this->assertSame(['Asha Perera', 'asha@example.com', 'Bidder 1', 'MANUAL', '10000'], array_slice($rows[1], 2));
        $this->assertSame(['Ravi Kumar', 'ravi@example.com', 'Bidder 2', 'AUTO', '10100'], array_slice($rows[2], 2));
        $this->assertCount(3, $rows);
    }

    public function test_the_results_export_has_outcome_reserve_winner_and_payment(): void
    {
        $sold = Auction::factory()->live()->create(['title' => 'Telescope', 'starting_price' => 10_000, 'reserve_price' => 12_000]);
        $asha = User::factory()->create(['name' => 'Asha Perera', 'email' => 'asha@example.com']);
        app(BidService::class)->placeBid($sold, $asha, 12_500);
        $sold->forceFill(['end_at' => now()->subSecond()])->save();
        app(AuctionCloser::class)->close($sold->id);
        Auction::factory()->live()->create(['title' => 'Still running']);

        $rows = $this->csv($this->actingAs($this->admin)->get('/api/admin/results/export')->assertOk()->streamedContent());

        $this->assertCount(2, $rows); // the live auction is not a result yet
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('Telescope', $row['Title']);
        $this->assertSame('SOLD', $row['Result']);
        $this->assertSame('12000', $row['Reserve (Rs.)']);
        $this->assertSame('12500', $row['Final price (Rs.)']);
        $this->assertSame('asha@example.com', $row['Winner email']);
        $this->assertSame('PENDING', $row['Payment']);
    }

    public function test_text_that_looks_like_a_formula_is_not_run_by_spreadsheets(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil\")", CsvDownload::cell('=HYPERLINK("http://evil")'));
        $this->assertSame("'+1", CsvDownload::cell('+1'));
        $this->assertSame("'@SUM(A1)", CsvDownload::cell('@SUM(A1)'));
        $this->assertSame('Brass telescope', CsvDownload::cell('Brass telescope'));
        $this->assertSame(-5, CsvDownload::cell(-5));
        $this->assertNull(CsvDownload::cell(null));
    }

    public function test_bidders_cannot_download_exports(): void
    {
        $auction = Auction::factory()->live()->create();
        $bidder = User::factory()->create();

        $this->actingAs($bidder)->getJson("/api/admin/auctions/{$auction->id}/bids/export")->assertForbidden();
        $this->actingAs($bidder)->getJson('/api/admin/results/export')->assertForbidden();
    }
}
