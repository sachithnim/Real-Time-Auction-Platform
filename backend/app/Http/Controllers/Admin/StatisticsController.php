<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Services\Reporting\AuctionStatistics;
use App\Support\ServerTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Platform statistics for admins (bonus challenge: auction statistics).
 */
class StatisticsController extends Controller
{
    public function __construct(private readonly AuctionStatistics $statistics) {}

    public function index(): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return response()->json([
            'data' => $this->statistics->summary(),
            ...ServerTime::meta(),
        ]);
    }
}
