<?php

namespace App\Http\Requests;

use App\Enums\EmailType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailPreferencesRequest extends FormRequest
{
    /**
     * Any subset of the email types, each true (on) or false (off).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['preferences' => ['required', 'array', 'min:1']];
        foreach (EmailType::cases() as $type) {
            $rules["preferences.{$type->value}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /** @return array<string, bool> */
    public function changes(): array
    {
        $known = array_map(fn (EmailType $type) => $type->value, EmailType::cases());

        return array_map('boolval', array_intersect_key($this->validated('preferences'), array_flip($known)));
    }
}
