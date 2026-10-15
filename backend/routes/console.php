<?php

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

// Delete idempotency keys older than their retention window.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
