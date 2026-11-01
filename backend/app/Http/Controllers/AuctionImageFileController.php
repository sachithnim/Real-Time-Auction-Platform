<?php

namespace App\Http\Controllers;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AuctionImage;
use App\Services\AuctionImageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a photo file. Public, like the auction pages: the 40-character random
 * filename cannot be guessed, so a draft's photos are only reachable through
 * the admin API that lists them.
 */
class AuctionImageFileController extends Controller
{
    public function show(string $filename): StreamedResponse
    {
        $image = AuctionImage::where('filename', $filename)->first();

        if ($image === null || ! Storage::disk(AuctionImageService::DISK)->exists($image->path)) {
            throw new ApiException(ErrorCode::NotFound, 'Photo not found.');
        }

        return Storage::disk(AuctionImageService::DISK)->response($image->path, $image->filename, [
            'Content-Type' => $image->mime_type,
            // A filename is never reused for different content, so browsers may keep it.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
