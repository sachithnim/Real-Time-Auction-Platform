<?php

namespace App\Enums;

/** Why a bidding pattern was flagged for review. */
enum FlagRule: string
{
    /** Many manual bids by one bidder in a short time (scripted or bot bidding). */
    case RapidBidding = 'RAPID_BIDDING';

    /** Many rejected bids by one bidder in a short time (probing the minimum). */
    case ManyRejections = 'MANY_REJECTIONS';

    /** Two accounts bidding on the same auction from the same IP (possible shill bidding). */
    case SharedIp = 'SHARED_IP';

    /** Only two bidders, trading the lead again and again (possible coordinated inflation). */
    case AlternatingLeaders = 'ALTERNATING_LEADERS';
}
