<?php

namespace App\Http\Requests;

use App\Enums\AuctionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPublicAuctionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Drafts are never listed publicly.
            'status' => ['nullable', Rule::enum(AuctionStatus::class)->except([AuctionStatus::Draft])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // A category slug, e.g. ?category=antiques
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            // Only the signed-in bidder's own watchlist.
            'watching' => ['nullable', 'boolean'],
            // Words in the title, e.g. ?search=brass telescope
            'search' => ['nullable', 'string', 'max:100'],
            // Default (omitted): live first, then upcoming, then finished.
            'sort' => ['nullable', Rule::in(['ending_soon', 'newest', 'price_high', 'price_low'])],
        ];
    }
}
