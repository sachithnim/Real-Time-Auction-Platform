<?php

namespace Tests\Unit\Enums;

use App\Enums\AuctionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuctionStatusTest extends TestCase
{
    /** @var list<string> */
    private const ALLOWED = [
        'DRAFT->SCHEDULED',
        'DRAFT->CANCELLED',
        'SCHEDULED->LIVE',
        'SCHEDULED->CANCELLED',
        'LIVE->COMPLETED',
        'LIVE->CANCELLED',
    ];

    /**
     * Every (from, to) pair, so no transition is left untested.
     *
     * @return array<string, array{AuctionStatus, AuctionStatus}>
     */
    public static function allPairs(): array
    {
        $pairs = [];
        foreach (AuctionStatus::cases() as $from) {
            foreach (AuctionStatus::cases() as $to) {
                $pairs["{$from->value}->{$to->value}"] = [$from, $to];
            }
        }

        return $pairs;
    }

    #[DataProvider('allPairs')]
    public function test_transition_is_allowed_only_along_the_lifecycle(AuctionStatus $from, AuctionStatus $to): void
    {
        $expected = in_array("{$from->value}->{$to->value}", self::ALLOWED, true);

        $this->assertSame($expected, $from->canTransitionTo($to));
    }

    public function test_only_drafts_are_editable(): void
    {
        foreach (AuctionStatus::cases() as $status) {
            $this->assertSame($status === AuctionStatus::Draft, $status->isEditable(), $status->value);
        }
    }

    public function test_completed_and_cancelled_are_final(): void
    {
        $final = array_filter(AuctionStatus::cases(), fn (AuctionStatus $s) => $s->isFinal());

        $this->assertEqualsCanonicalizing([AuctionStatus::Completed, AuctionStatus::Cancelled], array_values($final));
    }
}
