<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListBiddersRequest;
use App\Http\Resources\Admin\AdminBidderResource;
use App\Models\User;
use App\Services\Accounts\BidderAccounts;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bidder accounts for admins: find them, and disable or enable them.
 */
class BidderController extends Controller
{
    public function __construct(private readonly BidderAccounts $accounts) {}

    public function index(ListBiddersRequest $request): AnonymousResourceCollection
    {
        $bidders = User::query()
            ->where('role', UserRole::Bidder)
            ->withCount(['bids', 'wonAuctions'])
            ->when($request->validated('status') === 'active', fn ($query) => $query->whereNull('disabled_at'))
            ->when($request->validated('status') === 'disabled', fn ($query) => $query->whereNotNull('disabled_at'))
            ->when($request->validated('search'), function ($query, $search) {
                $like = '%'.addcslashes(trim($search), '\\%_').'%';
                $query->where(fn ($match) => $match->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return AdminBidderResource::collection($bidders)->additional(ServerTime::meta());
    }

    public function disable(Request $request, User $user): AdminBidderResource
    {
        return $this->respond($this->accounts->disable($user, $request->user()));
    }

    public function enable(User $user): AdminBidderResource
    {
        return $this->respond($this->accounts->enable($user));
    }

    private function respond(User $user): AdminBidderResource
    {
        return (new AdminBidderResource($user->loadCount(['bids', 'wonAuctions'])))->additional(ServerTime::meta());
    }
}
