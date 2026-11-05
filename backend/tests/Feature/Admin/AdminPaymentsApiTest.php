<?php

namespace Tests\Feature\Admin;

use App\Enums\AuctionResult;
use App\Enums\PaymentStatus;
use App\Models\Auction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function payment(string $title, string $winner, PaymentStatus $status, int $amount): Payment
    {
        $user = User::factory()->create(['name' => $winner]);
        $auction = Auction::factory()->completed(AuctionResult::Sold)->create(['title' => $title, 'winner_id' => $user->id, 'final_price' => $amount]);

        // A sold auction may already have opened its payment.
        $payment = Payment::where('auction_id', $auction->id)->first() ?? new Payment;
        $payment->forceFill(['auction_id' => $auction->id, 'user_id' => $user->id, 'amount' => $amount, 'status' => $status, 'reference' => $status === PaymentStatus::Paid ? 'LB-'.$auction->id : null])->save();

        return $payment;
    }

    public function test_owed_payments_come_first_with_auction_bidder_and_totals(): void
    {
        $this->payment('Telescope', 'Asha Perera', PaymentStatus::Paid, 20_000);
        $this->payment('Pocket Watch', 'Ravi Kumar', PaymentStatus::Pending, 59_000);
        $this->payment('Raksha Mask', 'Nimal Silva', PaymentStatus::Failed, 9_500);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/payments')->assertOk();

        $this->assertSame(['FAILED', 'PENDING', 'PAID'], collect($response->json('data'))->pluck('status')->all());
        $response
            ->assertJsonPath('data.0.auction.title', 'Raksha Mask')
            ->assertJsonPath('data.0.bidder.name', 'Nimal Silva')
            ->assertJsonPath('counts', ['all' => 3, 'pending' => 1, 'failed' => 1, 'paid' => 1, 'paid_total' => 20_000, 'owed_total' => 68_500]);
    }

    public function test_payments_filter_by_status_and_search(): void
    {
        $this->payment('Telescope', 'Asha Perera', PaymentStatus::Paid, 20_000);
        $this->payment('Pocket Watch', 'Ravi Kumar', PaymentStatus::Pending, 59_000);

        $titles = fn (string $query) => collect($this->actingAs($this->admin)->getJson('/api/admin/payments'.$query)->json('data'))->pluck('auction.title')->all();

        $this->assertSame(['Telescope'], $titles('?status=PAID'));
        $this->assertSame(['Pocket Watch'], $titles('?search=ravi'));
        $this->assertSame(['Telescope'], $titles('?search=telesc'));
        $this->actingAs($this->admin)->getJson('/api/admin/payments?status=LOST')->assertUnprocessable();
    }

    public function test_bidders_cannot_see_the_payments_list(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/payments')->assertForbidden();
    }
}
