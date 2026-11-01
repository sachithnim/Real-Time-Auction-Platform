<?php

namespace App\Http\Resources;

use App\Models\AuctionImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A photo as anyone sees it: no storage path or uploader details.
 *
 * @mixin AuctionImage
 */
class AuctionImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
