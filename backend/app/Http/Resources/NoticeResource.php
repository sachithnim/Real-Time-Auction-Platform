<?php

namespace App\Http\Resources;

use App\Models\Notice;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox entry. `data` is what the live pop-up carried (auction, title,
 * prices): only ever the owner's own information.
 *
 * @mixin Notice
 */
class NoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'read' => $this->read_at !== null,
            // When it last changed: a repeated "Outbid" moves back to the top.
            'at' => ServerTime::format($this->updated_at),
        ];
    }
}
