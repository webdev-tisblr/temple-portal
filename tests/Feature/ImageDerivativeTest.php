<?php

namespace Tests\Feature;

use App\Jobs\GenerateImageDerivatives;
use App\Models\DailyDarshanPhoto;
use App\Models\GalleryImage;
use App\Services\ImageDerivativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Section 7.1 / Suspect 1 — the app OOM-kills because the API hands it
 * full-resolution originals (seven of them ~200 MP) for grid tiles.
 *
 * Covers: rendition generation + dimensions, the deterministic key scheme,
 * idempotency of both the model call and the backfill command, cascade
 * delete of renditions, and the API response contract.
 */
class ImageDerivativeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');

        // The saved() hook queues generation. Faking the queue keeps every
        // test below explicit about when work happens (and stops the
        // sync-queue test connection from silently doing the backfill's
        // job for it). test_saving_a_record_queues_generation covers the
        // hook itself.
        Queue::fake();
    }

    /** Write a real (non-degenerate) JPEG of the given size onto the fake R2 disk. */
    private function putJpeg(string $key, int $width, int $height): int
    {
        $im = imagecreatetruecolor($width, $height);

        // Solid colour compresses to almost nothing and makes byte counts
        // meaningless; paint bands so the encoder has real work to do.
        for ($i = 0; $i < 24; $i++) {
            imagefilledrectangle(
                $im,
                (int) ($width * $i / 24), 0,
                (int) ($width * ($i + 1) / 24), $height,
                imagecolorallocate($im, ($i * 11) % 256, ($i * 37) % 256, ($i * 71) % 256),
            );
        }

        ob_start();
        imagejpeg($im, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        Storage::disk('r2')->put($key, $bytes);

        return strlen($bytes);
    }

    /** @return array{0:int,1:int,2:int} width, height, bytes */
    private function measure(string $key): array
    {
        $bytes = (string) Storage::disk('r2')->get($key);
        $info = getimagesizefromstring($bytes);

        return [(int) $info[0], (int) $info[1], strlen($bytes)];
    }

    public function test_service_generates_both_renditions_at_the_expected_sizes(): void
    {
        $this->putJpeg('gallery/source.jpg', 3000, 2000);

        $keys = app(ImageDerivativeService::class)->generate('gallery/source.jpg');

        $this->assertSame('gallery/derivatives/source_medium.jpg', $keys['medium']);
        $this->assertSame('gallery/derivatives/source_thumbnail.jpg', $keys['thumbnail']);

        // The original is NEVER touched — public-bucket uploads are permanent.
        Storage::disk('r2')->assertExists('gallery/source.jpg');
        $this->assertSame([3000, 2000], array_slice($this->measure('gallery/source.jpg'), 0, 2));

        [$mw, $mh] = $this->measure($keys['medium']);
        $this->assertSame([ImageDerivativeService::MEDIUM_EDGE, 667], [$mw, $mh]);

        [$tw, $th] = $this->measure($keys['thumbnail']);
        $this->assertSame([ImageDerivativeService::THUMBNAIL_EDGE, 267], [$tw, $th]);
    }

    /**
     * Only ever fails on a host WITH imagick (the VPS, the CI runner —
     * not a Mac without the extension): the shrink-on-load `jpeg:size`
     * hint is a request, and libjpeg-turbo will scale UP to 2x to satisfy
     * it, so a 300x200 source asked for a 1000 px box decoded at 600x400.
     */
    public function test_renditions_never_upscale_a_small_source(): void
    {
        $this->putJpeg('gallery/small.jpg', 300, 200);

        $keys = app(ImageDerivativeService::class)->generate('gallery/small.jpg');

        $this->assertSame([300, 200], array_slice($this->measure($keys['medium']), 0, 2));
        $this->assertSame([300, 200], array_slice($this->measure($keys['thumbnail']), 0, 2));
    }

    public function test_model_populates_derivative_columns_and_is_idempotent(): void
    {
        $this->putJpeg('gallery/photo.jpg', 2000, 1500);

        $image = GalleryImage::create([
            'type' => 'photo',
            'title' => 'Test',
            'image_path' => 'gallery/photo.jpg',
            'category' => 'temple',
        ]);

        $this->assertTrue($image->generateImageDerivatives());
        $image->refresh();

        $this->assertSame('gallery/derivatives/photo_thumbnail.jpg', $image->thumbnail_path);
        $this->assertSame('gallery/derivatives/photo_medium.jpg', $image->medium_path);
        Storage::disk('r2')->assertExists($image->thumbnail_path);
        Storage::disk('r2')->assertExists($image->medium_path);

        // Second call finds nothing missing and does no work at all.
        $this->assertFalse($image->generateImageDerivatives());
        $this->assertTrue($image->hasAllImageDerivatives());
    }

    public function test_saving_a_record_queues_generation_only_when_the_source_changes(): void
    {
        $this->putJpeg('gallery/hook.jpg', 800, 600);

        $image = GalleryImage::create([
            'type' => 'photo',
            'image_path' => 'gallery/hook.jpg',
            'category' => 'temple',
        ]);

        Queue::assertPushed(GenerateImageDerivatives::class, 1);

        // A metadata-only edit must NOT re-queue an expensive decode.
        $image->update(['title' => 'renamed']);
        Queue::assertPushed(GenerateImageDerivatives::class, 1);

        // …and writing the rendition columns back (what the job itself
        // does) must not re-arm the hook into a dispatch loop.
        $image->generateImageDerivatives();
        Queue::assertPushed(GenerateImageDerivatives::class, 1);
    }

    public function test_deleting_a_record_cascades_to_its_renditions(): void
    {
        $this->putJpeg('gallery/gone.jpg', 1200, 900);

        $image = GalleryImage::create([
            'type' => 'photo',
            'image_path' => 'gallery/gone.jpg',
            'category' => 'temple',
        ]);
        $image->generateImageDerivatives();
        $image->refresh();

        $thumb = $image->thumbnail_path;
        $medium = $image->medium_path;

        $image->delete();

        Storage::disk('r2')->assertMissing('gallery/gone.jpg');
        Storage::disk('r2')->assertMissing($thumb);
        Storage::disk('r2')->assertMissing($medium);
    }

    public function test_backfill_command_is_idempotent_and_covers_darshan_photos(): void
    {
        $this->putJpeg('gallery/a.jpg', 1600, 1200);
        $this->putJpeg('daily-darshan/b.jpg', 1600, 1200);

        $gallery = GalleryImage::create([
            'type' => 'photo',
            'image_path' => 'gallery/a.jpg',
            'category' => 'temple',
        ]);
        $darshan = DailyDarshanPhoto::create([
            'image_path' => 'daily-darshan/b.jpg',
            'captured_on' => now()->subYear(),
            'is_active' => true,
        ]);

        $this->artisan('images:backfill-derivatives')
            ->expectsOutputToContain('generated=2 failed=0')
            ->assertExitCode(0);

        $gallery->refresh();
        $darshan->refresh();
        $this->assertSame('gallery/derivatives/a_medium.jpg', $gallery->medium_path);
        $this->assertSame('daily-darshan/derivatives/b_medium.jpg', $darshan->medium_path);

        // Re-running must not re-download or re-encode anything.
        $this->artisan('images:backfill-derivatives')
            ->expectsOutputToContain('generated=0 failed=0 scanned=0')
            ->assertExitCode(0);
    }

    public function test_backfill_reports_a_missing_original_without_aborting(): void
    {
        $this->putJpeg('gallery/ok.jpg', 800, 600);

        GalleryImage::create(['type' => 'photo', 'image_path' => 'gallery/missing.jpg', 'category' => 'temple']);
        GalleryImage::create(['type' => 'photo', 'image_path' => 'gallery/ok.jpg', 'category' => 'temple']);

        $this->artisan('images:backfill-derivatives')
            ->expectsOutputToContain('generated=1 failed=1')
            ->assertExitCode(1);
    }

    public function test_gallery_api_keeps_every_existing_key_and_now_serves_renditions(): void
    {
        $this->putJpeg('gallery/api.jpg', 2000, 1500);

        $image = GalleryImage::create([
            'type' => 'photo',
            'title' => 'API',
            'image_path' => 'gallery/api.jpg',
            'category' => 'temple',
        ]);
        $image->generateImageDerivatives();

        $row = $this->getJson('/api/v1/gallery')->assertOk()->json('data.0');

        // Exactly the key set the shipped 1.4.8 client parses — nothing
        // removed, nothing renamed.
        $this->assertSame([
            'id', 'type', 'title', 'description', 'image_url', 'thumbnail_url',
            'medium_url', 'video_url', 'category', 'categories', 'is_wallpaper',
        ], array_keys($row));

        $this->assertStringContainsString('gallery/api.jpg', (string) $row['image_url']);
        $this->assertStringContainsString('api_thumbnail.jpg', (string) $row['thumbnail_url']);
        $this->assertStringContainsString('api_medium.jpg', (string) $row['medium_url']);
    }

    public function test_gallery_api_is_unpaginated_by_default_and_paginates_on_request(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            GalleryImage::create([
                'type' => 'photo',
                'image_path' => "gallery/p{$i}.jpg",
                'category' => 'temple',
                'sort_order' => $i,
            ]);
        }

        // Shipped client sends no pagination params: flat list, no meta key.
        $legacy = $this->getJson('/api/v1/gallery')->assertOk();
        $this->assertCount(5, $legacy->json('data'));
        $this->assertSame(['success', 'message', 'data'], array_keys($legacy->json()));

        $paged = $this->getJson('/api/v1/gallery?per_page=2&page=2')->assertOk();
        $this->assertCount(2, $paged->json('data'));
        $this->assertSame(
            ['total' => 5, 'per_page' => 2, 'current_page' => 2, 'last_page' => 3, 'has_more' => true],
            $paged->json('meta'),
        );

        // `data` stays a flat list in the paginated shape too.
        $this->assertIsList($paged->json('data'));
    }
}
