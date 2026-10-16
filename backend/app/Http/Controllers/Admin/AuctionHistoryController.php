<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuctionEventsRequest;
use App\Http\Requests\ListBidsRequest;
use App\Http\Resources\Admin\AdminBidResource;
use App\Http\Resources\Admin\AuctionEventResource;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\User;
use App\Support\ServerTime;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Full-detail history for admins: bids with real identities and the audit trail.
 */
class AuctionHistoryController extends Controller
{
    public function bids(ListBidsRequest $request, Auction $auction): AnonymousResourceCollection
    {
        Gate::authorize('manage', Auction::class);

        $bids = $auction->bids()->reorder()
            ->with('user')
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 50))
            ->through(fn (Bid $bid) => $bid->setRelation('auction', $auction));

        return AdminBidResource::collection($bids)->additional(ServerTime::meta());
    }

    /** The audit trail in the order it was written. */
    public function events(ListAuctionEventsRequest $request, Auction $auction): AnonymousResourceCollection
    {
        Gate::authorize('manage', Auction::class);

        $events = $auction->events()
            ->with('actor')
            ->when($request->validated('type'), fn ($query, $type) => $query->where('type', $type))
            ->paginate($request->integer('per_page', 100));

        // Read before wrapping: the resource collection replaces the paginator's models.
        $users = $this->usersReferencedBy($events->getCollection());

        return AuctionEventResource::collection($events)->additional([
            'meta' => ['server_time' => ServerTime::now(), 'users' => $users],
        ]);
    }

    /** Payload keys that hold a user id. */
    private const USER_KEYS = ['bidder_id', 'user_id', 'leader_id', 'previous_leader_id', 'new_leader_id', 'winner_id', 'highest_bidder_id'];

    /**
     * Names for the user ids inside the payloads, so the audit log can say
     * "Asha Perera" instead of "user 7".
     *
     * @param  Collection<int, AuctionEvent>  $events
     * @return array<int, array{name: string, email: string}>|object
     */
    private function usersReferencedBy(Collection $events): array|object
    {
        $ids = $events
            ->flatMap(fn (AuctionEvent $event) => array_values(array_intersect_key($event->payload, array_flip(self::USER_KEYS))))
            ->filter(fn ($id) => is_int($id))
            ->unique();

        $users = User::whereIn('id', $ids)->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user) => [$user->id => ['name' => $user->name, 'email' => $user->email]])
            ->all();

        // An empty map must still serialise as a JSON object.
        return $users === [] ? (object) [] : $users;
    }
}
