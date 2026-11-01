<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Services\Reporting\AdminDashboard;
use App\Support\ServerTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** The admin home page: one request for everything it shows. */
class DashboardController extends Controller
{
    public function __construct(private readonly AdminDashboard $dashboard) {}

    public function index(): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return response()->json([
            'data' => $this->dashboard->summary(),
            ...ServerTime::meta(),
        ]);
    }
}
