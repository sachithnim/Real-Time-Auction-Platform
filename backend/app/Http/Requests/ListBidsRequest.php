<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListBidsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
