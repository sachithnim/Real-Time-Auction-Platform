<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Auction;
use App\Models\Category;
use App\Support\ServerTime;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Admin management of auction categories.
 */
class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage', Auction::class);

        return CategoryResource::collection(Category::query()->withCount('auctions')->orderBy('name')->get())
            ->additional(ServerTime::meta());
    }

    public function store(SaveCategoryRequest $request): JsonResponse
    {
        Gate::authorize('manage', Auction::class);

        $category = Category::create($request->validated())->loadCount('auctions');

        return (new CategoryResource($category))->additional(ServerTime::meta())->response()->setStatusCode(201);
    }

    public function update(SaveCategoryRequest $request, Category $category): CategoryResource
    {
        Gate::authorize('manage', Auction::class);

        $category->update($request->validated());

        return (new CategoryResource($category->loadCount('auctions')))->additional(ServerTime::meta());
    }

    /** Refused while any auction (of any status) still uses the category. */
    public function destroy(Category $category): Response
    {
        Gate::authorize('manage', Auction::class);

        $inUse = $category->auctions()->count();
        if ($inUse > 0) {
            throw new ApiException(
                ErrorCode::CategoryInUse,
                "This category is used by {$inUse} auction(s). Move them to another category first.",
                ['auctions_count' => $inUse],
            );
        }

        try {
            $category->delete();
        } catch (QueryException $e) {
            // Assigned to an auction a moment ago: the foreign key refused the delete.
            if ($e->getCode() === '23000') {
                throw new ApiException(ErrorCode::CategoryInUse, 'This category is now used by an auction. Move it to another category first.');
            }

            throw $e;
        }

        return response()->noContent();
    }
}
