<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo of an auction item. Written only by AuctionImageService, under the
 * auction's row lock.
 */
class AuctionImage extends Model
{
    /** Photos an auction may have; the first is its cover. */
    public const MAX_PER_AUCTION = 8;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /** Where the file is served: unguessable, and never reused, so it can be cached forever. */
    public function url(): string
    {
        return route('auction-images.show', ['filename' => $this->filename]);
    }
}
