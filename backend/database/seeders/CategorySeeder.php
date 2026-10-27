<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Default auction categories. Safe to re-run: existing ones are left as they are.
 */
class CategorySeeder extends Seeder
{
    public const DEFAULTS = [
        'Antiques',
        'Art',
        'Collectibles',
        'Sports memorabilia',
        'Jewellery & watches',
        'Home & furniture',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $name) {
            Category::firstOrCreate(['slug' => Category::slugFor($name)], ['name' => $name]);
        }
    }
}
