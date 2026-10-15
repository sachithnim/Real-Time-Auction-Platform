<?php

return [

    /*
    | Bid processing. A bid waits at most this many seconds for the auction row
    | lock, and a transaction that hits a lock timeout or deadlock is retried
    | this many times before the client gets 409 BID_CONFLICT_RETRY.
    */
    'bid_lock_wait_seconds' => (int) env('AUCTION_BID_LOCK_WAIT_SECONDS', 5),

    'bid_transaction_attempts' => (int) env('AUCTION_BID_TRANSACTION_ATTEMPTS', 3),

];
