<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StoreProductSeeder extends Seeder
{
    public function run(): void
    {
        $categories = $this->seedCategories();
        $this->seedVastraProducts($categories['vastra']);
        $this->seedPrasadProducts($categories['prasad']);
        $this->seedBookProducts($categories['books']);
        $this->seedPoojaProducts($categories['pooja']);
    }

    private function seedCategories(): array
    {
        $categoryData = [
            'vastra' => [
                'name_gu' => 'વસ્ત્ર',
                'name_hi' => 'वस्त्र',
                'name_en' => 'Religious Garments',
                'slug' => Str::slug('Religious Garments'),
                'description' => 'શ્રી પાતળિયા હનુમાનજી મંદિરમાં ભગવાનને અર્પણ કરવા માટેના પવિત્ર વસ્ત્રો અને શ્રૃંગાર સામગ્રી.',
                'sort_order' => 1,
                'is_active' => true,
            ],
            'prasad' => [
                'name_gu' => 'પ્રસાદ',
                'name_hi' => 'प्रसाद',
                'name_en' => 'Sacred Sweets',
                'slug' => Str::slug('Sacred Sweets'),
                'description' => 'મંદિરના પવિત્ર પ્રસાદ — હનુમાનજીને ધરાવેલ મીઠાઈઓ અને લાડુ.',
                'sort_order' => 2,
                'is_active' => true,
            ],
            'books' => [
                'name_gu' => 'પુસ્તકો',
                'name_hi' => 'पुस्तकें',
                'name_en' => 'Religious Publications',
                'slug' => Str::slug('Religious Publications'),
                'description' => 'ધાર્મિક પુસ્તકો, હનુમાન ચાલીસા, સુંદરકાંડ અને અન્ય આધ્યાત્મિક સાહિત્ય.',
                'sort_order' => 3,
                'is_active' => true,
            ],
            'pooja' => [
                'name_gu' => 'પૂજા સામગ્રી',
                'name_hi' => 'पूजा सामग्री',
                'name_en' => 'Devotional Accessories',
                'slug' => Str::slug('Devotional Accessories'),
                'description' => 'માળા, ફોટો ફ્રેમ, કીચેન અને અન્ય ભક્તિ સામગ્રી — મંદિરની યાદગીરી માટે.',
                'sort_order' => 4,
                'is_active' => true,
            ],
        ];

        $categories = [];
        foreach ($categoryData as $key => $data) {
            $categories[$key] = ProductCategory::firstOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }

        return $categories;
    }

    private function seedVastraProducts(ProductCategory $category): void
    {
        $products = [
            [
                'name_gu' => 'શ્રી હનુમાનજી વસ્ત્ર - લાલ',
                'name_hi' => 'श्री हनुमानजी वस्त्र - लाल',
                'name_en' => 'Red Hanuman Vastra',
                'description_gu' => 'શ્રી પાતળિયા હનુમાનજી મંદિરમાં ભગવાનને અર્પણ કરવા માટેનો લાલ રંગનો પવિત્ર વસ્ત્ર. શુદ્ધ કોટન કાપડમાંથી બનાવેલ, ઝરી બોર્ડર સાથે.',
                'description_hi' => 'श्री पातलिया हनुमानजी मंदिर में भगवान को अर्पण करने के लिए लाल रंग का पवित्र वस्त्र. शुद्ध कॉटन कपड़े से बना, ज़री बॉर्डर के साथ.',
                'description_en' => 'Sacred red garment for offering to Hanumanji at Shree Pataliya Hanumanji Temple. Made from pure cotton fabric with zari border.',
                'price' => 1100.00,
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'name_gu' => 'શ્રી હનુમાનજી વસ્ત્ર - કેસરી',
                'name_hi' => 'श्री हनुमानजी वस्त्र - केसरी',
                'name_en' => 'Saffron Hanuman Vastra',
                'description_gu' => 'કેસરી રંગનો પવિત્ર વસ્ત્ર — હનુમાનજીનો પ્રિય રંગ. શુદ્ધ કોટન કાપડ, સુંદર ભરતકામ સાથે.',
                'description_hi' => 'केसरी रंग का पवित्र वस्त्र — हनुमानजी का प्रिय रंग. शुद्ध कॉटन कपड़ा, सुंदर कढ़ाई के साथ.',
                'description_en' => 'Sacred saffron garment — Hanumanji\'s favourite colour. Pure cotton fabric with beautiful embroidery.',
                'price' => 1100.00,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'name_gu' => 'શ્રી હનુમાનજી શ્રૃંગાર વસ્ત્ર',
                'name_hi' => 'श्री हनुमानजी श्रृंगार वस्त्र',
                'name_en' => 'Shringar Vastra Set',
                'description_gu' => 'સંપૂર્ણ શ્રૃંગાર વસ્ત્ર સેટ — ઉત્તરીય, ધોતી અને પટકો સહિત. વિશેષ પ્રસંગોએ હનુમાનજીના શ્રૃંગાર માટે ઉપયોગી.',
                'description_hi' => 'संपूर्ण श्रृंगार वस्त्र सेट — उत्तरीय, धोती और पटका सहित. विशेष अवसरों पर हनुमानजी के श्रृंगार के लिए उपयोगी.',
                'description_en' => 'Complete Shringar Vastra set including uttariya, dhoti and patka. Ideal for special occasion adornment of Hanumanji.',
                'price' => 2100.00,
                'is_featured' => false,
                'sort_order' => 3,
            ],
            [
                'name_gu' => 'મુકુટ અને વસ્ત્ર સેટ',
                'name_hi' => 'मुकुट और वस्त्र सेट',
                'name_en' => 'Mukut and Vastra Set',
                'description_gu' => 'સુંદર મુકુટ સાથે મેચિંગ વસ્ત્ર સેટ. ઝરી અને ભરતકામથી સુશોભિત, મંદિરના મહોત્સવો માટે ઉત્તમ.',
                'description_hi' => 'सुंदर मुकुट के साथ मैचिंग वस्त्र सेट. ज़री और कढ़ाई से सुशोभित, मंदिर के महोत्सवों के लिए उत्तम.',
                'description_en' => 'Beautiful crown with matching vastra set. Adorned with zari and embroidery, perfect for temple festivals.',
                'price' => 3500.00,
                'is_featured' => false,
                'sort_order' => 4,
            ],
            [
                'name_gu' => 'ચાંદીનો વસ્ત્ર સેટ',
                'name_hi' => 'चांदी का वस्त्र सेट',
                'name_en' => 'Silver Thread Vastra Set',
                'description_gu' => 'ચાંદીના તારથી વણેલ પ્રિમિયમ વસ્ત્ર સેટ. ભગવાનના વિશેષ શ્રૃંગાર માટે — દિવાળી, જન્મોત્સવ જેવા મહાપર્વો માટે.',
                'description_hi' => 'चांदी के तार से बुना प्रीमियम वस्त्र सेट. भगवान के विशेष श्रृंगार के लिए — दीवाली, जन्मोत्सव जैसे महापर्वों के लिए.',
                'description_en' => 'Premium vastra set woven with silver thread. For special adornment of the deity — ideal for Diwali, Janmotsav and other grand festivals.',
                'price' => 4000.00,
                'is_featured' => false,
                'sort_order' => 5,
            ],
            [
                'name_gu' => 'ધોતી-ઉપરણો સેટ',
                'name_hi' => 'धोती-उपरना सेट',
                'name_en' => 'Dhoti Uparna Set',
                'description_gu' => 'શુદ્ધ સફેદ ધોતી અને ઉપરણો — મંદિરના દૈનિક શ્રૃંગાર માટે. નરમ કોટન કાપડ, કેસરી બોર્ડર સાથે.',
                'description_hi' => 'शुद्ध सफ़ेद धोती और उपरना — मंदिर के दैनिक श्रृंगार के लिए. नरम कॉटन कपड़ा, केसरी बॉर्डर के साथ.',
                'description_en' => 'Pure white dhoti and uparna for daily temple adornment. Soft cotton fabric with saffron border.',
                'price' => 1500.00,
                'is_featured' => false,
                'sort_order' => 6,
            ],
            [
                'name_gu' => 'પટકો અને દુપટ્ટો',
                'name_hi' => 'पटका और दुपट्टा',
                'name_en' => 'Patko and Dupatta',
                'description_gu' => 'ભગવાનના કમર પર બાંધવાનો પટકો અને ખભા પર રાખવાનો દુપટ્ટો. સુંદર રંગ સંયોજન અને ઝરી કામ.',
                'description_hi' => 'भगवान की कमर पर बांधने का पटका और कंधे पर रखने का दुपट्टा. सुंदर रंग संयोजन और ज़री काम.',
                'description_en' => 'Patko for the deity\'s waist and dupatta for the shoulders. Beautiful colour combination with zari work.',
                'price' => 1200.00,
                'is_featured' => false,
                'sort_order' => 7,
            ],
            [
                'name_gu' => 'ફૂલોનો વસ્ત્ર હાર',
                'name_hi' => 'फूलों का वस्त्र हार',
                'name_en' => 'Floral Vastra Garland',
                'description_gu' => 'ફૂલોના આકારમાં કાપડથી બનાવેલ સુંદર હાર. લાંબા સમય સુધી ટકે છે, રોજિંદા શ્રૃંગાર માટે ઉત્તમ.',
                'description_hi' => 'फूलों के आकार में कपड़े से बनी सुंदर माला. लंबे समय तक टिकती है, दैनिक श्रृंगार के लिए उत्तम.',
                'description_en' => 'Beautiful garland made from fabric in floral patterns. Long-lasting, perfect for daily adornment.',
                'price' => 1800.00,
                'is_featured' => false,
                'sort_order' => 8,
            ],
            [
                'name_gu' => 'શિયાળુ ઊનનો વસ્ત્ર',
                'name_hi' => 'शीतकालीन ऊनी वस्त्र',
                'name_en' => 'Winter Woolen Vastra',
                'description_gu' => 'શિયાળાની ઋતુ માટે ઊનનો ગરમ વસ્ત્ર. નરમ ઊન, કેસરી રંગ, ભરતકામ સાથે. ભગવાનને ઠંડીમાં ગરમાવો આપવા.',
                'description_hi' => 'सर्दियों के मौसम के लिए ऊनी गर्म वस्त्र. नरम ऊन, केसरी रंग, कढ़ाई के साथ. भगवान को ठंड में गर्माहट देने के लिए.',
                'description_en' => 'Warm woolen vastra for the winter season. Soft wool, saffron colour, with embroidery. To keep the deity warm during cold months.',
                'price' => 2500.00,
                'is_featured' => false,
                'sort_order' => 9,
            ],
            [
                'name_gu' => 'દિવાળી વિશેષ વસ્ત્ર',
                'name_hi' => 'दीवाली विशेष वस्त्र',
                'name_en' => 'Diwali Special Vastra',
                'description_gu' => 'દિવાળીના પાવન પર્વ માટે વિશેષ વસ્ત્ર. ભારે ઝરી કામ, મોતી અને કુંદનથી સજાવેલ. સીમિત માત્રામાં ઉપલબ્ધ.',
                'description_hi' => 'दीवाली के पावन पर्व के लिए विशेष वस्त्र. भारी ज़री काम, मोती और कुंदन से सजाया हुआ. सीमित मात्रा में उपलब्ध.',
                'description_en' => 'Special vastra for the auspicious festival of Diwali. Heavy zari work, decorated with pearls and kundan. Available in limited quantity.',
                'price' => 3000.00,
                'is_featured' => true,
                'sort_order' => 10,
            ],
        ];

        $this->createProducts($category, $products);
    }

    private function seedPrasadProducts(ProductCategory $category): void
    {
        $products = [
            [
                'name_gu' => 'હનુમાનજી પેંડા - 250 ગ્રામ',
                'name_hi' => 'हनुमानजी पेड़ा - 250 ग्राम',
                'name_en' => 'Peda Box 250gm',
                'description_gu' => 'શ્રી પાતળિયા હનુમાનજી મંદિરના પવિત્ર પેંડા પ્રસાદ. શુદ્ધ ઘી અને ખાંડથી બનાવેલ, 250 ગ્રામ પેકિંગ.',
                'description_hi' => 'श्री पातलिया हनुमानजी मंदिर का पवित्र पेड़ा प्रसाद. शुद्ध घी और चीनी से बना, 250 ग्राम पैकिंग.',
                'description_en' => 'Sacred peda prasad from Shree Pataliya Hanumanji Temple. Made with pure ghee and sugar, 250gm packing.',
                'price' => 199.00,
                'is_featured' => false,
                'sort_order' => 1,
            ],
            [
                'name_gu' => 'હનુમાનજી પેંડા - 500 ગ્રામ',
                'name_hi' => 'हनुमानजी पेड़ा - 500 ग्राम',
                'name_en' => 'Peda Box 500gm',
                'description_gu' => 'શ્રી પાતળિયા હનુમાનજી મંદિરના પવિત્ર પેંડા પ્રસાદ. શુદ્ધ ઘી અને ખાંડથી બનાવેલ, 500 ગ્રામ ફેમિલી પેક.',
                'description_hi' => 'श्री पातलिया हनुमानजी मंदिर का पवित्र पेड़ा प्रसाद. शुद्ध घी और चीनी से बना, 500 ग्राम फैमिली पैक.',
                'description_en' => 'Sacred peda prasad from Shree Pataliya Hanumanji Temple. Made with pure ghee and sugar, 500gm family pack.',
                'price' => 349.00,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'name_gu' => 'હનુમાનજી પેંડા - 1 કિલો',
                'name_hi' => 'हनुमानजी पेड़ा - 1 किलो',
                'name_en' => 'Peda Box 1kg',
                'description_gu' => 'શ્રી પાતળિયા હનુમાનજી મંદિરના પવિત્ર પેંડા પ્રસાદ. શુદ્ધ ઘી અને ખાંડથી બનાવેલ, 1 કિલો મોટું પેકિંગ — કુટુંબ અને સગાંવહાલાં માટે.',
                'description_hi' => 'श्री पातलिया हनुमानजी मंदिर का पवित्र पेड़ा प्रसाद. शुद्ध घी और चीनी से बना, 1 किलो बड़ी पैकिंग — परिवार और रिश्तेदारों के लिए.',
                'description_en' => 'Sacred peda prasad from Shree Pataliya Hanumanji Temple. Made with pure ghee and sugar, 1kg large packing — for family and relatives.',
                'price' => 649.00,
                'is_featured' => false,
                'sort_order' => 3,
            ],
            [
                'name_gu' => 'શ્રી લાડુ પ્રસાદ - 250 ગ્રામ',
                'name_hi' => 'श्री लड्डू प्रसाद - 250 ग्राम',
                'name_en' => 'Ladoo Prasad 250gm',
                'description_gu' => 'હનુમાનજીને પ્રિય બેસન લાડુ પ્રસાદ. શુદ્ધ ઘી, બેસન અને ગોળથી બનાવેલ, 250 ગ્રામ પેકિંગ.',
                'description_hi' => 'हनुमानजी को प्रिय बेसन लड्डू प्रसाद. शुद्ध घी, बेसन और गुड़ से बना, 250 ग्राम पैकिंग.',
                'description_en' => 'Besan ladoo prasad, beloved by Hanumanji. Made with pure ghee, besan and jaggery, 250gm packing.',
                'price' => 249.00,
                'is_featured' => false,
                'sort_order' => 4,
            ],
            [
                'name_gu' => 'શ્રી લાડુ પ્રસાદ - 500 ગ્રામ',
                'name_hi' => 'श्री लड्डू प्रसाद - 500 ग्राम',
                'name_en' => 'Ladoo Prasad 500gm',
                'description_gu' => 'હનુમાનજીને પ્રિય બેસન લાડુ પ્રસાદ. શુદ્ધ ઘી, બેસન અને ગોળથી બનાવેલ, 500 ગ્રામ ફેમિલી પેક.',
                'description_hi' => 'हनुमानजी को प्रिय बेसन लड्डू प्रसाद. शुद्ध घी, बेसन और गुड़ से बना, 500 ग्राम फैमिली पैक.',
                'description_en' => 'Besan ladoo prasad, beloved by Hanumanji. Made with pure ghee, besan and jaggery, 500gm family pack.',
                'price' => 449.00,
                'is_featured' => true,
                'sort_order' => 5,
            ],
            [
                'name_gu' => 'શ્રી લાડુ પ્રસાદ - 1 કિલો',
                'name_hi' => 'श्री लड्डू प्रसाद - 1 किलो',
                'name_en' => 'Ladoo Prasad 1kg',
                'description_gu' => 'હનુમાનજીને પ્રિય બેસન લાડુ પ્રસાદ. શુદ્ધ ઘી, બેસન અને ગોળથી બનાવેલ, 1 કિલો મોટું પેકિંગ.',
                'description_hi' => 'हनुमानजी को प्रिय बेसन लड्डू प्रसाद. शुद्ध घी, बेसन और गुड़ से बना, 1 किलो बड़ी पैकिंग.',
                'description_en' => 'Besan ladoo prasad, beloved by Hanumanji. Made with pure ghee, besan and jaggery, 1kg large packing.',
                'price' => 799.00,
                'is_featured' => false,
                'sort_order' => 6,
            ],
            [
                'name_gu' => 'મિક્સ મીઠાઈ બોક્સ - 1 કિલો',
                'name_hi' => 'मिक्स मिठाई बॉक्स - 1 किलो',
                'name_en' => 'Mixed Sweet Box 1kg',
                'description_gu' => 'પેંડા, લાડુ, બરફી અને મોહનથાળ — ચાર પ્રકારની મીઠાઈનું મિક્સ બોક્સ. મંદિરના પવિત્ર પ્રસાદ, 1 કિલો ગિફ્ટ પેકિંગ.',
                'description_hi' => 'पेड़ा, लड्डू, बर्फी और मोहनथाल — चार प्रकार की मिठाई का मिक्स बॉक्स. मंदिर का पवित्र प्रसाद, 1 किलो गिफ्ट पैकिंग.',
                'description_en' => 'Peda, ladoo, barfi and mohanthal — a mixed box of four types of sweets. Sacred temple prasad, 1kg gift packing.',
                'price' => 899.00,
                'is_featured' => false,
                'sort_order' => 7,
            ],
            [
                'name_gu' => 'મિક્સ મીઠાઈ બોક્સ - 2 કિલો',
                'name_hi' => 'मिक्स मिठाई बॉक्स - 2 किलो',
                'name_en' => 'Mixed Sweet Box 2kg',
                'description_gu' => 'પેંડા, લાડુ, બરફી અને મોહનથાળ — ચાર પ્રકારની મીઠાઈનું મોટું મિક્સ બોક્સ. તહેવારો અને પ્રસંગો માટે, 2 કિલો પ્રિમિયમ ગિફ્ટ પેકિંગ.',
                'description_hi' => 'पेड़ा, लड्डू, बर्फी और मोहनथाल — चार प्रकार की मिठाई का बड़ा मिक्स बॉक्स. त्योहारों और अवसरों के लिए, 2 किलो प्रीमियम गिफ्ट पैकिंग.',
                'description_en' => 'Peda, ladoo, barfi and mohanthal — a large mixed box of four types of sweets. For festivals and occasions, 2kg premium gift packing.',
                'price' => 1699.00,
                'is_featured' => false,
                'sort_order' => 8,
            ],
        ];

        $this->createProducts($category, $products);
    }

    private function seedBookProducts(ProductCategory $category): void
    {
        $products = [
            [
                'name_gu' => 'હનુમાન ચાલીસા — સચિત્ર',
                'name_hi' => 'हनुमान चालीसा — सचित्र',
                'name_en' => 'Hanuman Chalisa Illustrated',
                'description_gu' => 'સુંદર ચિત્રો સાથેનું હનુમાન ચાલીસા પુસ્તક. ગુજરાતી અર્થ અને ટીકા સહિત. શ્રી પાતળિયા હનુમાનજી મંદિર દ્વારા પ્રકાશિત.',
                'description_hi' => 'सुंदर चित्रों के साथ हनुमान चालीसा पुस्तक. गुजराती अर्थ और टीका सहित. श्री पातलिया हनुमानजी मंदिर द्वारा प्रकाशित.',
                'description_en' => 'Hanuman Chalisa book with beautiful illustrations. Including Gujarati meaning and commentary. Published by Shree Pataliya Hanumanji Temple.',
                'price' => 151.00,
                'is_featured' => false,
                'sort_order' => 1,
            ],
            [
                'name_gu' => 'સુંદરકાંડ — ગુજરાતી ટીકા',
                'name_hi' => 'सुंदरकांड — गुजराती टीका',
                'name_en' => 'Sundarkand with Gujarati Commentary',
                'description_gu' => 'તુલસીદાસજી રચિત રામચરિતમાનસનો સુંદરકાંડ — વિસ્તૃત ગુજરાતી ટીકા સાથે. ભક્તોના દૈનિક પાઠ માટે ઉત્તમ.',
                'description_hi' => 'तुलसीदासजी रचित रामचरितमानस का सुंदरकांड — विस्तृत गुजराती टीका के साथ. भक्तों के दैनिक पाठ के लिए उत्तम.',
                'description_en' => 'Sundarkand from Tulsidas\'s Ramcharitmanas — with detailed Gujarati commentary. Ideal for daily recitation by devotees.',
                'price' => 351.00,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'name_gu' => 'શ્રી રામચરિતમાનસ',
                'name_hi' => 'श्री रामचरितमानस',
                'name_en' => 'Shri Ramcharitmanas',
                'description_gu' => 'ગોસ્વામી તુલસીદાસજી રચિત સંપૂર્ણ રામચરિતમાનસ. ગુજરાતી અનુવાદ અને ટીકા સહિત. મોટા અક્ષરોમાં છાપેલ.',
                'description_hi' => 'गोस्वामी तुलसीदासजी रचित संपूर्ण रामचरितमानस. गुजराती अनुवाद और टीका सहित. बड़े अक्षरों में छपा हुआ.',
                'description_en' => 'Complete Ramcharitmanas by Goswami Tulsidas. With Gujarati translation and commentary. Printed in large font.',
                'price' => 551.00,
                'is_featured' => true,
                'sort_order' => 3,
            ],
            [
                'name_gu' => 'બજરંગ બાણ અને આરતી સંગ્રહ',
                'name_hi' => 'बजरंग बाण और आरती संग्रह',
                'name_en' => 'Bajrang Baan and Aarti Collection',
                'description_gu' => 'બજરંગ બાણ, હનુમાન ચાલીસા, આરતી અને સ્તુતિનો સંગ્રહ — એક જ પુસ્તકમાં. પોકેટ સાઈઝ, મુસાફરીમાં લઈ જવા યોગ્ય.',
                'description_hi' => 'बजरंग बाण, हनुमान चालीसा, आरती और स्तुति का संग्रह — एक ही पुस्तक में. पॉकेट साइज़, यात्रा में ले जाने योग्य.',
                'description_en' => 'Collection of Bajrang Baan, Hanuman Chalisa, aarti and stuti — all in one book. Pocket size, convenient for travel.',
                'price' => 199.00,
                'is_featured' => false,
                'sort_order' => 4,
            ],
            [
                'name_gu' => 'હનુમાન કથા — બાળકો માટે',
                'name_hi' => 'हनुमान कथा — बच्चों के लिए',
                'name_en' => 'Hanuman Stories for Children',
                'description_gu' => 'બાળકો માટે સરળ ભાષામાં હનુમાનજીની કથાઓ. રંગબેરંગી ચિત્રો સાથે. બાળકોને સંસ્કાર અને ભક્તિ શીખવતું પુસ્તક.',
                'description_hi' => 'बच्चों के लिए सरल भाषा में हनुमानजी की कथाएं. रंग-बिरंगे चित्रों के साथ. बच्चों को संस्कार और भक्ति सिखाती पुस्तक.',
                'description_en' => 'Stories of Hanumanji in simple language for children. With colourful illustrations. A book that teaches children values and devotion.',
                'price' => 249.00,
                'is_featured' => false,
                'sort_order' => 5,
            ],
        ];

        $this->createProducts($category, $products);
    }

    private function seedPoojaProducts(ProductCategory $category): void
    {
        $products = [
            [
                'name_gu' => 'રુદ્રાક્ષ માળા',
                'name_hi' => 'रुद्राक्ष माला',
                'name_en' => 'Rudraksha Mala',
                'description_gu' => 'અસલ 5-મુખી રુદ્રાક્ષ માળા — 108 દાણા. મંદિરમાં અભિમંત્રિત. જાપ અને ધ્યાન માટે ઉત્તમ.',
                'description_hi' => 'असली 5-मुखी रुद्राक्ष माला — 108 दाने. मंदिर में अभिमंत्रित. जाप और ध्यान के लिए उत्तम.',
                'description_en' => 'Authentic 5-mukhi Rudraksha mala — 108 beads. Energised at the temple. Ideal for chanting and meditation.',
                'price' => 599.00,
                'is_featured' => false,
                'sort_order' => 1,
            ],
            [
                'name_gu' => 'ચંદન માળા',
                'name_hi' => 'चंदन माला',
                'name_en' => 'Sandalwood Mala',
                'description_gu' => 'શુદ્ધ ચંદનની સુગંધિત માળા — 108 દાણા. મંદિરમાં અભિમંત્રિત. ચંદનની કુદરતી સુગંધ, શાંતિ અને ધ્યાન માટે.',
                'description_hi' => 'शुद्ध चंदन की सुगंधित माला — 108 दाने. मंदिर में अभिमंत्रित. चंदन की प्राकृतिक सुगंध, शांति और ध्यान के लिए.',
                'description_en' => 'Pure sandalwood fragrant mala — 108 beads. Energised at the temple. Natural sandalwood fragrance, for peace and meditation.',
                'price' => 899.00,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'name_gu' => 'તુલસી માળા',
                'name_hi' => 'तुलसी माला',
                'name_en' => 'Tulsi Mala',
                'description_gu' => 'પવિત્ર તુલસીની માળા — 108 દાણા. મંદિરમાં અભિમંત્રિત. રામ નામ જાપ અને દૈનિક પૂજા માટે.',
                'description_hi' => 'पवित्र तुलसी की माला — 108 दाने. मंदिर में अभिमंत्रित. राम नाम जाप और दैनिक पूजा के लिए.',
                'description_en' => 'Sacred Tulsi mala — 108 beads. Energised at the temple. For Ram naam chanting and daily worship.',
                'price' => 299.00,
                'is_featured' => false,
                'sort_order' => 3,
            ],
            [
                'name_gu' => 'શ્રી હનુમાનજી ફોટો ફ્રેમ — નાનો',
                'name_hi' => 'श्री हनुमानजी फोटो फ्रेम — छोटा',
                'name_en' => 'Hanumanji Photo Frame Small',
                'description_gu' => 'શ્રી પાતળિયા હનુમાનજીની મૂર્તિનો સુંદર ફોટો ફ્રેમ — 6×8 ઈંચ. ઘર અને ઓફિસમાં મૂકવા માટે, ગોલ્ડ કલરની ફ્રેમ.',
                'description_hi' => 'श्री पातलिया हनुमानजी की मूर्ति का सुंदर फोटो फ्रेम — 6×8 इंच. घर और ऑफिस में रखने के लिए, गोल्ड कलर की फ्रेम.',
                'description_en' => 'Beautiful photo frame of Shree Pataliya Hanumanji\'s murti — 6x8 inches. For home and office display, gold coloured frame.',
                'price' => 399.00,
                'is_featured' => false,
                'sort_order' => 4,
            ],
            [
                'name_gu' => 'શ્રી હનુમાનજી ફોટો ફ્રેમ — મોટો',
                'name_hi' => 'श्री हनुमानजी फोटो फ्रेम — बड़ा',
                'name_en' => 'Hanumanji Photo Frame Large',
                'description_gu' => 'શ્રી પાતળિયા હનુમાનજીની મૂર્તિનો મોટો ફોટો ફ્રેમ — 12×16 ઈંચ. પૂજા ઘર માટે ઉત્તમ, પ્રિમિયમ વૂડન ફ્રેમ.',
                'description_hi' => 'श्री पातलिया हनुमानजी की मूर्ति का बड़ा फोटो फ्रेम — 12×16 इंच. पूजा घर के लिए उत्तम, प्रीमियम वुडन फ्रेम.',
                'description_en' => 'Large photo frame of Shree Pataliya Hanumanji\'s murti — 12x16 inches. Ideal for prayer room, premium wooden frame.',
                'price' => 799.00,
                'is_featured' => false,
                'sort_order' => 5,
            ],
            [
                'name_gu' => 'ગદા કીચેન — પિત્તળ',
                'name_hi' => 'गदा कीचेन — पीतल',
                'name_en' => 'Brass Gada Keychain',
                'description_gu' => 'હનુમાનજીની ગદાના આકારની પિત્તળની કીચેન. મંદિરની યાદગીરી, ભેટ આપવા માટે ઉત્તમ.',
                'description_hi' => 'हनुमानजी की गदा के आकार की पीतल की कीचेन. मंदिर की याद, उपहार देने के लिए उत्तम.',
                'description_en' => 'Brass keychain in the shape of Hanumanji\'s gada. A temple souvenir, perfect as a gift.',
                'price' => 149.00,
                'is_featured' => false,
                'sort_order' => 6,
            ],
            [
                'name_gu' => 'ઓર્ગેનિક જ્યૂટ શોપિંગ બેગ',
                'name_hi' => 'ऑर्गेनिक जूट शॉपिंग बैग',
                'name_en' => 'Organic Jute Shopping Bag',
                'description_gu' => 'મંદિરના લોગો અને હનુમાનજીની છબી સાથેની જ્યૂટ શોપિંગ બેગ. પર્યાવરણ-મૈત્રીપૂર્ણ, રોજિંદા ઉપયોગ માટે મજબૂત.',
                'description_hi' => 'मंदिर के लोगो और हनुमानजी की छवि के साथ जूट शॉपिंग बैग. पर्यावरण-अनुकूल, दैनिक उपयोग के लिए मजबूत.',
                'description_en' => 'Jute shopping bag with temple logo and Hanumanji\'s image. Eco-friendly, sturdy for daily use.',
                'price' => 249.00,
                'is_featured' => false,
                'sort_order' => 7,
            ],
        ];

        $this->createProducts($category, $products);
    }

    private function createProducts(ProductCategory $category, array $products): void
    {
        foreach ($products as $productData) {
            $slug = Str::slug($productData['name_en']);

            Product::firstOrCreate(
                ['slug' => $slug],
                array_merge($productData, [
                    'category_id' => $category->id,
                    'slug' => $slug,
                    'stock_quantity' => rand(10, 50),
                    'is_active' => true,
                    'image_path' => null,
                ])
            );
        }
    }
}
