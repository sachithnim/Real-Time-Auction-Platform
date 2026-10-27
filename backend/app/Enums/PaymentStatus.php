<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /** Waiting for the winner to pay. */
    case Pending = 'PENDING';

    /** Paid. Final: it cannot be paid again. */
    case Paid = 'PAID';

    /** The last attempt was declined; the winner may try again. */
    case Failed = 'FAILED';
}
