<?php

namespace App\Http\Controllers;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Requests\PayRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Auction;
use App\Services\Payments\PaymentService;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The winner's checkout for a sold auction (bonus challenge: payment simulation).
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function show(Request $request, Auction $auction): PaymentResource
    {
        $this->ensureVisible($auction);

        return (new PaymentResource($this->payments->forWinner($auction, $request->user())))->additional(ServerTime::meta());
    }

    public function store(PayRequest $request, Auction $auction): PaymentResource
    {
        $this->ensureVisible($auction);

        $payment = $this->payments->pay($auction, $request->user(), (string) $request->input('card_number'));

        return (new PaymentResource($payment))->additional(ServerTime::meta());
    }

    private function ensureVisible(Auction $auction): void
    {
        if (Gate::denies('view', $auction)) {
            throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');
        }
    }
}
