<?php

namespace App\Http\Requests;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape check only. Whether the amount is high enough is decided by
 * BidService under the auction row lock, never here.
 */
class PlaceBidRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Whole rupees as a JSON integer: "25500" and 25500.5 are rejected.
            'amount' => ['required', 'integer:strict', 'min:1', 'max:1000000000000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(ErrorCode::InvalidBid, 'The bid amount must be a positive whole number of rupees.', [
            'errors' => $validator->errors()->toArray(),
        ]);
    }
}
