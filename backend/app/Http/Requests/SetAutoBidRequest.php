<?php

namespace App\Http\Requests;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape check only; whether the maximum is high enough is decided under the lock.
 */
class SetAutoBidRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'max_amount' => ['required', 'integer:strict', 'min:1', 'max:1000000000000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(ErrorCode::InvalidBid, 'The maximum must be a positive whole number of rupees.', [
            'errors' => $validator->errors()->toArray(),
        ]);
    }
}
