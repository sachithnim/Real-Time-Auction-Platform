<?php

namespace App\Services\Payments;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Enums\ErrorCode;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuctionEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The winner's payment for a sold auction (bonus challenge: payment simulation).
 *
 * A payment is opened when the auction closes as SOLD. Paying runs in one
 * transaction holding the payment row lock, so two attempts at the same time are
 * processed one after the other and a paid payment can never be paid again.
 * Only the card's brand and last four digits are stored.
 */
class PaymentService
{
    public function __construct(
        private readonly SimulatedCardGateway $gateway,
        private readonly AuctionEventRecorder $events,
    ) {}

    /**
     * The payment owed for a sold auction, created if missing. Safe to call more
     * than once and concurrently: the unique auction_id keeps it to one row.
     */
    public function openFor(Auction $auction): Payment
    {
        $this->ensurePayable($auction);
        $now = now();

        DB::table('payments')->insertOrIgnore([
            'auction_id' => $auction->id,
            'user_id' => $auction->winner_id,
            'amount' => $auction->final_price,
            'status' => PaymentStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Payment::where('auction_id', $auction->id)->firstOrFail();
    }

    /**
     * The winner's payment, for the winner only.
     */
    public function forWinner(Auction $auction, User $user): Payment
    {
        $this->ensurePayable($auction);
        $this->ensureWinner($auction, $user);

        return $this->openFor($auction);
    }

    /**
     * Charges the winner's card for the final price. A declined card leaves the
     * payment FAILED (the winner may try again); it is not an error response.
     */
    public function pay(Auction $auction, User $user, string $cardNumber): Payment
    {
        $this->ensurePayable($auction);
        $this->ensureWinner($auction, $user);
        $this->openFor($auction);

        return DB::transaction(function () use ($auction, $user, $cardNumber) {
            $payment = Payment::where('auction_id', $auction->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Paid) {
                throw new ApiException(ErrorCode::PaymentAlreadyCompleted, 'This auction has already been paid for.', [
                    'reference' => $payment->reference,
                ]);
            }

            $digits = SimulatedCardGateway::digits($cardNumber);
            $result = $this->gateway->charge($digits, $payment->amount);

            $payment->forceFill([
                'attempts' => $payment->attempts + 1,
                'card_brand' => SimulatedCardGateway::brand($digits),
                'card_last4' => substr($digits, -4),
                'status' => $result['approved'] ? PaymentStatus::Paid : PaymentStatus::Failed,
                'failure_reason' => $result['reason'],
                'reference' => $result['approved'] ? 'PAY-'.Str::upper(Str::random(10)) : null,
                'paid_at' => $result['approved'] ? now() : null,
            ])->save();

            $this->events->record(
                $auction,
                $result['approved'] ? AuctionEventType::PaymentSucceeded : AuctionEventType::PaymentFailed,
                $user,
                array_filter([
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'card_brand' => $payment->card_brand,
                    'card_last4' => $payment->card_last4,
                    'reference' => $payment->reference,
                    'reason' => $result['reason'],
                    'attempt' => $payment->attempts,
                ], fn ($value) => $value !== null),
            );

            return $payment;
        });
    }

    private function ensurePayable(Auction $auction): void
    {
        if ($auction->result !== AuctionResult::Sold || $auction->winner_id === null) {
            throw new ApiException(ErrorCode::PaymentNotAvailable, 'There is nothing to pay: this auction was not sold.', [
                'status' => $auction->status->value,
                'result' => $auction->result?->value,
            ]);
        }
    }

    private function ensureWinner(Auction $auction, User $user): void
    {
        if ($auction->winner_id !== $user->id) {
            throw new ApiException(ErrorCode::Forbidden, 'Only the winner of this auction can pay for it.');
        }
    }
}
