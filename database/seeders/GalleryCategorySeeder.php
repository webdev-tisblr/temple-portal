<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GalleryCategory;
use Illuminate\Database\Seeder;

/**
 * Idempotent: seeds the six category slugs that existed while the list
 * was hardcoded in GalleryResource, so pre-existing gallery images keep
 * their category tabs. Admin-added categories are never touched, and
 * admin edits to these six (names/sort) are preserved on re-run.
 */
class GalleryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['slug' => 'temple',    'name_gu' => 'મંદિર',    'name_hi' => 'मंदिर',    'name_en' => 'Temple',    'sort_order' => 10],
            ['slug' => 'deity',     'name_gu' => 'દેવ',      'name_hi' => 'देव',      'name_en' => 'Deity',     'sort_order' => 20],
            ['slug' => 'festival',  'name_gu' => 'ઉત્સવ',    'name_hi' => 'उत्सव',    'name_en' => 'Festival',  'sort_order' => 30],
            ['slug' => 'event',     'name_gu' => 'કાર્યક્રમ', 'name_hi' => 'कार्यक्रम', 'name_en' => 'Event',     'sort_order' => 40],
            ['slug' => 'wallpaper', 'name_gu' => 'વૉલપેપર',  'name_hi' => 'वॉलपेपर',  'name_en' => 'Wallpaper', 'sort_order' => 50],
            ['slug' => 'other',     'name_gu' => 'અન્ય',     'name_hi' => 'अन्य',     'name_en' => 'Other',     'sort_order' => 60],
        ];

        foreach ($defaults as $row) {
            GalleryCategory::firstOrCreate(['slug' => $row['slug']], $row);
        }
    }
}
