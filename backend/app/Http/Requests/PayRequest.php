<?php

namespace App\Http\Requests;

use App\Services\Payments\SimulatedCardGateway;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * A (simulated) card payment. The details are checked the way a checkout would
 * check them; none of them except the brand and last four digits are stored.
 */
class PayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cardholder_name' => ['required', 'string', 'max:100'],
            'card_number' => ['required', 'string', 'max:30'],
            'expiry' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'cvc' => ['required', 'string', 'regex:/^\d{3}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expiry.regex' => 'Enter the expiry as MM/YY.',
            'cvc.regex' => 'The CVC is the 3 digits on the back of the card.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! SimulatedCardGateway::passesLuhn(SimulatedCardGateway::digits($this->input('card_number')))) {
                $validator->errors()->add('card_number', 'This card number is not valid.');
            }

            [$month, $year] = explode('/', $this->input('expiry'));
            $expires = Carbon::createFromDate(2000 + (int) $year, (int) $month, 1)->endOfMonth();
            if ($expires->isPast()) {
                $validator->errors()->add('expiry', 'This card has expired.');
            }
        }];
    }
}
