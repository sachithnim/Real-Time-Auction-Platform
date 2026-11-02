<?php

namespace App\Notifications;

use App\Enums\EmailType;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

/**
 * A notification email about one auction, sent in the background by the queue
 * worker so a slow mail server never slows a bid. Each type supplies its wording;
 * this class adds the item, its cover photo, the button and the unsubscribe links.
 *
 * Privacy matches the site: no other bidder's identity, maximum or the reserve.
 */
abstract class AuctionEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /** Retries for a temporarily unreachable mail server. */
    public int $tries = 3;

    /** @param  array<string, mixed>  $data  The notification event's data (auction_id, title, prices…). */
    public function __construct(public readonly array $data) {}

    abstract public function type(): EmailType;

    abstract protected function subject(): string;

    abstract protected function heading(): string;

    /** @return list<string> Short paragraphs (Markdown). */
    abstract protected function lines(): array;

    abstract protected function buttonLabel(): string;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $auction = Auction::with('images')->find($this->data['auction_id']);
        $unsubscribe = URL::signedRoute('email.unsubscribe', ['user' => $notifiable->id, 'type' => $this->type()->value]);

        return (new MailMessage)
            ->subject($this->subject())
            ->markdown('mail.auction', [
                'name' => $notifiable->first_name ?: $notifiable->name,
                'heading' => $this->heading(),
                'lines' => $this->lines(),
                'title' => $auction->title ?? $this->data['title'],
                'photo' => $auction?->images->first()?->url(),
                'buttonLabel' => $this->buttonLabel(),
                'buttonUrl' => $this->frontend("/auctions/{$this->data['auction_id']}"),
                'typeLabel' => $this->type()->label(),
                'unsubscribeUrl' => $unsubscribe,
                'settingsUrl' => $this->frontend('/account/emails'),
            ])
            // One-click unsubscribe from the mail app's own button (RFC 8058).
            ->withSymfonyMessage(function (Email $message) use ($unsubscribe) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$unsubscribe}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    protected function frontend(string $path): string
    {
        return config('app.frontend_url').$path;
    }

    protected function rs(mixed $amount): string
    {
        return 'Rs. '.number_format((int) $amount);
    }
}
