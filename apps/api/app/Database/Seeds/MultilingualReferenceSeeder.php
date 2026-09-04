<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class MultilingualReferenceSeeder extends Seeder
{
    public function run()
    {
        $db = \Config\Database::connect();

        // 1. Districts (25 administrative districts of Sri Lanka)
        $districtTranslations = [
            'Colombo'       => ['si' => 'කොළඹ', 'ta' => 'கொழும்பு'],
            'Gampaha'       => ['si' => 'ගම්පහ', 'ta' => 'கம்பஹா'],
            'Kalutara'      => ['si' => 'කළුතර', 'ta' => 'களுத்துறை'],
            'Kandy'         => ['si' => 'මහනුවර', 'ta' => 'கண்டி'],
            'Matale'        => ['si' => 'මාතලේ', 'ta' => 'மாத்தளை'],
            'Nuwara Eliya'  => ['si' => 'නුවරඑළිය', 'ta' => 'நுவரெலியா'],
            'Galle'         => ['si' => 'ගාල්ල', 'ta' => 'காலி'],
            'Matara'        => ['si' => 'මාතර', 'ta' => 'மாத்தறை'],
            'Hambantota'    => ['si' => 'හම්බන්තොට', 'ta' => 'அம்பாந்தோட்டை'],
            'Jaffna'        => ['si' => 'යාපනය', 'ta' => 'யாழ்ப்பாணம்'],
            'Kilinochchi'   => ['si' => 'කිලිනොච්චිය', 'ta' => 'கிளிநொச்சி'],
            'Mannar'        => ['si' => 'මන්නාරම', 'ta' => 'மன்னார்'],
            'Vavuniya'      => ['si' => 'වවුනියාව', 'ta' => 'வவுனியா'],
            'Mullaitivu'    => ['si' => 'මුලතිව්', 'ta' => 'முல்லைத்தீவு'],
            'Batticaloa'    => ['si' => 'මඩකලපුව', 'ta' => 'மட்டக்களப்பு'],
            'Ampara'        => ['si' => 'අම්පාර', 'ta' => 'அம்பாறை'],
            'Trincomalee'   => ['si' => 'ත්‍රිකුණාමලය', 'ta' => 'திருகோணமலை'],
            'Kurunegala'    => ['si' => 'කුරුණෑගල', 'ta' => 'குருணாகல்'],
            'Puttalam'      => ['si' => 'පුත්තලම', 'ta' => 'புத்தளம்'],
            'Anuradhapura'  => ['si' => 'අනුරාධපුරය', 'ta' => 'அனுராதபுரம்'],
            'Polonnaruwa'   => ['si' => 'පොළොන්නරුව', 'ta' => 'பொலன்னறுவை'],
            'Badulla'       => ['si' => 'බදුල්ල', 'ta' => 'பதுளை'],
            'Monaragala'    => ['si' => 'මොණරාගල', 'ta' => 'மொனராகலை'],
            'Ratnapura'     => ['si' => 'රත්නපුරය', 'ta' => 'இரத்தினபுரி'],
            'Kegalle'       => ['si' => 'කෑගල්ල', 'ta' => 'கேகாலை'],
        ];

        foreach ($districtTranslations as $en => $tr) {
            $db->table('districts')->where('name', $en)->update([
                'name_si' => $tr['si'],
                'name_ta' => $tr['ta'],
            ]);
        }

        // 2. Categories
        $categoryTranslations = [
            'Construction & Civil Works' => ['si' => 'ඉදිකිරීම් සහ සිවිල් වැඩ', 'ta' => 'கட்டுமானம் மற்றும் சிவில் பணிகள்'],
            'Buildings'                  => ['si' => 'ගොඩනැගිලි', 'ta' => 'கட்டடங்கள்'],
            'Roads & Bridges'            => ['si' => 'මාර්ග සහ පාලම්', 'ta' => 'வீதிகள் மற்றும் பாலங்கள்'],
            'Water & Drainage'           => ['si' => 'ජලය සහ ජලාපවහනය', 'ta' => 'நீர் மற்றும் வடிகால்'],
            'Electrical Installation'    => ['si' => 'විදුලි ස්ථාපනයන්', 'ta' => 'மின் நிறுவல்கள்'],
            'Goods & Supplies'           => ['si' => 'භාණ්ඩ සහ සැපයුම්', 'ta' => 'பொருட்கள் மற்றும் விநியோகங்கள்'],
            'Medical Supplies'           => ['si' => 'වෛද්‍ය සැපයුම්', 'ta' => 'மருத்துவ விநியோகங்கள்'],
            'Office Equipment'           => ['si' => 'කාර්යාල උපකරණ', 'ta' => 'அலுவலக உபகரணங்கள்'],
            'Furniture'                  => ['si' => 'ගෘහ භාණ්ඩ', 'ta' => 'தளபாடங்கள்'],
            'Food & Rations'             => ['si' => 'ආහාර සහ සලාක', 'ta' => 'உணவு மற்றும் பொருட்கள்'],
            'Services'                   => ['si' => 'සේවාවන්', 'ta' => 'சேவைகள்'],
            'Consultancy'                => ['si' => 'උපදේශන සේවා', 'ta' => 'ஆலோசனை சேவைகள்'],
            'Security Services'          => ['si' => 'ආරක්ෂක සේවා', 'ta' => 'பாதுகாப்பு சேவைகள்'],
            'Cleaning & Janitorial'      => ['si' => 'පිරිසිදු කිරීම් සහ සනීපාරක්ෂක', 'ta' => 'சுத்தம் மற்றும் பராமரிப்பு'],
            'Transport & Logistics'      => ['si' => 'ප්‍රවාහන සහ සැපයුම්', 'ta' => 'போக்குவரத்து மற்றும் தளவாடங்கள்'],
            'ICT'                        => ['si' => 'තොරතුරු සහ සන්නිවේදන තාක්ෂණය', 'ta' => 'தகவல் தொடர்பாடல் தொழில்நுட்பம்'],
            'Software & Systems'         => ['si' => 'මෘදුකාංග සහ පද්ධති', 'ta' => 'மென்பொருள் மற்றும் அமைப்புகள்'],
            'Hardware'                   => ['si' => 'දෘඩාංග', 'ta' => 'வன்பொருள்'],
            'Networking'                 => ['si' => 'ජාලකරණය', 'ta' => 'வலையமைப்பு'],
            'Vehicles & Machinery'       => ['si' => 'වාහන සහ යන්ත්‍රෝපකරණ', 'ta' => 'வாகனங்கள் மற்றும் இயந்திரங்கள்'],
            'Vehicles'                   => ['si' => 'වාහන', 'ta' => 'வாகனங்கள்'],
            'Heavy Machinery'            => ['si' => 'බර යන්ත්‍රෝපකරණ', 'ta' => 'கனரக இயந்திரங்கள்'],
            'Spare Parts'                => ['si' => 'අමතර කොටස්', 'ta' => 'உதிரி பாகங்கள்'],
            'Land & Property'            => ['si' => 'ඉඩම් සහ දේපළ', 'ta' => 'காணி மற்றும் சொத்து'],
            'Land Sale'                  => ['si' => 'ඉඩම් විකිණීම', 'ta' => 'காணி விற்பனை'],
            'Building Sale'              => ['si' => 'ගොඩනැගිලි විකිණීම', 'ta' => 'கட்டட விற்பனை'],
            'Lease'                      => ['si' => 'බදු දීම', 'ta' => 'குத்தகை'],
        ];

        foreach ($categoryTranslations as $en => $tr) {
            $db->table('categories')->where('name', $en)->update([
                'name_si' => $tr['si'],
                'name_ta' => $tr['ta'],
            ]);
        }

        // 3. Authorities
        $authorityTranslations = [
            'Road Development Authority'            => ['si' => 'මාර්ග සංවර්ධන අධිකාරිය', 'ta' => 'வீதி அபிவிருத்தி அதிகாரசபை'],
            'Ceylon Electricity Board'              => ['si' => 'ලංකා විදුලිබල මණ්ඩලය', 'ta' => 'இலங்கை மின்சார சபை'],
            'National Water Supply & Drainage Board' => ['si' => 'ජාතික ජලසම්පාදන හා ජලාපවහන මණ්ඩලය', 'ta' => 'தேசிய நீர் வழங்கல் மற்றும் வடிகாலமைப்புச் சபை'],
            'Ministry of Health'                    => ['si' => 'සෞඛ්‍ය අමාත්‍යාංශය', 'ta' => 'சுகாதார அமைச்சு'],
            'Sri Lanka Ports Authority'             => ['si' => 'ශ්‍රී ලංකා වරාය අධිකාරිය', 'ta' => 'இலங்கை துறைமுக அதிகாரசபை'],
            'Sri Lanka Railways'                    => ['si' => 'ශ්‍රී ලංකා දුම්රිය සේවය', 'ta' => 'இலங்கை புகையிரத சேவை'],
            'University of Colombo'                 => ['si' => 'කොළඹ විශ්වවිද්‍යාලය', 'ta' => 'கொழும்பு பல்கலைக்கழகம்'],
            'Urban Development Authority'           => ['si' => 'නාගරික සංවර්ධන අධිකාරිය', 'ta' => 'நகர்ப்புற அபிவிருத்தி அதிகாரசபை'],
            'Colombo Municipal Council'             => ['si' => 'කොළඹ මහ නගර සභාව', 'ta' => 'கொழும்பு மாநகர சபை'],
            'Bank of Ceylon'                        => ['si' => 'ලංකා බැංකුව', 'ta' => 'இலங்கை வங்கி'],
            'People\'s Bank'                        => ['si' => 'මහජන බැංකුව', 'ta' => 'மக்கள் வங்கி'],
            'Hatton National Bank PLC'              => ['si' => 'හැටන් නැෂනල් බැංකුව', 'ta' => 'ஹற்றன் நஷனல் வங்கி'],
            'Commercial Bank of Ceylon PLC'         => ['si' => 'කොමර්ෂල් බැංකුව', 'ta' => 'கொமர்ஷல் வங்கி'],
            'Sampath Bank PLC'                      => ['si' => 'සම්පත් බැංකුව', 'ta' => 'சம்பத் வங்கி'],
            'Sri Lanka Telecom PLC'                 => ['si' => 'ශ්‍රී ලංකා ටෙලිකොම්', 'ta' => 'ஸ்ரீலங்கா டெலிகொம்'],
        ];

        foreach ($authorityTranslations as $en => $tr) {
            $db->table('authorities')->where('name', $en)->update([
                'name_si' => $tr['si'],
                'name_ta' => $tr['ta'],
            ]);
        }
    }
}
