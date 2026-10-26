<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Services\Realtime\AdminMonitor;
use App\Support\ServerTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Snapshot for the admin live monitor; live changes then arrive on the
 * private admin.monitor channel (bonus challenge: admin live monitoring).
 */
class MonitorController extends Controller
{
    public function __construct(private readonly AdminMonitor $monitor) {}

    public function index(): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return response()->json([
            'data' => $this->monitor->snapshot(),
            ...ServerTime::meta(),
        ]);
    }
}
