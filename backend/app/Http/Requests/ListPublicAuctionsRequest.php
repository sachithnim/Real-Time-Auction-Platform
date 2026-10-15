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
        ];
    }
}
