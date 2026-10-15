<?php

use App\Http\Controllers\Admin\AuctionController as AdminAuctionController;
use App\Http\Controllers\AuctionController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BidController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Public: guests and bidders can browse auctions.
Route::get('auctions', [AuctionController::class, 'index']);
Route::get('auctions/{auction}', [AuctionController::class, 'show']);

// Bidding: bidders only (admins cannot bid), rate limited, safe to retry.
Route::middleware(['auth:sanctum', 'role:BIDDER', 'throttle:bids', 'idempotent'])->group(function () {
    Route::post('auctions/{auction}/bids', [BidController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'role:ADMIN'])->prefix('admin')->group(function () {
    Route::get('auctions', [AdminAuctionController::class, 'index']);
    Route::post('auctions', [AdminAuctionController::class, 'store']);
    Route::get('auctions/{auction}', [AdminAuctionController::class, 'show']);
    Route::put('auctions/{auction}', [AdminAuctionController::class, 'update']);
    Route::post('auctions/{auction}/schedule', [AdminAuctionController::class, 'schedule']);
    Route::post('auctions/{auction}/cancel', [AdminAuctionController::class, 'cancel']);
});
