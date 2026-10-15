<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelAuctionRequest;
use App\Http\Requests\Admin\ListAuctionsRequest;
use App\Http\Requests\Admin\SaveAuctionRequest;
use App\Http\Resources\Admin\AdminAuctionResource;
use App\Models\Auction;
use App\Services\AuctionLifecycleService;
use App\Support\ServerTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Admin auction management. Thin: validation lives in Form Requests,
 * lifecycle rules in AuctionLifecycleService.
 */
class AuctionController extends Controller
{
    public function __construct(private readonly AuctionLifecycleService $lifecycle) {}

    public function index(ListAuctionsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', Auction::class);

        $auctions = Auction::query()
            ->withCount('bids')
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return AdminAuctionResource::collection($auctions)->additional(ServerTime::meta());
    }

    public function store(SaveAuctionRequest $request): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        $auction = $this->lifecycle->create($request->auctionData(), $request->user(), $request->wantsSchedule());

        return $this->respond($auction, 201);
    }

    public function show(Auction $auction): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return $this->respond($auction);
    }

    public function update(SaveAuctionRequest $request, Auction $auction): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return $this->respond($this->lifecycle->update($auction, $request->auctionData(), $request->user()));
    }

    public function schedule(Request $request, Auction $auction): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return $this->respond($this->lifecycle->schedule($auction, $request->user()));
    }

    public function cancel(CancelAuctionRequest $request, Auction $auction): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return $this->respond($this->lifecycle->cancel($auction, $request->user(), $request->validated('reason')));
    }

    private function respond(Auction $auction, int $status = 200): JsonResponse
    {
        $auction->loadCount('bids');

        return (new AdminAuctionResource($auction))
            ->additional(ServerTime::meta())
            ->response()
            ->setStatusCode($status);
    }
}
