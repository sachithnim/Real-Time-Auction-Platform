<?php

namespace Tests\Unit\Bidding;

use App\Enums\BidType;
use App\Services\Bidding\IncrementCalculator;
use App\Services\Bidding\PlannedBid;
use App\Services\Bidding\ProxyBidResolver;
use App\Services\Bidding\ProxyCandidate;
use App\Services\Bidding\ProxyResolution;
use Closure;
use Database\Seeders\BidIncrementRuleSeeder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProxyBidResolverTest extends TestCase
{
    private const A = 1;

    private const B = 2;

    private const C = 3;

    private ProxyBidResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ProxyBidResolver;
    }

    /** Assignment tiers with an auction minimum increment of 500. */
    private function increments(int $auctionMinimum = 500): Closure
    {
        $tiers = new IncrementCalculator(BidIncrementRuleSeeder::TIERS);

        return fn (int $price) => max($tiers->tierIncrement($price), $auctionMinimum);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-01-01 {$time}");
    }

    private function auto(int $user, int $max, string $time = '10:00:00'): ProxyCandidate
    {
        return new ProxyCandidate($user, $max, $this->at($time), BidType::Auto);
    }

    private function manual(int $user, int $amount, string $time = '10:00:00'): ProxyCandidate
    {
        return new ProxyCandidate($user, $amount, $this->at($time), BidType::Manual);
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function bids(ProxyResolution $resolution): array
    {
        return array_map(
            fn (PlannedBid $bid) => [$bid->userId, $bid->amount, $bid->type->value],
            $resolution->bids,
        );
    }

    // --- Assignment example -------------------------------------------------

    public function test_assignment_example_a_max_20000_b_max_15000_gives_15500_with_a_leading(): void
    {
        // A sets a 20,000 maximum first: alone, A opens at the starting price.
        $first = $this->resolver->resolve($this->auto(self::A, 20_000, '10:00'), [], null, 10_000, $this->increments());
        $this->assertSame([self::A, 10_000], [$first->leaderId, $first->price]);

        // B sets 15,000: A defends automatically, only as much as needed.
        $second = $this->resolver->resolve(
            $this->auto(self::B, 15_000, '10:05'),
            [$this->auto(self::A, 20_000, '10:00')],
            10_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame(self::A, $second->leaderId);
        $this->assertSame(15_500, $second->price);
        $this->assertSame([[self::B, 15_000, 'AUTO'], [self::A, 15_500, 'AUTO']], $this->bids($second));
    }

    public function test_the_same_example_works_in_the_other_order(): void
    {
        // B (15,000) leads first; A arrives with 20,000 and pays only 15,500.
        $resolution = $this->resolver->resolve(
            $this->auto(self::A, 20_000, '10:05'),
            [$this->auto(self::B, 15_000, '10:00')],
            10_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame([self::A, 15_500], [$resolution->leaderId, $resolution->price]);
        $this->assertSame([[self::A, 15_500, 'AUTO']], $this->bids($resolution));
    }

    // --- Ties ---------------------------------------------------------------

    public function test_equal_maximums_are_won_by_whoever_set_it_first(): void
    {
        $resolution = $this->resolver->resolve(
            $this->auto(self::B, 20_000, '10:05'),
            [$this->auto(self::A, 20_000, '10:00')],
            10_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame([self::A, 20_000], [$resolution->leaderId, $resolution->price]);
        $this->assertSame([[self::B, 20_000, 'AUTO'], [self::A, 20_000, 'AUTO']], $this->bids($resolution));
    }

    public function test_among_defenders_the_earliest_equal_maximum_defends(): void
    {
        $resolution = $this->resolver->resolve(
            $this->auto(self::C, 15_000, '10:10'),
            [$this->auto(self::B, 20_000, '10:05'), $this->auto(self::A, 20_000, '10:00')],
            12_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame(self::A, $resolution->leaderId);
    }

    public function test_a_manual_bid_equal_to_an_auto_maximum_loses_to_the_earlier_auto(): void
    {
        $resolution = $this->resolver->resolve(
            $this->manual(self::B, 20_000, '10:05'),
            [$this->auto(self::A, 20_000, '10:00')],
            15_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame([self::A, 20_000], [$resolution->leaderId, $resolution->price]);
        $this->assertSame([[self::B, 20_000, 'MANUAL'], [self::A, 20_000, 'AUTO']], $this->bids($resolution));
    }

    // --- Manual bids against auto-bidders ----------------------------------

    public function test_auto_bidder_answers_a_lower_manual_bid_with_one_increment(): void
    {
        $resolution = $this->resolver->resolve(
            $this->manual(self::C, 12_000),
            [$this->auto(self::A, 20_000, '09:00')],
            10_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame([self::A, 12_500], [$resolution->leaderId, $resolution->price]);
        $this->assertSame([[self::C, 12_000, 'MANUAL'], [self::A, 12_500, 'AUTO']], $this->bids($resolution));
    }

    public function test_auto_answer_is_capped_at_the_defenders_maximum(): void
    {
        $resolution = $this->resolver->resolve(
            $this->manual(self::C, 19_800),
            [$this->auto(self::A, 20_000, '09:00')],
            15_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame([self::A, 20_000], [$resolution->leaderId, $resolution->price]);
    }

    public function test_a_manual_bid_above_every_maximum_wins_at_its_own_amount(): void
    {
        $resolution = $this->resolver->resolve(
            $this->manual(self::C, 25_000),
            [$this->auto(self::A, 20_000, '09:00')],
            15_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame([self::C, 25_000], [$resolution->leaderId, $resolution->price]);
        $this->assertSame([[self::C, 25_000, 'MANUAL']], $this->bids($resolution));
    }

    public function test_a_manual_bid_with_no_competition_stands_at_its_amount(): void
    {
        $resolution = $this->resolver->resolve($this->manual(self::A, 23_000), [], null, 20_000, $this->increments());

        $this->assertSame([self::A, 23_000], [$resolution->leaderId, $resolution->price]);
    }

    // --- Auto-bids against a manual leader ---------------------------------

    public function test_auto_bid_beats_a_manual_leader_by_exactly_one_increment(): void
    {
        // B leads manually at 15,000; A's 20,000 maximum only needs 15,500.
        $resolution = $this->resolver->resolve(
            $this->auto(self::A, 20_000, '10:05'),
            [$this->manual(self::B, 15_000, '10:00')],
            15_000,
            10_000,
            $this->increments(),
        );

        $this->assertSame([self::A, 15_500], [$resolution->leaderId, $resolution->price]);
        $this->assertSame([[self::A, 15_500, 'AUTO']], $this->bids($resolution));
    }

    public function test_first_auto_bid_on_an_auction_without_bids_opens_at_the_starting_price(): void
    {
        $resolution = $this->resolver->resolve($this->auto(self::A, 50_000), [], null, 20_000, $this->increments());

        $this->assertSame([self::A, 20_000], [$resolution->leaderId, $resolution->price]);
        $this->assertSame([[self::A, 20_000, 'AUTO']], $this->bids($resolution));
    }

    // --- Increments come from the tier of the losing maximum ----------------

    public function test_winning_price_uses_the_increment_tier_of_the_second_highest_maximum(): void
    {
        $increments = $this->increments(auctionMinimum: 100);
        $resolve = fn (int $secondMax) => $this->resolver->resolve(
            $this->auto(self::A, 200_000, '10:05'),
            [$this->auto(self::B, $secondMax, '10:00')],
            10_000,
            10_000,
            $increments,
        )->price;

        $this->assertSame(10_100, $resolve(10_000));   // 10,000 is in the 100 tier
        $this->assertSame(10_501, $resolve(10_001));   // 10,001 is in the 500 tier
        $this->assertSame(50_500, $resolve(50_000));   // 500 tier
        $this->assertSame(51_001, $resolve(50_001));   // 1,000 tier
        $this->assertSame(102_501, $resolve(100_001)); // 2,500 tier
    }

    // --- Competing auto-bidders ---------------------------------------------

    public function test_three_competing_auto_bidders_resolve_to_second_highest_plus_increment(): void
    {
        $resolution = $this->resolver->resolve(
            $this->auto(self::C, 30_000, '10:10'),
            [$this->auto(self::A, 20_000, '10:00'), $this->auto(self::B, 26_000, '10:05')],
            20_500,
            10_000,
            $this->increments(),
        );

        // C beats the strongest defender (B, 26,000) by one increment.
        $this->assertSame([self::C, 26_500], [$resolution->leaderId, $resolution->price]);
    }
}
