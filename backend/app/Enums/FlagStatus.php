<?php

namespace App\Enums;

enum FlagStatus: string
{
    /** Waiting for an admin to look at it (or the pattern continued after review). */
    case Open = 'OPEN';

    /** An admin has looked at it. */
    case Reviewed = 'REVIEWED';
}
