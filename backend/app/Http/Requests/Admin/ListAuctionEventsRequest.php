<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuctionEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuctionEventsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(AuctionEventType::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
