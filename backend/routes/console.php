<?php

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

// A sweep's overlap lock expires after this many minutes. Laravel's default is
// 24 hours, so a scheduler killed mid-run (a restart or deploy) would otherwise
// keep that sweep switched off for a day. The sweeps are idempotent, so a rare
// overlap after expiry is harmless.
$sweepLockMinutes = 5;

// Safety nets behind the delayed start/close jobs. Both are idempotent.
Schedule::command('auctions:start-due')->everyTenSeconds()->withoutOverlapping($sweepLockMinutes);
Schedule::command('auctions:close-due')->everyMinute()->withoutOverlapping($sweepLockMinutes);

// Watchlist reminders: "starts soon" / "ends soon", each sent once per bidder.
Schedule::command('auctions:remind-watchers')->everyMinute()->withoutOverlapping($sweepLockMinutes);

// Delete idempotency keys older than their retention window.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
