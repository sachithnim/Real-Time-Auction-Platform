<?php

use App\Http\Controllers\Admin\AuctionController as AdminAuctionController;
use App\Http\Controllers\Admin\AuctionHistoryController;
use App\Http\Controllers\Admin\BidFlagController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\MonitorController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\AuctionController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AutoBidController;
use App\Http\Controllers\BidController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\WatchlistController;
use App\Http\Middleware\ScanBiddingActivity;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::get('session', [AuthController::class, 'session']);

    Route::middleware(['throttle:auth', 'session.required'])->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Public: guests and bidders can browse auctions.
Route::get('categories', [CategoryController::class, 'index']);
Route::get('auctions', [AuctionController::class, 'index']);
Route::get('auctions/{auction}', [AuctionController::class, 'show']);
Route::get('auctions/{auction}/bids', [BidController::class, 'index']);

// Bidding: bidders only (admins cannot bid), rate limited, scanned for suspicious
// patterns after the response is sent, safe to retry.
Route::middleware(['auth:sanctum', 'role:BIDDER', 'throttle:bids', ScanBiddingActivity::class, 'idempotent'])->group(function () {
    Route::post('auctions/{auction}/bids', [BidController::class, 'store']);
    Route::put('auctions/{auction}/auto-bid', [AutoBidController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'role:BIDDER'])->group(function () {
    Route::get('auctions/{auction}/auto-bid', [AutoBidController::class, 'show']);
    Route::put('auctions/{auction}/watch', [WatchlistController::class, 'store']);
    Route::delete('auctions/{auction}/watch', [WatchlistController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:ADMIN'])->prefix('admin')->group(function () {
    Route::get('auctions', [AdminAuctionController::class, 'index']);
    Route::post('auctions', [AdminAuctionController::class, 'store']);
    Route::get('auctions/{auction}', [AdminAuctionController::class, 'show']);
    Route::put('auctions/{auction}', [AdminAuctionController::class, 'update']);
    Route::post('auctions/{auction}/schedule', [AdminAuctionController::class, 'schedule']);
    Route::post('auctions/{auction}/cancel', [AdminAuctionController::class, 'cancel']);
    Route::get('auctions/{auction}/bids', [AuctionHistoryController::class, 'bids']);
    Route::get('auctions/{auction}/events', [AuctionHistoryController::class, 'events']);
    Route::get('statistics', [StatisticsController::class, 'index']);
    Route::get('monitor', [MonitorController::class, 'index']);
    Route::get('categories', [AdminCategoryController::class, 'index']);
    Route::post('categories', [AdminCategoryController::class, 'store']);
    Route::put('categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy']);
    Route::get('flags', [BidFlagController::class, 'index']);
    Route::post('flags/{flag}/review', [BidFlagController::class, 'review']);
});
