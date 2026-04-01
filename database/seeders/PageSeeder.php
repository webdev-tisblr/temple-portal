<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'slug' => 'parichay',
                'title_gu' => 'પરિચય',
                'title_hi' => 'परिचय',
                'title_en' => 'Introduction',
                'body_gu' => '<p>શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ, અંતરજાલ, ગાંધીધામ, કચ્છ ખાતે આવેલું આ પવિત્ર મંદિર ભક્તોના શ્રદ્ધાનું કેન્દ્ર છે. મંદિરમાં હનુમાનજી મહારાજની ભવ્ય મૂર્તિ સ્થાપિત છે. દરરોજ સેંકડો ભક્તો દર્શન માટે આવે છે.</p>',
                'body_hi' => '<p>श्री पातळिया हनुमानजी सेवा ट्रस्ट, अंतरजाल, गांधीधाम, कच्छ में स्थित यह पवित्र मंदिर भक्तों की श्रद्धा का केंद्र है। मंदिर में हनुमानजी महाराज की भव्य मूर्ति स्थापित है।</p>',
                'body_en' => '<p>Shree Pataliya Hanumanji Seva Trust temple, located in Antarjal, Gandhidham, Kutch, is a sacred center of devotion. The temple houses a grand idol of Hanumanji Maharaj and attracts hundreds of devotees daily.</p>',
                'status' => 'published',
                'published_at' => now(),
                'sort_order' => 1,
            ],
            [
                'slug' => 'itihas',
                'title_gu' => 'ઇતિહાસ',
                'title_hi' => 'इतिहास',
                'title_en' => 'History',
                'body_gu' => '<p>શ્રી પાતળિયા હનુમાનજી મંદિરનો ઇતિહાસ ઘણો પ્રાચીન છે. આ મંદિર ભક્તોની આસ્થા અને શ્રદ્ધાનું પ્રતીક છે. વર્ષોથી ટ્રસ્ટી મંડળ મંદિરના વિકાસ અને જીર્ણોદ્ધાર માટે સતત કાર્યરત છે.</p>',
                'body_hi' => '<p>श्री पातळिया हनुमानजी मंदिर का इतिहास बहुत प्राचीन है। यह मंदिर भक्तों की आस्था और श्रद्धा का प्रतीक है।</p>',
                'body_en' => '<p>The history of Shree Pataliya Hanumanji Temple is ancient. The temple stands as a symbol of devotion and faith. The trust committee has been continuously working towards the development and renovation of the temple.</p>',
                'status' => 'published',
                'published_at' => now(),
                'sort_order' => 2,
            ],
            [
                'slug' => 'mahima',
                'title_gu' => 'મહિમા',
                'title_hi' => 'महिमा',
                'title_en' => 'Glory',
                'body_gu' => '<p>શ્રી પાતળિયા હનુમાનજી મંદિરની મહિમા અપરંપાર છે. અનેક ભક્તોએ અહીં દર્શન કરીને ચમત્કારિક અનુભવો કર્યા છે. હનુમાનજી મહારાજ ભક્તોની મનોકામના પૂર્ણ કરે છે.</p>',
                'body_hi' => '<p>श्री पातळिया हनुमानजी मंदिर की महिमा अपरंपार है। अनेक भक्तों ने यहां दर्शन करके चमत्कारिक अनुभव किए हैं।</p>',
                'body_en' => '<p>The glory of Shree Pataliya Hanumanji Temple is immense. Many devotees have experienced miracles after visiting the temple. Hanumanji Maharaj fulfills the wishes of his devotees.</p>',
                'status' => 'published',
                'published_at' => now(),
                'sort_order' => 3,
            ],
        ];

        foreach ($pages as $page) {
            Page::firstOrCreate(
                ['slug' => $page['slug']],
                $page
            );
        }
    }
}
