<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DarshanTiming;
use Illuminate\Database\Seeder;

class DarshanTimingSeeder extends Seeder
{
    public function run(): void
    {
        DarshanTiming::firstOrCreate(
            ['day_type' => 'regular'],
            [
                'label' => 'Regular Day',
                'morning_open' => '05:30',
                'morning_close' => '11:30',
                'evening_open' => '16:00',
                'evening_close' => '20:30',
                'aarti_morning' => '06:00',
                'aarti_evening' => '19:00',
                'is_active' => true,
            ]
        );

        DarshanTiming::firstOrCreate(
            ['day_type' => 'sunday'],
            [
                'label' => 'Sunday',
                'morning_open' => '05:00',
                'morning_close' => '12:00',
                'evening_open' => '16:00',
                'evening_close' => '21:00',
                'aarti_morning' => '05:30',
                'aarti_evening' => '19:30',
                'is_active' => true,
            ]
        );
    }
}
