<?php

namespace App\Notifications;

use App\Enums\EmailType;

class OutbidEmail extends AuctionEmail
{
    public function type(): EmailType
    {
        return EmailType::Outbid;
    }

    protected function subject(): string
    {
        return "You've been outbid on {$this->data['title']}";
    }

    protected function heading(): string
    {
        return "You've been outbid";
    }

    protected function lines(): array
    {
        return [
            "Another bidder has taken the lead on **{$this->data['title']}**. The price is now **{$this->rs($this->data['current_price'])}**.",
            "Bid **{$this->rs($this->data['min_next_bid'])}** or more to lead again, or set a maximum and let LiveBid bid for you.",
        ];
    }

    protected function buttonLabel(): string
    {
        return "Bid {$this->rs($this->data['min_next_bid'])}";
    }
}
