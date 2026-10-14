<?php

namespace App\Enums;

enum IdempotencyStatus: string
{
    /** The first request with this key is still running. */
    case Processing = 'PROCESSING';

    /** Finished; the stored response is replayed for retries. */
    case Completed = 'COMPLETED';
}
