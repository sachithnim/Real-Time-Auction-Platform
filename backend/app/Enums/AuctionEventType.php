<?php

namespace App\Enums;

/**
 * Audit trail entries stored in auction_events.
 */
enum AuctionEventType: string
{
    case AuctionCreated = 'AUCTION_CREATED';
    case AuctionUpdated = 'AUCTION_UPDATED';
    case AuctionScheduled = 'AUCTION_SCHEDULED';
    case AuctionStarted = 'AUCTION_STARTED';
    case BidPlaced = 'BID_PLACED';
    case BidRejected = 'BID_REJECTED';
    case AutoBidPlaced = 'AUTO_BID_PLACED';
    case AutoBidConfigured = 'AUTO_BID_CONFIGURED';
    case AutoBidMaxReached = 'AUTO_BID_MAX_REACHED';
    case LeaderChanged = 'LEADER_CHANGED';
    case AuctionExtended = 'AUCTION_EXTENDED';
    case AuctionEnded = 'AUCTION_ENDED';
    case WinnerSelected = 'WINNER_SELECTED';
    case ReserveNotMet = 'RESERVE_NOT_MET';
    case AuctionCancelled = 'AUCTION_CANCELLED';
}
