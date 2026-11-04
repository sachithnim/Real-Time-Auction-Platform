<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            // The email is how the account logs in and resets its password, so changing it
            // needs the password too: an unattended signed-in browser is not enough.
            'current_password' => [Rule::requiredIf($this->changesEmail()), 'nullable', 'string', 'current_password:web'],
        ];
    }

    public function changesEmail(): bool
    {
        return strcasecmp((string) $this->input('email'), $this->user()->email) !== 0;
    }
}
