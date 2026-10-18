<?php

use Illuminate\Support\Facades\Route;

// This service is an API; the user interface is the separate React app (frontend/).
Route::get('/', fn () => response()->json([
    'name' => 'Real-Time Auction Platform API',
    'api' => url('/api'),
    'health' => url('/up'),
]));
