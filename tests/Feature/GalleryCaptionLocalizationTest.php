<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\GalleryResource;
use App\Models\AdminUser;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Gallery photo captions are multilingual end to end.
 *
 * Covers the three things that can silently break:
 *  - the API answering in the caller's X-Locale language,
 *  - a photo translated only into Gujarati falling back to Gujarati rather
 *    than rendering blank in hi/en,
 *  - the legacy `title` / `description` columns staying populated, since the
 *    shipped app build reads them.
 */
class GalleryCaptionLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GalleryCategory::create([
            'slug' => 'temple',
            'name_gu' => 'મંદિર',
            'name_hi' => 'मंदिर',
            'name_en' => 'Temple',
            'sort_order' => 1,
        ]);
    }

    private function photo(array $attributes = []): GalleryImage
    {
        $image = GalleryImage::create(array_merge([
            'type' => 'photo',
            'image_path' => 'gallery/test.jpg',
            'category' => 'temple',
            'sort_order' => 1,
        ], $attributes));

        $image->syncCategories(['temple']);

        return $image->fresh();
    }

    public function test_api_returns_the_caption_in_the_requested_locale(): void
    {
        $this->photo([
            'title_gu' => 'મંદિર મુખ્ય દ્વાર',
            'title_hi' => 'मंदिर मुख्य द्वार',
            'title_en' => 'Temple Main Gate',
            'description_gu' => 'મંદિરના મુખ્ય પ્રવેશદ્વારનું દ્રશ્ય',
            'description_hi' => 'मंदिर के मुख्य प्रवेशद्वार का दृश्य',
            'description_en' => 'A view of the temple main entrance',
        ]);

        $expected = [
            'gu' => ['મંદિર મુખ્ય દ્વાર', 'મંદિરના મુખ્ય પ્રવેશદ્વારનું દ્રશ્ય'],
            'hi' => ['मंदिर मुख्य द्वार', 'मंदिर के मुख्य प्रवेशद्वार का दृश्य'],
            'en' => ['Temple Main Gate', 'A view of the temple main entrance'],
        ];

        foreach ($expected as $locale => [$title, $description]) {
            $response = $this->withHeader('X-Locale', $locale)->getJson('/api/v1/gallery');

            $response->assertOk();

            $this->assertSame($title, $response->json('data.0.title'), "title for {$locale}");
            $this->assertSame($description, $response->json('data.0.description'), "description for {$locale}");
        }
    }

    /**
     * The cache key must carry the locale. Requesting English FIRST and then
     * Gujarati is the exact order that used to leak the first caller's
     * language to everybody else (LocalizedCache exists for this).
     */
    public function test_a_cached_english_response_does_not_leak_into_the_gujarati_one(): void
    {
        $this->photo([
            'title_gu' => 'હનુમાનજી મૂર્તિ',
            'title_en' => 'Hanumanji Murti',
        ]);

        $this->withHeader('X-Locale', 'en')->getJson('/api/v1/gallery')->assertOk();

        $second = $this->withHeader('X-Locale', 'gu')->getJson('/api/v1/gallery');

        $this->assertSame('હનુમાનજી મૂર્તિ', $second->json('data.0.title'));
    }

    public function test_a_gujarati_only_caption_falls_back_instead_of_rendering_blank(): void
    {
        $this->photo([
            'title_gu' => 'સુંદરકાંડ પાઠ',
            'description_gu' => 'દર શનિવારે સુંદરકાંડનો પાઠ',
        ]);

        foreach (['gu', 'hi', 'en'] as $locale) {
            $response = $this->withHeader('X-Locale', $locale)->getJson('/api/v1/gallery');

            $this->assertSame('સુંદરકાંડ પાઠ', $response->json('data.0.title'), "title for {$locale}");
            $this->assertSame('દર શનિવારે સુંદરકાંડનો પાઠ', $response->json('data.0.description'), "description for {$locale}");
        }
    }

    /**
     * A row written before the multilingual columns existed: only the legacy
     * scalar is set. It must still show a caption, in every language.
     */
    public function test_a_pre_migration_row_still_shows_its_legacy_caption(): void
    {
        DB::table('temple_gallery_images')->insert([
            'type' => 'photo',
            'title' => 'ભોજનાલય',
            'description' => 'મંદિરનું ભોજનાલય',
            'image_path' => 'gallery/legacy.jpg',
            'category' => 'temple',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $image = GalleryImage::firstOrFail();

        foreach (['gu', 'hi', 'en'] as $locale) {
            app()->setLocale($locale);

            $this->assertSame('ભોજનાલય', $image->title, "title for {$locale}");
            $this->assertSame('મંદિરનું ભોજનાલય', $image->description, "description for {$locale}");
        }
    }

    /**
     * Backward compatibility with the shipped app build, which reads the
     * scalar columns: saving a Gujarati caption mirrors into them.
     */
    public function test_saving_mirrors_the_gujarati_caption_into_the_legacy_columns(): void
    {
        $image = $this->photo([
            'title_gu' => 'દિવાળી ઉત્સવ',
            'title_en' => 'Diwali Festival',
            'description_gu' => 'મંદિરમાં દિવાળીની ઉજવણી',
        ]);

        $row = DB::table('temple_gallery_images')->where('id', $image->id)->first();

        $this->assertSame('દિવાળી ઉત્સવ', $row->title);
        $this->assertSame('મંદિરમાં દિવાળીની ઉજવણી', $row->description);
    }

    /**
     * An unrelated update (the wallpaper bulk action) must not blank the
     * legacy caption of a row that has no Gujarati translation yet.
     */
    public function test_an_unrelated_update_leaves_a_legacy_caption_alone(): void
    {
        DB::table('temple_gallery_images')->insert([
            'type' => 'photo',
            'title' => 'મંદિર વૉલપેપર ૧',
            'image_path' => 'gallery/legacy.jpg',
            'category' => 'temple',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        GalleryImage::firstOrFail()->update(['is_wallpaper' => true]);

        $this->assertSame(
            'મંદિર વૉલપેપર ૧',
            DB::table('temple_gallery_images')->value('title'),
        );
    }

    public function test_the_web_lightbox_data_is_localized(): void
    {
        $this->photo([
            'title_gu' => 'મંદિર મુખ્ય દ્વાર',
            'title_hi' => 'मंदिर मुख्य द्वार',
            'title_en' => 'Temple Main Gate',
            'description_gu' => 'મુખ્ય પ્રવેશદ્વાર',
            'description_hi' => 'मुख्य प्रवेशद्वार',
            'description_en' => 'The main entrance',
        ]);

        $expected = [
            'gu' => ['મંદિર મુખ્ય દ્વાર', 'મુખ્ય પ્રવેશદ્વાર'],
            'hi' => ['मंदिर मुख्य द्वार', 'मुख्य प्रवेशद्वार'],
            'en' => ['Temple Main Gate', 'The main entrance'],
        ];

        foreach ($expected as $locale => [$title, $description]) {
            $payload = $this->lightboxPayload($this->get("/gallery?lang={$locale}")->assertOk()->getContent());

            $this->assertSame($title, $payload[0]['title'], "title for {$locale}");
            $this->assertSame($description, $payload[0]['description'], "description for {$locale}");
        }
    }

    /**
     * Smoke test for the admin form: the TranslatableTabs schema has to
     * actually mount, and it has to carry all six caption fields. A typo in
     * a field name here would silently save nothing.
     */
    public function test_the_admin_form_exposes_all_six_caption_fields(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = AdminUser::create([
            'name' => 'Caption Admin',
            'email' => 'caption-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin->fresh(), 'admin');

        $component = Livewire::test(GalleryResource\Pages\CreateGalleryImage::class);

        foreach (['gu', 'hi', 'en'] as $locale) {
            $component->assertFormFieldExists("title_{$locale}");
            $component->assertFormFieldExists("description_{$locale}");
        }
    }

    /**
     * The blade hands the whole gallery to Alpine as `images: @js(...)`, i.e.
     * a JSON.parse() of a double-encoded string. Decoding it beats asserting
     * on escaped glyph sequences, which would break the moment Laravel
     * changed a json_encode flag.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lightboxPayload(string $html): array
    {
        $this->assertSame(1, preg_match("/images: JSON\.parse\('(.*?)'\),/s", $html, $matches), 'gallery payload not found');

        return json_decode(json_decode('"'.$matches[1].'"', true), true, flags: JSON_THROW_ON_ERROR);
    }
}
