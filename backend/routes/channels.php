<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Public channels need no authorization:
 *   auction.{id}  live state of one auction (bids, price, leader, extension, start, end)
 *   auctions      listing-level updates
 *
 * Private channel: notifications meant for one bidder only (outbid, maximum
 * reached, you won). Only that user may subscribe.
 */
Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id);
