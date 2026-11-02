<?php

namespace App\Notifications;

use App\Enums\EmailType;
use App\Enums\WatchReminder;

class WatchReminderEmail extends AuctionEmail
{
    public function type(): EmailType
    {
        return EmailType::Reminders;
    }

    private function starting(): bool
    {
        return $this->data['kind'] === WatchReminder::StartingSoon->value;
    }

    private function inMinutes(): string
    {
        $minutes = (int) $this->data['minutes_left'];

        return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
    }

    protected function subject(): string
    {
        return $this->starting()
            ? "{$this->data['title']} starts in {$this->inMinutes()}"
            : "{$this->data['title']} ends in {$this->inMinutes()}";
    }

    protected function heading(): string
    {
        return $this->starting() ? 'Bidding opens soon' : 'Last chance to bid';
    }

    protected function lines(): array
    {
        return [$this->starting()
            ? "**{$this->data['title']}**, on your watchlist, opens for bidding in {$this->inMinutes()}."
            : "**{$this->data['title']}**, on your watchlist, closes in {$this->inMinutes()}. A bid in the final minutes extends the auction, so there is still time."];
    }

    protected function buttonLabel(): string
    {
        return 'View auction';
    }
}
