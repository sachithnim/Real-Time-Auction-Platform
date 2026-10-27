<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment as its payer (or an admin) sees it. No card data beyond brand and
 * last four digits exists to show.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'auction_id' => $this->auction_id,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'card_brand' => $this->card_brand,
            'card_last4' => $this->card_last4,
            'reference' => $this->reference,
            'failure_reason' => $this->failure_reason,
            'attempts' => $this->attempts,
            'paid_at' => ServerTime::format($this->paid_at),
        ];
    }
}
