<?php

namespace App\Notifications;

use App\Enums\EmailType;

class MaxReachedEmail extends AuctionEmail
{
    public function type(): EmailType
    {
        return EmailType::MaxReached;
    }

    protected function subject(): string
    {
        return "Your maximum of {$this->rs($this->data['max_amount'])} was passed";
    }

    protected function heading(): string
    {
        return 'Your maximum was passed';
    }

    protected function lines(): array
    {
        return [
            "The price on **{$this->data['title']}** is now **{$this->rs($this->data['current_price'])}**, above your maximum of {$this->rs($this->data['max_amount'])}. Automatic bidding has stopped for you.",
            "To stay in, raise your maximum to **{$this->rs($this->data['min_next_bid'])}** or more.",
        ];
    }

    protected function buttonLabel(): string
    {
        return 'Raise your maximum';
    }
}
