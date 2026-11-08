<?php

namespace App\Services;

use App\Enums\AuctionEventType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Adds, removes and reorders an auction's photos.
 *
 * Each change locks the auction row first (like every other auction change), so it
 * cannot race the auction starting. Files are written before the transaction and
 * deleted after it commits: the database never points at a missing file, and a
 * failed change leaves no stray files behind.
 */
class AuctionImageService
{
    public const DISK = 'local';

    public function __construct(private readonly AuctionEventRecorder $events) {}

    /**
     * @param  list<UploadedFile>  $files  Already validated as images.
     * @return Collection<int, AuctionImage> All the auction's photos, in order.
     */
    public function add(Auction $auction, array $files, User $admin): Collection
    {
        $stored = array_map(fn (UploadedFile $file) => $this->store($auction, $file), $files);

        try {
            return DB::transaction(function () use ($auction, $stored, $admin) {
                $auction = $this->lockEditable($auction);
                $before = $auction->images()->count();

                if ($before + count($stored) > AuctionImage::MAX_PER_AUCTION) {
                    throw ValidationException::withMessages([
                        'images' => 'An auction can have at most '.AuctionImage::MAX_PER_AUCTION." photos. It has {$before} already.",
                    ]);
                }

                $added = [];
                foreach ($stored as $i => $attributes) {
                    $image = new AuctionImage;
                    $image->forceFill([...$attributes, 'auction_id' => $auction->id, 'position' => $before + $i])->save();
                    $added[] = $image->id;
                }

                $this->events->record($auction, AuctionEventType::PhotosChanged, $admin, [
                    'action' => 'added', 'image_ids' => $added, 'count_before' => $before, 'count_after' => $before + count($added),
                ]);

                return $auction->images()->get();
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete(array_column($stored, 'path'));
            throw $e;
        }
    }

    /** @return Collection<int, AuctionImage> */
    public function remove(Auction $auction, AuctionImage $image, User $admin): Collection
    {
        return DB::transaction(function () use ($auction, $image, $admin) {
            $auction = $this->lockEditable($auction);
            $images = $auction->images()->get();
            $removed = $images->firstWhere('id', $image->id) ?? throw new ApiException(ErrorCode::NotFound, 'Photo not found.');

            $removed->delete();
            $remaining = $images->reject(fn (AuctionImage $i) => $i->id === $removed->id)->values();
            $this->renumber($remaining);

            $this->events->record($auction, AuctionEventType::PhotosChanged, $admin, [
                'action' => 'removed', 'image_ids' => [$removed->id], 'count_before' => $images->count(), 'count_after' => $remaining->count(),
                'was_cover' => $removed->position === 0,
            ]);

            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($removed->path));

            return $remaining;
        });
    }

    /**
     * @param  list<int>  $ids  Every photo of the auction, in the new order.
     * @return Collection<int, AuctionImage>
     */
    public function reorder(Auction $auction, array $ids, User $admin): Collection
    {
        return DB::transaction(function () use ($auction, $ids, $admin) {
            $auction = $this->lockEditable($auction);
            $images = $auction->images()->get();
            $before = $images->pluck('id')->all();

            $sameSet = count($ids) === count($before) && array_diff($ids, $before) === [] && array_diff($before, $ids) === [];
            if (! $sameSet) {
                throw ValidationException::withMessages(['ids' => 'List every photo of this auction exactly once.']);
            }

            $ordered = collect($ids)->map(fn (int $id) => $images->firstWhere('id', $id));
            $this->renumber($ordered);

            if ($ids !== $before) {
                $this->events->record($auction, AuctionEventType::PhotosChanged, $admin, [
                    'action' => 'reordered', 'image_ids' => $ids, 'previous_order' => $before,
                    'cover_changed' => $ids[0] !== $before[0],
                ]);
            }

            return $ordered->values();
        });
    }

    /**
     * Writes the file under a random name. The extension comes from the detected
     * content type, never from the name the uploader chose.
     *
     * @return array<string, mixed>
     */
    /**
     * Copies every photo of one auction to another as new files with new names, so
     * removing a photo from either never affects the other. Call it inside the
     * transaction that creates `$to`; if that rolls back, delete the returned paths.
     *
     * @return list<string> The new files' paths.
     */
    public function copyAll(Auction $from, Auction $to): array
    {
        $disk = Storage::disk(self::DISK);
        $paths = [];

        foreach ($from->images()->get() as $image) {
            $filename = Str::random(40).'.'.pathinfo($image->filename, PATHINFO_EXTENSION);
            $path = "auctions/{$to->id}/{$filename}";
            $disk->copy($image->path, $path);
            $paths[] = $path;

            $copy = $image->replicate(['auction_id', 'filename', 'path']);
            $copy->forceFill(['auction_id' => $to->id, 'filename' => $filename, 'path' => $path])->save();
        }

        return $paths;
    }

    private function store(Auction $auction, UploadedFile $file): array
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $filename = Str::random(40).'.'.$extension;
        [$width, $height] = getimagesize($file->getRealPath()) ?: [0, 0];

        $path = $file->storeAs("auctions/{$auction->id}", $filename, self::DISK);

        return [
            'filename' => $filename,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ];
    }

    private function lockEditable(Auction $auction): Auction
    {
        $auction = Auction::whereKey($auction->getKey())->lockForUpdate()->firstOrFail();

        if (! $auction->status->photosEditable()) {
            throw new ApiException(
                ErrorCode::AuctionNotEditable,
                'Photos can only be changed before the auction starts.',
                ['status' => $auction->status->value],
            );
        }

        return $auction;
    }

    /** @param  Collection<int, AuctionImage>  $images */
    private function renumber(Collection $images): void
    {
        $images->values()->each(function (AuctionImage $image, int $position) {
            if ($image->position !== $position) {
                $image->position = $position;
                $image->save();
            }
        });
    }
}
