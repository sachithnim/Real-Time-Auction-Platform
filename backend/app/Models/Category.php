<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An auction category (e.g. "Antiques"). The slug is derived from the name and
 * used in URLs and the public list filter.
 */
#[Fillable(['name'])]
class Category extends Model
{
    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            $category->slug = self::slugFor($category->name);
        });
    }

    public static function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class);
    }
}
