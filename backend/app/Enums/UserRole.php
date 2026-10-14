<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case Bidder = 'BIDDER';
}
