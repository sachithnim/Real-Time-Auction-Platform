<?php

namespace App\Http\Controllers;

use App\Enums\AuctionStatus;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ServerTime;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public list of categories for the auction filter (bonus challenge: multiple
 * auction categories). Counts only auctions bidders can see (no drafts).
 */
class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->withCount(['auctions' => fn ($auctions) => $auctions->where('status', '!=', AuctionStatus::Draft)])
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories)->additional(ServerTime::meta());
    }
}
