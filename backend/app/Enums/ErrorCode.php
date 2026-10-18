<?php

namespace App\Enums;

/**
 * Machine-readable error codes returned in every API error response.
 * Each code owns its HTTP status so the mapping lives in one place.
 */
enum ErrorCode: string
{
    // Generic
    case ValidationFailed = 'VALIDATION_FAILED';
    case StatefulRequestRequired = 'STATEFUL_REQUEST_REQUIRED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';

    // Auctions
    case AuctionNotFound = 'AUCTION_NOT_FOUND';
    case AuctionNotStarted = 'AUCTION_NOT_STARTED';
    case AuctionEnded = 'AUCTION_ENDED';
    case AuctionNotLive = 'AUCTION_NOT_LIVE';
    case InvalidTransition = 'INVALID_TRANSITION';
    case AuctionNotEditable = 'AUCTION_NOT_EDITABLE';

    // Bidding
    case InvalidBid = 'INVALID_BID';
    case BidTooLow = 'BID_TOO_LOW';
    case MaxAutoBidReached = 'MAX_AUTO_BID_REACHED';
    case BidConflictRetry = 'BID_CONFLICT_RETRY';

    // Idempotency
    case DuplicateRequest = 'DUPLICATE_REQUEST';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed,
            self::InvalidBid,
            self::BidTooLow,
            self::MaxAutoBidReached,
            self::IdempotencyKeyReused => 422,

            self::AuctionNotStarted,
            self::AuctionEnded,
            self::AuctionNotLive,
            self::InvalidTransition,
            self::AuctionNotEditable,
            self::BidConflictRetry,
            self::DuplicateRequest => 409,

            self::StatefulRequestRequired => 400,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound, self::AuctionNotFound => 404,
            self::MethodNotAllowed => 405,
            self::CsrfTokenMismatch => 419,
            self::TooManyRequests => 429,
            self::ServerError => 500,
        };
    }
}
