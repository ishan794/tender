<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Models\NoticeModel;
use App\Transformers\NoticeTransformer;

class MultilingualTenderTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
    }

    public function testMultilingualMetadataPersistence(): void
    {
        $uniqueRef = 'TEST-ML-' . bin2hex(random_bytes(4));
        $data = [
            'kind'            => 'tender',
            'reference'       => $uniqueRef,
            'slug'            => strtolower($uniqueRef),
            'title'           => 'Rehabilitation of Kandy-Jaffna Highway Section 3',
            'title_si'        => 'මහනුවර-යාපනය මහාමාර්ගයේ 3 වන කොටස ප්‍රතිසංස්කරණය කිරීම',
            'title_ta'        => 'கண்டி-யாழ்ப்பாணம் நெடுஞ்சாலை பிரிவு 3 புனரமைப்பு',
            'summary'         => 'Asphalt overlay and bridge repairs along section 3.',
            'summary_si'      => '3 වන කොටස දිගේ ඇස්ෆල්ට් ඇතිරීම සහ පාලම් අලුත්වැඩියා කිරීම.',
            'summary_ta'      => 'பிரிவு 3 உடன் நிலக்கீல் மேலடுக்கு மற்றும் பாலம் பழுதுபார்ப்பு.',
            'description'     => 'Full scope of works including drainage construction.',
            'description_si'  => 'කාණු ඉදිකිරීම ඇතුළු සම්පූර්ණ වැඩ විෂය පථය.',
            'description_ta'  => 'வடிகால் கட்டுமானம் உட்பட முழு அளவிலான பணிகள்.',
            'status'          => 'published',
            'sector'          => 'government',
            'published_at'    => date('Y-m-d H:i:s'),
            'closing_at'      => date('Y-m-d H:i:s', time() + 86400 * 14),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        $this->db->table('notices')->insert($data);
        $id = (int) $this->db->insertID();

        $row = $this->db->table('notices')->where('id', $id)->get()->getFirstRow('array');
        $this->assertNotNull($row);
        $this->assertSame('මහනුවර-යාපනය මහාමාර්ගයේ 3 වන කොටස ප්‍රතිසංස්කරණය කිරීම', $row['title_si']);
        $this->assertSame('கண்டி-யாழ்ப்பாணம் நெடுஞ்சாலை பிரிவு 3 புனரமைப்பு', $row['title_ta']);
        $this->assertSame('3 වන කොටස දිගේ ඇස්ෆල්ට් ඇතිරීම සහ පාලම් අලුත්වැඩියා කිරීම.', $row['summary_si']);
        $this->assertSame('பிரிவு 3 உடன் நிலக்கீல் மேலடுக்கு மற்றும் பாலம் பழுதுபார்ப்பு.', $row['summary_ta']);

        // Clean up
        $this->db->table('notices')->where('id', $id)->delete();
    }

    public function testLocaleTransformationWithSinhalaAndTamil(): void
    {
        $row = [
            'id'                  => 99991,
            'kind'                => 'tender',
            'reference'           => 'TEST-REF-99991',
            'slug'                => 'test-ref-99991',
            'title'               => 'Construction of Water Treatment Plant',
            'title_si'            => 'ජල පිරිපහදු මධ්‍යස්ථානයක් ඉදිකිරීම',
            'title_ta'            => 'நீர் சுத்திகரிப்பு நிலையம் அமைத்தல்',
            'summary'             => 'English summary of the water project.',
            'summary_si'          => 'ජල ව්‍යාපෘතිය පිළිබඳ සිංහල සාරාංශය.',
            'summary_ta'          => 'நீர் திட்டம் பற்றிய தமிழ் சுருக்கம்.',
            'description'         => 'Full technical specification in English.',
            'description_si'      => 'සම්පූර්ණ තාක්ෂණික පිරිවිතර.',
            'description_ta'      => 'முழு தொழில்நுட்ப விவரக்குறிப்பு.',
            'category_name'       => 'Water & Drainage',
            'category_name_si'    => 'ජලය සහ ජලාපවහනය',
            'category_name_ta'    => 'நீர் மற்றும் வடிகால்',
            'district_name'       => 'Kandy',
            'district_name_si'    => 'මහනුවර',
            'district_name_ta'    => 'கண்டி',
            'authority_name'      => 'National Water Supply & Drainage Board',
            'authority_name_si'   => 'ජාතික ජලසම්පාදන හා ජලාපවහන මණ්ඩලය',
            'authority_name_ta'   => 'தேசிய நீர் வழங்கல் மற்றும் வடிகாலமைப்புச் சபை',
            'sector'              => 'government',
            'status'              => 'published',
            'closing_at'          => date('Y-m-d H:i:s', time() + 86400 * 10),
            'opening_at'          => date('Y-m-d H:i:s', time() + 86400 * 10 + 3600),
            'estimated_value'     => 45000000,
            'currency'            => 'LKR',
            'documents_count'     => 2,
            'org_id'              => null,
        ];

        // 1. Transform with Sinhala locale
        $siTransformed = NoticeTransformer::one($row, 'paid', [], 'si');
        $this->assertSame('ජල පිරිපහදු මධ්‍යස්ථානයක් ඉදිකිරීම', $siTransformed['title']);
        $this->assertSame('ජල ව්‍යාපෘතිය පිළිබඳ සිංහල සාරාංශය.', $siTransformed['summary']);
        $this->assertSame('ජලය සහ ජලාපවහනය', $siTransformed['category']);
        $this->assertSame('මහනුවර', $siTransformed['district']);
        $this->assertSame('ජාතික ජලසම්පාදන හා ජලාපවහන මණ්ඩලය', $siTransformed['buyer']);
        $this->assertSame('si', $siTransformed['locale']);
        $this->assertFalse($siTransformed['is_fallback']);

        // 2. Transform with Tamil locale
        $taTransformed = NoticeTransformer::one($row, 'paid', [], 'ta');
        $this->assertSame('நீர் சுத்திகரிப்பு நிலையம் அமைத்தல்', $taTransformed['title']);
        $this->assertSame('நீர் திட்டம் பற்றிய தமிழ் சுருக்கம்.', $taTransformed['summary']);
        $this->assertSame('நீர் மற்றும் வடிகால்', $taTransformed['category']);
        $this->assertSame('கண்டி', $taTransformed['district']);
        $this->assertSame('தேசிய நீர் வழங்கல் மற்றும் வடிகாலமைப்புச் சபை', $taTransformed['buyer']);
        $this->assertSame('ta', $taTransformed['locale']);
        $this->assertFalse($taTransformed['is_fallback']);

        // 3. Transform with default English locale
        $enTransformed = NoticeTransformer::one($row, 'paid', [], 'en');
        $this->assertSame('Construction of Water Treatment Plant', $enTransformed['title']);
        $this->assertSame('Water & Drainage', $enTransformed['category']);
        $this->assertSame('en', $enTransformed['locale']);
    }

    public function testLocaleFallbackToEnglishWhenTranslationMissing(): void
    {
        $row = [
            'id'                  => 99992,
            'kind'                => 'tender',
            'reference'           => 'TEST-REF-99992',
            'slug'                => 'test-ref-99992',
            'title'               => 'Supply of Office Stationery',
            'title_si'            => null, // Missing Sinhala title
            'title_ta'            => null, // Missing Tamil title
            'summary'             => 'English summary only.',
            'summary_si'          => null,
            'summary_ta'          => null,
            'category_name'       => 'Office Equipment',
            'category_name_si'    => null,
            'district_name'       => 'Colombo',
            'district_name_si'    => 'කොළඹ',
            'sector'              => 'government',
            'status'              => 'published',
            'closing_at'          => date('Y-m-d H:i:s', time() + 86400 * 5),
            'currency'            => 'LKR',
        ];

        $siTransformed = NoticeTransformer::one($row, 'paid', [], 'si');
        // Falls back to English title
        $this->assertSame('Supply of Office Stationery', $siTransformed['title']);
        $this->assertTrue($siTransformed['is_fallback']);
        // District has translation so it is localized
        $this->assertSame('කොළඹ', $siTransformed['district']);
    }

    public function testPaywallGatingOnMultilingualDescriptions(): void
    {
        $row = [
            'id'                  => 99993,
            'kind'                => 'tender',
            'reference'           => 'TEST-REF-99993',
            'slug'                => 'test-ref-99993',
            'title'               => 'Solar Panel Installation',
            'title_si'            => 'සූර්ය පැනල සවි කිරීම',
            'summary'             => 'Solar rooftop installation.',
            'summary_si'          => 'වහල මත සූර්ය පැනල සවි කිරීම.',
            'description'         => 'Secret specifications and pricing terms.',
            'description_si'      => 'රහස්‍ය තාක්ෂණික පිරිවිතර සහ මිල කොන්දේසි.',
            'status'              => 'published',
            'closing_at'          => date('Y-m-d H:i:s', time() + 86400 * 5),
            'currency'            => 'LKR',
        ];

        // Guest tier should NOT have access to description or description_si
        $guestTransformed = NoticeTransformer::one($row, 'guest', [], 'si');
        $this->assertArrayNotHasKey('description', $guestTransformed);
        $this->assertArrayNotHasKey('description_si', $guestTransformed);
        $this->assertContains('description', $guestTransformed['locked']);
        $this->assertNull($guestTransformed['translations']['si']['description']);
    }

    public function testSearchQueryMatchesSinhalaAndTamil(): void
    {
        $uniqueRef = 'TEST-SEARCH-ML-' . bin2hex(random_bytes(4));
        $this->db->table('notices')->insert([
            'kind'            => 'tender',
            'reference'       => $uniqueRef,
            'slug'            => strtolower($uniqueRef),
            'title'           => 'Supply of Hospital Beds',
            'title_si'        => 'රෝහල් ඇඳන් සැපයීම',
            'title_ta'        => 'வைத்தியசாலை கட்டில்கள் விநியோகம்',
            'summary'         => 'Medical equipment supply for National Hospital.',
            'summary_si'      => 'ජාතික රෝහල සඳහා වෛද්‍ය උපකරණ සැපයීම.',
            'summary_ta'      => 'தேசிய வைத்தியசாலைக்கான மருத்துவ உபகரணங்கள் விநியோகம்.',
            'status'          => 'published',
            'published_at'    => date('Y-m-d H:i:s'),
            'closing_at'      => date('Y-m-d H:i:s', time() + 86400 * 20),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->insertID();

        $model = model(NoticeModel::class);

        // Search in Sinhala
        $resSi = $model->search(['q' => 'රෝහල් ඇඳන්'], 1, 10);
        $foundSi = array_filter($resSi['rows'], fn($r) => (int)$r['id'] === $id);
        $this->assertNotEmpty($foundSi, 'Search by Sinhala term should find notice');

        // Search in Tamil
        $resTa = $model->search(['q' => 'வைத்தியசாலை'], 1, 10);
        $foundTa = array_filter($resTa['rows'], fn($r) => (int)$r['id'] === $id);
        $this->assertNotEmpty($foundTa, 'Search by Tamil term should find notice');

        // Clean up
        $this->db->table('notices')->where('id', $id)->delete();
    }
}
