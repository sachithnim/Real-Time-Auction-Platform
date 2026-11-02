<?php

namespace App\Notifications;

use App\Enums\EmailType;

class AuctionWonEmail extends AuctionEmail
{
    public function type(): EmailType
    {
        return EmailType::Won;
    }

    protected function subject(): string
    {
        return "You won {$this->data['title']} for {$this->rs($this->data['final_price'])}";
    }

    protected function heading(): string
    {
        return 'Congratulations, you won!';
    }

    protected function lines(): array
    {
        return [
            "Your bid of **{$this->rs($this->data['final_price'])}** won **{$this->data['title']}**.",
            'Complete your payment on the auction page to secure the item.',
        ];
    }

    protected function buttonLabel(): string
    {
        return 'Pay now';
    }
}
