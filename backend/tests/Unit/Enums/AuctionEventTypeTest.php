<?php

namespace Tests\Unit\Enums;

use App\Enums\AuctionEventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuctionEventTypeTest extends TestCase
{
    /**
     * Every audit event type the auction history must be able to record.
     *
     * @return array<string, array{string}>
     */
    public static function requiredTypes(): array
    {
        $types = [
            'AUCTION_CREATED', 'AUCTION_SCHEDULED', 'AUCTION_STARTED', 'BID_PLACED',
            'BID_REJECTED', 'AUTO_BID_PLACED', 'AUTO_BID_CONFIGURED', 'LEADER_CHANGED',
            'AUCTION_EXTENDED', 'AUCTION_ENDED', 'WINNER_SELECTED', 'RESERVE_NOT_MET',
            'AUCTION_CANCELLED',
        ];

        return array_combine($types, array_map(fn ($type) => [$type], $types));
    }

    #[DataProvider('requiredTypes')]
    public function test_required_audit_event_type_exists(string $type): void
    {
        $this->assertNotNull(AuctionEventType::tryFrom($type));
    }
}
