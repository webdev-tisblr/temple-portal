<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SevaCategory;
use Illuminate\Database\Seeder;

/**
 * Idempotent: seeds the six category slugs that existed while the list
 * was a hardcoded ENUM (App\Enums\SevaCategory), so pre-existing sevas
 * keep their category tabs. Names are the exact values from the old
 * resources/lang/{gu,hi,en}/seva.php cat_* keys. Admin-added categories
 * are never touched, and admin edits to these six are preserved on re-run.
 */
class SevaCategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['slug' => 'shringar', 'name_gu' => 'શૃંગાર સેવા',  'name_hi' => 'शृंगार सेवा',  'name_en' => 'Shringar Seva', 'sort_order' => 10],
            ['slug' => 'vastra',   'name_gu' => 'વસ્ત્ર સેવા',   'name_hi' => 'वस्त्र सेवा',   'name_en' => 'Vastra Seva',   'sort_order' => 20],
            ['slug' => 'annadan',  'name_gu' => 'અન્નદાન સેવા', 'name_hi' => 'अन्नदान सेवा', 'name_en' => 'Annadan Seva',  'sort_order' => 30],
            ['slug' => 'puja',     'name_gu' => 'પૂજા સેવા',    'name_hi' => 'पूजा सेवा',    'name_en' => 'Puja Seva',     'sort_order' => 40],
            ['slug' => 'special',  'name_gu' => 'વિશેષ સેવાઓ',  'name_hi' => 'विशेष सेवाएँ',  'name_en' => 'Special Sevas', 'sort_order' => 50],
            ['slug' => 'other',    'name_gu' => 'અન્ય સેવાઓ',   'name_hi' => 'अन्य सेवाएँ',   'name_en' => 'Other Sevas',   'sort_order' => 60],
        ];

        foreach ($defaults as $row) {
            SevaCategory::firstOrCreate(['slug' => $row['slug']], $row);
        }
    }
}
