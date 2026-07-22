<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Devotee;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\Event;
use App\Models\GalleryImage;
use App\Models\Hall;
use App\Models\Payment;
use App\Models\SevaBooking;
use App\Models\Seva;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedEvents();
        $this->seedCampaigns();
        $this->seedGallery();
        $this->seedHalls();
        $this->seedTestDevotees();

        $this->command->info('Test data seeded successfully!');
    }

    private function seedEvents(): void
    {
        $events = [
            [
                'title_gu' => 'હનુમાન જયંતિ મહોત્સવ',
                'title_hi' => 'हनुमान जयंती महोत्सव',
                'title_en' => 'Hanuman Jayanti Celebration',
                'description_gu' => 'શ્રી પાતાળિયા હનુમાનજી ધામ ખાતે હનુમાન જયંતિનો ભવ્ય ઉત્સવ. સવારે વિશેષ અભિષેક, મહાપૂજા, સુંદરકાંડ પાઠ, ભંડારા અને સાંજે ભવ્ય આરતી.',
                'description_hi' => 'श्री पातळिया हनुमानजी धाम पर हनुमान जयंती का भव्य उत्सव।',
                'start_date' => now()->addDays(15),
                'end_date' => now()->addDays(15),
                'start_time' => '05:00',
                'end_time' => '21:00',
                'event_type' => 'festival',
                'status' => 'published',
                'is_featured' => true,
            ],
            [
                'title_gu' => 'શ્રી રામ નવમી ઉત્સવ',
                'title_hi' => 'श्री राम नवमी उत्सव',
                'title_en' => 'Shri Ram Navami Festival',
                'description_gu' => 'ભગવાન શ્રી રામના જન્મોત્સવની ધામધૂમથી ઉજવણી. સવારે રામચરિતમાનસ પાઠ, બપોરે ભંડારા અને સાંજે ભવ્ય ઝાંકી.',
                'start_date' => now()->addDays(25),
                'end_date' => now()->addDays(25),
                'start_time' => '06:00',
                'end_time' => '22:00',
                'event_type' => 'festival',
                'status' => 'published',
                'is_featured' => true,
            ],
            [
                'title_gu' => 'સાપ્તાહિક સુંદરકાંડ પાઠ',
                'title_hi' => 'साप्ताहिक सुंदरकांड पाठ',
                'title_en' => 'Weekly Sundarkand Path',
                'description_gu' => 'દર શનિવારે સાંજે ૫:૦૦ વાગ્યે સુંદરકાંડનો સામૂહિક પાઠ. તમામ ભક્તોને ભાગ લેવા વિનંતી.',
                'start_date' => now()->next('Saturday'),
                'start_time' => '17:00',
                'end_time' => '19:30',
                'event_type' => 'satsang',
                'status' => 'published',
                'is_featured' => false,
            ],
            [
                'title_gu' => 'દિવાળી મહોત્સવ',
                'title_hi' => 'दिवाली महोत्सव',
                'title_en' => 'Diwali Festival',
                'description_gu' => 'દિવાળી ના પાવન પ્રસંગે મંદિરમાં ભવ્ય દીપોત્સવ અને લક્ષ્મી પૂજા.',
                'start_date' => now()->addMonths(7),
                'end_date' => now()->addMonths(7)->addDays(4),
                'event_type' => 'festival',
                'status' => 'published',
                'is_featured' => true,
            ],
        ];

        foreach ($events as $e) {
            Event::firstOrCreate(['title_gu' => $e['title_gu']], $e);
        }
        $this->command->info('  ✓ 4 events seeded');
    }

    private function seedCampaigns(): void
    {
        DonationCampaign::firstOrCreate(
            ['title_gu' => 'મંદિર જીર્ણોદ્ધાર અભિયાન'],
            [
                'title_gu' => 'મંદિર જીર્ણોદ્ધાર અભિયાન',
                'title_hi' => 'मंदिर जीर्णोद्धार अभियान',
                'title_en' => 'Temple Renovation Campaign',
                'description_gu' => 'શ્રી પાતાળિયા હનુમાનજી મંદિરના જીર્ણોદ્ધાર માટે ભક્તોના સહયોગની જરૂર છે. ગર્ભગૃહ, સભા મંડપ અને પરિસરના નવીનીકરણ માટે આ અભિયાન ચાલી રહ્યું છે.',
                'description_hi' => 'श्री पातळिया हनुमानजी मंदिर के जीर्णोद्धार के लिए भक्तों के सहयोग की आवश्यकता है।',
                'goal_amount' => 2500000,
                'raised_amount' => 875000,
                'donor_count' => 156,
                'start_date' => now()->subMonths(2),
                'end_date' => now()->addMonths(6),
                'is_active' => true,
            ]
        );

        DonationCampaign::firstOrCreate(
            ['title_gu' => 'ભોજનાલય વિસ્તરણ'],
            [
                'title_gu' => 'ભોજનાલય વિસ્તરણ',
                'title_hi' => 'भोजनालय विस्तारण',
                'title_en' => 'Bhojanalay Expansion',
                'description_gu' => 'દૈનિક ૫૦૦+ ભક્તોને ભોજન પ્રસાદ પીરસવા માટે ભોજનાલયનું વિસ્તરણ કરવામાં આવી રહ્યું છે.',
                'goal_amount' => 1000000,
                'raised_amount' => 320000,
                'donor_count' => 78,
                'start_date' => now()->subMonth(),
                'end_date' => now()->addMonths(4),
                'is_active' => true,
            ]
        );

        $this->command->info('  ✓ 2 campaigns seeded');
    }

    private function seedGallery(): void
    {
        $images = [
            ['title' => 'મંદિર મુખ્ય દ્વાર', 'category' => 'temple', 'image_path' => 'gallery/temple-main.jpg', 'sort_order' => 1],
            ['title' => 'હનુમાનજી મૂર્તિ', 'category' => 'deity', 'image_path' => 'gallery/hanumanji-murti.jpg', 'sort_order' => 2],
            ['title' => 'શ્રૃંગાર દર્શન', 'category' => 'deity', 'image_path' => 'gallery/shringar-darshan.jpg', 'sort_order' => 3],
            ['title' => 'હનુમાન જયંતિ ૨૦૨૫', 'category' => 'festival', 'image_path' => 'gallery/jayanti-2025.jpg', 'sort_order' => 4],
            ['title' => 'દિવાળી ઉત્સવ', 'category' => 'festival', 'image_path' => 'gallery/diwali-utsav.jpg', 'sort_order' => 5],
            ['title' => 'સુંદરકાંડ પાઠ', 'category' => 'event', 'image_path' => 'gallery/sundarkand-path.jpg', 'sort_order' => 6],
            ['title' => 'ભોજનાલય', 'category' => 'temple', 'image_path' => 'gallery/bhojanalay.jpg', 'sort_order' => 7],
            ['title' => 'મંદિર વૉલપેપર ૧', 'category' => 'wallpaper', 'image_path' => 'gallery/wallpaper-1.jpg', 'is_wallpaper' => true, 'sort_order' => 8],
            ['title' => 'મંદિર વૉલપેપર ૨', 'category' => 'wallpaper', 'image_path' => 'gallery/wallpaper-2.jpg', 'is_wallpaper' => true, 'sort_order' => 9],
        ];

        foreach ($images as $img) {
            GalleryImage::firstOrCreate(['title' => $img['title']], $img);
        }
        $this->command->info('  ✓ 9 gallery images seeded (placeholder paths)');
    }

    private function seedHalls(): void
    {
        Hall::firstOrCreate(
            ['name' => 'સભા મંડપ'],
            [
                'name' => 'સભા મંડપ',
                'description' => 'મંદિર પરિસરમાં આવેલો વિશાળ સભા મંડપ. સત્સંગ, ભજન, લગ્ન પ્રસંગ માટે ઉપલબ્ધ.',
                'capacity' => 500,
                'price_per_day' => 15000,
                'price_per_half_day' => 8000,
                'amenities' => ['AC', 'Sound System', 'Projector', 'Stage', 'Parking'],
                'is_active' => true,
            ]
        );

        Hall::firstOrCreate(
            ['name' => 'યાત્રીવાસ હોલ'],
            [
                'name' => 'યાત્રીવાસ હોલ',
                'description' => 'નાના સમારંભ અને મિટિંગ માટે યોગ્ય.',
                'capacity' => 100,
                'price_per_day' => 5000,
                'price_per_half_day' => 3000,
                'amenities' => ['Fan', 'Sound System', 'Chairs'],
                'is_active' => true,
            ]
        );

        $this->command->info('  ✓ 2 halls seeded');
    }

    private function seedTestDevotees(): void
    {
        $fy = now()->month >= 4
            ? now()->year . '-' . substr((string) (now()->year + 1), -2)
            : (now()->year - 1) . '-' . substr((string) now()->year, -2);

        // Test devotee 1 — with donations
        $devotee1 = Devotee::firstOrCreate(
            ['phone' => '9876543210'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'રામ પટેલ',
                'phone' => '9876543210',
                'email' => 'ram@example.com',
                'city' => 'ગાંધીધામ',
                'state' => 'Gujarat',
                'pincode' => '370205',
                'date_of_birth' => now()->subYears(35)->format('Y-m-d'),
                'language' => 'gu',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );

        // Test devotee 2 — birthday today (for testing birthday blessings)
        $devotee2 = Devotee::firstOrCreate(
            ['phone' => '9898989898'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'સીતા શર્મા',
                'phone' => '9898989898',
                'city' => 'અંજાર',
                'state' => 'Gujarat',
                'date_of_birth' => now()->format('Y-m-d'), // today's birthday!
                'language' => 'gu',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );

        // Test devotee 3
        Devotee::firstOrCreate(
            ['phone' => '9111222333'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'કૃષ્ણ જોષી',
                'phone' => '9111222333',
                'city' => 'ભુજ',
                'state' => 'Gujarat',
                'date_of_birth' => '1990-08-15',
                'language' => 'gu',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );

        // Add sample donations for devotee 1
        $sevas = Seva::all();
        $donationTypes = ['general', 'seva', 'annadan', 'festival'];

        for ($i = 0; $i < 8; $i++) {
            $amount = [101, 501, 1100, 2100, 5100, 251, 1001, 11000][$i];
            $type = $donationTypes[$i % 4];

            $paymentId = (string) Str::uuid();
            Payment::firstOrCreate(['id' => $paymentId], [
                'id' => $paymentId,
                'razorpay_order_id' => 'order_test_' . Str::random(10),
                'razorpay_payment_id' => 'pay_test_' . Str::random(10),
                'amount' => $amount,
                'currency' => 'INR',
                'status' => 'captured',
                'method' => ['upi', 'card', 'netbanking', 'wallet'][$i % 4],
                'paid_at' => now()->subDays($i * 3),
            ]);

            Donation::firstOrCreate(
                ['payment_id' => $paymentId],
                [
                    'id' => (string) Str::uuid(),
                    'devotee_id' => $devotee1->id,
                    'payment_id' => $paymentId,
                    'amount' => $amount,
                    'donation_type' => $type,
                    'purpose' => ['મંદિર વિકાસ', 'અન્નદાન', 'શ્રૃંગાર સેવા', 'ઉત્સવ દાન'][$i % 4],
                    'is_80g_eligible' => true,
                    'financial_year' => $fy,
                ]
            );
        }

        // Add sample seva bookings for devotee 1
        if ($sevas->isNotEmpty()) {
            for ($i = 0; $i < 3; $i++) {
                $seva = $sevas[$i % $sevas->count()];
                $paymentId = (string) Str::uuid();

                Payment::firstOrCreate(['id' => $paymentId], [
                    'id' => $paymentId,
                    'razorpay_order_id' => 'order_seva_' . Str::random(10),
                    'amount' => $seva->price,
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => 'upi',
                    'paid_at' => now()->subDays($i * 5),
                ]);

                SevaBooking::firstOrCreate(
                    ['payment_id' => $paymentId],
                    [
                        'id' => (string) Str::uuid(),
                        'devotee_id' => $devotee1->id,
                        'seva_id' => $seva->id,
                        'booking_date' => now()->addDays($i + 5),
                        'quantity' => 1,
                        'total_amount' => $seva->price,
                        'status' => ['confirmed', 'confirmed', 'pending'][$i],
                        'payment_id' => $paymentId,
                        'devotee_name_for_seva' => 'રામ પટેલ',
                        'sankalp' => 'પરિવારના કલ્યાણ માટે',
                    ]
                );
            }
        }

        $this->command->info('  ✓ 3 test devotees seeded');
        $this->command->info('  ✓ 8 sample donations seeded');
        $this->command->info('  ✓ 3 sample seva bookings seeded');
    }
}
