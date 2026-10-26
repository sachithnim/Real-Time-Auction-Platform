<?php

return [

    /*
    | Bid processing. A bid waits at most this many seconds for the auction row
    | lock, and a transaction that hits a lock timeout or deadlock is retried
    | this many times before the client gets 409 BID_CONFLICT_RETRY.
    */
    'bid_lock_wait_seconds' => (int) env('AUCTION_BID_LOCK_WAIT_SECONDS', 5),

    'bid_transaction_attempts' => (int) env('AUCTION_BID_TRANSACTION_ATTEMPTS', 3),

    /*
    | Watchlist reminders. Bidders watching an auction are told once when it is
    | about to start, and once when it is about to end, within these windows.
    */
    'remind_starting_within_minutes' => (int) env('AUCTION_REMIND_STARTING_MINUTES', 10),

    'remind_ending_within_minutes' => (int) env('AUCTION_REMIND_ENDING_MINUTES', 5),

];
