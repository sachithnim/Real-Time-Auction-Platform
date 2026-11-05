<?php

namespace App\Http\Resources\Admin;

use App\Models\Payment;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment as admins see it: who owes what for which auction. Card data is
 * limited to brand and last four digits, as stored.
 *
 * @mixin Payment
 */
class AdminPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'auction' => ['id' => $this->auction_id, 'title' => $this->auction->title],
            'bidder' => ['id' => $this->user_id, 'name' => $this->user->name, 'email' => $this->user->email],
            'amount' => $this->amount,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'card_brand' => $this->card_brand,
            'card_last4' => $this->card_last4,
            'reference' => $this->reference,
            'failure_reason' => $this->failure_reason,
            'paid_at' => ServerTime::format($this->paid_at),
            'created_at' => ServerTime::format($this->created_at),
        ];
    }
}
