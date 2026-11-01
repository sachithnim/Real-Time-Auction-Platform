<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderAuctionImagesRequest;
use App\Http\Requests\Admin\UploadAuctionImagesRequest;
use App\Http\Resources\AuctionImageResource;
use App\Models\Auction;
use App\Models\AuctionImage;
use App\Services\AuctionImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** Admin photo management. Each action answers with all the auction's photos, in order. */
class AuctionImageController extends Controller
{
    public function __construct(private readonly AuctionImageService $images) {}

    public function store(UploadAuctionImagesRequest $request, Auction $auction): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return $this->respond($this->images->add($auction, $request->file('images'), $request->user()), 201);
    }

    public function destroy(Request $request, Auction $auction, AuctionImage $image): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return $this->respond($this->images->remove($auction, $image, $request->user()));
    }

    public function reorder(ReorderAuctionImagesRequest $request, Auction $auction): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        return $this->respond($this->images->reorder($auction, array_map('intval', $request->validated('ids')), $request->user()));
    }

    /** @param  Collection<int, AuctionImage>  $images */
    private function respond(Collection $images, int $status = 200): JsonResponse
    {
        return AuctionImageResource::collection($images)->response()->setStatusCode($status);
    }
}
