<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Admin\AuctionController as AdminAuctionController;
use App\Http\Controllers\Admin\AuctionHistoryController;
use App\Http\Controllers\Admin\AuctionImageController;
use App\Http\Controllers\Admin\BidderController;
use App\Http\Controllers\Admin\BidFlagController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\MonitorController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\AuctionController;
use App\Http\Controllers\AuctionImageFileController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\AutoBidController;
use App\Http\Controllers\BidController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\EmailPreferenceController;
use App\Http\Controllers\MyBidsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WatchlistController;
use App\Http\Middleware\ScanBiddingActivity;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::get('session', [AuthController::class, 'session']);

    Route::middleware(['throttle:auth', 'session.required'])->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    // Forgot password: rate limited per email and per client like logging in.
    Route::middleware('throttle:auth')->group(function () {
        Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('reset-password', [PasswordResetController::class, 'reset']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Unsubscribe links in emails: no login, but the URL must carry a valid signature.
Route::middleware(['signed', 'throttle:30,1'])->group(function () {
    Route::get('email/unsubscribe/{user}/{type}', [EmailPreferenceController::class, 'unsubscribe'])->name('email.unsubscribe');
    Route::post('email/unsubscribe/{user}/{type}', [EmailPreferenceController::class, 'unsubscribeOneClick']);
});

// The "Confirm your email" link: no login needed, the signature is checked in the controller
// so a bad link lands on a friendly page instead of an error.
Route::get('email/verify/{user}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:30,1')
    ->name('verification.verify');

// Public: guests and bidders can browse auctions.
Route::get('categories', [CategoryController::class, 'index']);
Route::get('auctions', [AuctionController::class, 'index']);
Route::get('auctions/{auction}', [AuctionController::class, 'show']);
Route::get('auctions/{auction}/bids', [BidController::class, 'index']);
Route::get('auction-images/{filename}', [AuctionImageFileController::class, 'show'])
    ->where('filename', '[A-Za-z0-9]{40}\.(jpg|png|webp)')
    ->name('auction-images.show');

// Bidding: bidders only (admins cannot bid), rate limited, scanned for suspicious
// patterns after the response is sent, safe to retry.
Route::middleware(['auth:sanctum', 'role:BIDDER', 'verified.email', 'throttle:bids', ScanBiddingActivity::class, 'idempotent'])->group(function () {
    Route::post('auctions/{auction}/bids', [BidController::class, 'store']);
    Route::put('auctions/{auction}/auto-bid', [AutoBidController::class, 'update']);
});

// Paying for a won auction: winner only (checked in PaymentService), safe to retry.
Route::middleware(['auth:sanctum', 'role:BIDDER', 'verified.email', 'throttle:bids', 'idempotent'])->group(function () {
    Route::post('auctions/{auction}/payment', [PaymentController::class, 'store']);
});

// Every signed-in user's own account. Password checks are slowed per user.
Route::middleware(['auth:sanctum', 'throttle:10,1'])->group(function () {
    Route::put('me/profile', [AccountController::class, 'updateProfile']);
    Route::put('me/password', [AccountController::class, 'updatePassword']);
    Route::post('me/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1');
});

Route::middleware(['auth:sanctum', 'role:BIDDER'])->group(function () {
    Route::get('me/bids', [MyBidsController::class, 'index']);
    Route::get('me/notifications', [NotificationController::class, 'index']);
    Route::post('me/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('me/notifications/{notice}/read', [NotificationController::class, 'markRead'])->whereUuid('notice');
    Route::get('me/email-preferences', [EmailPreferenceController::class, 'show']);
    Route::put('me/email-preferences', [EmailPreferenceController::class, 'update']);
    Route::get('auctions/{auction}/auto-bid', [AutoBidController::class, 'show']);
    Route::get('auctions/{auction}/payment', [PaymentController::class, 'show']);
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
    Route::post('auctions/{auction}/images', [AuctionImageController::class, 'store']);
    Route::put('auctions/{auction}/images/order', [AuctionImageController::class, 'reorder']);
    // scopeBindings: the photo must belong to the auction in the URL.
    Route::delete('auctions/{auction}/images/{image}', [AuctionImageController::class, 'destroy'])->scopeBindings();
    Route::get('auctions/{auction}/bids', [AuctionHistoryController::class, 'bids']);
    Route::get('auctions/{auction}/events', [AuctionHistoryController::class, 'events']);
    Route::get('auctions/{auction}/bids/export', [ExportController::class, 'bids']);
    Route::get('results/export', [ExportController::class, 'results']);
    Route::get('payments', [AdminPaymentController::class, 'index']);
    Route::get('bidders', [BidderController::class, 'index']);
    Route::post('bidders/{user}/disable', [BidderController::class, 'disable']);
    Route::post('bidders/{user}/enable', [BidderController::class, 'enable']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('statistics', [StatisticsController::class, 'index']);
    Route::get('monitor', [MonitorController::class, 'index']);
    Route::get('categories', [AdminCategoryController::class, 'index']);
    Route::post('categories', [AdminCategoryController::class, 'store']);
    Route::put('categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy']);
    Route::get('flags', [BidFlagController::class, 'index']);
    Route::post('flags/{flag}/review', [BidFlagController::class, 'review']);
});
