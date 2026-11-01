<?php

namespace App\Http\Requests\Admin;

use App\Models\AuctionImage;
use Illuminate\Foundation\Http\FormRequest;

class UploadAuctionImagesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.AuctionImage::MAX_PER_AUCTION],
            // "image" alone would also allow GIF and BMP; SVG is refused (it can carry scripts).
            'images.*' => [
                'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120',
                'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.*.mimes' => 'Use a JPG, PNG or WebP photo.',
            'images.*.image' => 'Use a JPG, PNG or WebP photo.',
            'images.*.max' => 'Each photo must be 5 MB or smaller.',
            'images.*.dimensions' => 'Each photo must be between 200 and 8000 pixels on each side.',
        ];
    }
}
