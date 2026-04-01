<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Seva;
use Illuminate\Database\Seeder;

class SampleSevaSeeder extends Seeder
{
    public function run(): void
    {
        $sevas = [
            [
                'name_gu' => 'શ્રૃંગાર સેવા',
                'name_hi' => 'श्रृंगार सेवा',
                'name_en' => 'Shringar Seva',
                'description_gu' => 'હનુમાનજી મહારાજની શ્રૃંગાર સેવા. ભગવાનને ફૂલ, માળા અને શ્રૃંગાર સામગ્રીથી સજાવવામાં આવે છે.',
                'description_hi' => 'हनुमानजी महाराज की श्रृंगार सेवा. भगवान को फूल, माला और श्रृंगार सामग्री से सजाया जाता है.',
                'description_en' => 'Shringar Seva of Hanumanji Maharaj. The deity is adorned with flowers, garlands and decorative items.',
                'category' => 'shringar',
                'price' => 1100.00,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name_gu' => 'વસ્ત્ર સેવા',
                'name_hi' => 'वस्त्र सेवा',
                'name_en' => 'Vastra Seva',
                'description_gu' => 'હનુમાનજી મહારાજને નવા વસ્ત્રો અર્પણ કરવાની સેવા.',
                'description_hi' => 'हनुमानजी महाराज को नए वस्त्र अर्पण करने की सेवा.',
                'description_en' => 'Offering new garments to Hanumanji Maharaj.',
                'category' => 'vastra',
                'price' => 501.00,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name_gu' => 'અન્નદાન સેવા',
                'name_hi' => 'अन्नदान सेवा',
                'name_en' => 'Annadan Seva',
                'description_gu' => 'ભોજનાલયમાં અન્નદાન સેવા. ભક્તોને પ્રસાદ ભોજન પીરસવામાં આવે છે.',
                'description_hi' => 'भोजनालय में अन्नदान सेवा. भक्तों को प्रसाद भोजन परोसा जाता है.',
                'description_en' => 'Annadan Seva at the temple kitchen. Prasad meals are served to devotees.',
                'category' => 'annadan',
                'price' => 2100.00,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name_gu' => 'મહાભિષેક પૂજા',
                'name_hi' => 'महाभिषेक पूजा',
                'name_en' => 'Mahabhishek Puja',
                'description_gu' => 'હનુમાનજી મહારાજનો મહાભિષેક. દૂધ, દહીં, ઘી, મધ અને ગંગાજળથી અભિષેક કરવામાં આવે છે.',
                'description_hi' => 'हनुमानजी महाराज का महाभिषेक. दूध, दही, घी, शहद और गंगाजल से अभिषेक किया जाता है.',
                'description_en' => 'Mahabhishek of Hanumanji Maharaj. Abhishek performed with milk, curd, ghee, honey and Gangajal.',
                'category' => 'puja',
                'price' => 5100.00,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name_gu' => 'સુંદરકાંડ પાઠ',
                'name_hi' => 'सुंदरकांड पाठ',
                'name_en' => 'Sundarkand Path',
                'description_gu' => 'સુંદરકાંડનો સામૂહિક પાઠ. તુલસીદાસ રચિત રામચરિતમાનસના સુંદરકાંડનો પાઠ.',
                'description_hi' => 'सुंदरकांड का सामूहिक पाठ. तुलसीदास रचित रामचरितमानस के सुंदरकांड का पाठ.',
                'description_en' => 'Collective recitation of Sundarkand from Tulsidas\' Ramcharitmanas.',
                'category' => 'special',
                'price' => 2501.00,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'name_gu' => 'સામાન્ય દાન',
                'name_hi' => 'सामान्य दान',
                'name_en' => 'General Donation',
                'description_gu' => 'મંદિરના વિકાસ અને સેવા કાર્યો માટે સામાન્ય દાન.',
                'description_hi' => 'मंदिर के विकास और सेवा कार्यों के लिए सामान्य दान.',
                'description_en' => 'General donation for temple development and service activities.',
                'category' => 'other',
                'price' => 100.00,
                'min_price' => 100.00,
                'is_variable_price' => true,
                'requires_booking' => false,
                'is_active' => true,
                'sort_order' => 6,
            ],
        ];

        foreach ($sevas as $seva) {
            Seva::firstOrCreate(
                ['name_gu' => $seva['name_gu']],
                $seva
            );
        }
    }
}
