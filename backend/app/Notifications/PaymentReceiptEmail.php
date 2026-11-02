<?php

namespace App\Notifications;

use App\Enums\EmailType;
use Carbon\CarbonImmutable;

class PaymentReceiptEmail extends AuctionEmail
{
    public function type(): EmailType
    {
        return EmailType::Receipts;
    }

    protected function subject(): string
    {
        return "Receipt {$this->data['reference']}: {$this->data['title']}";
    }

    protected function heading(): string
    {
        return 'Payment received';
    }

    protected function lines(): array
    {
        $paidAt = CarbonImmutable::parse($this->data['paid_at'])->format('j M Y, H:i').' UTC';

        return [
            "Thank you. We received your payment for **{$this->data['title']}**.",
            "Amount: **{$this->rs($this->data['amount'])}**  \nCard: {$this->data['card_brand']} ending {$this->data['card_last4']}  \nReceipt: {$this->data['reference']}  \nPaid: {$paidAt}",
        ];
    }

    protected function buttonLabel(): string
    {
        return 'View auction';
    }
}
