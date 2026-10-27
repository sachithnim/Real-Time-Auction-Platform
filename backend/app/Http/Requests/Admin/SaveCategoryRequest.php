<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) or rename (PUT) a category. The name must be unique, and so must
 * the slug it produces ("Art" and "art!" would share the slug "art").
 */
class SaveCategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('categories', 'name')->ignore($this->route('category')),
            ],
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

            $slug = Category::slugFor((string) $this->input('name'));
            $current = $this->route('category');

            if ($slug === '') {
                $validator->errors()->add('name', 'Use at least one letter or number in the name.');
            } elseif (Category::where('slug', $slug)->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
                $validator->errors()->add('name', 'A category with a very similar name already exists.');
            }
        }];
    }
}
