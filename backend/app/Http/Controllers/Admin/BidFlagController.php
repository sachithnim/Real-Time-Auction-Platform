<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FlagStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListBidFlagsRequest;
use App\Http\Resources\Admin\BidFlagResource;
use App\Models\Auction;
use App\Models\BidFlag;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Suspicious bidding flags for admins (bonus challenge: suspicious bidding
 * detection). Admins list them and mark them reviewed; the detector creates them.
 */
class BidFlagController extends Controller
{
    public function index(ListBidFlagsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', Auction::class);

        $flags = BidFlag::query()
            ->with(['auction:id,title', 'user:id,name,email', 'relatedUser:id,name,email', 'reviewer:id,name'])
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            // Open flags first, then the most recently seen.
            ->orderByRaw('status = ? DESC', [FlagStatus::Open->value])
            ->orderByDesc('last_detected_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return BidFlagResource::collection($flags)->additional([
            'meta' => [
                'server_time' => ServerTime::now(),
                'open_count' => BidFlag::where('status', FlagStatus::Open)->count(),
            ],
        ]);
    }

    /** Marks a flag as reviewed. Repeating it is harmless. */
    public function review(Request $request, BidFlag $flag): BidFlagResource
    {
        Gate::authorize('manage', Auction::class);

        $flag->forceFill([
            'status' => FlagStatus::Reviewed,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return (new BidFlagResource($flag->load(['auction:id,title', 'user', 'relatedUser', 'reviewer'])))
            ->additional(ServerTime::meta());
    }
}
