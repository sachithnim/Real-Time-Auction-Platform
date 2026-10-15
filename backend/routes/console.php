<?php

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

// Safety nets behind the delayed start/close jobs. Both are idempotent.
Schedule::command('auctions:start-due')->everyTenSeconds()->withoutOverlapping();
Schedule::command('auctions:close-due')->everyMinute()->withoutOverlapping();

// Delete idempotency keys older than their retention window.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
