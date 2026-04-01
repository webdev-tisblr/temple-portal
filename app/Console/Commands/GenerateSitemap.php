<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PageStatus;
use App\Models\BlogPost;
use App\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class GenerateSitemap extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sitemap:generate';

    /**
     * The console command description.
     */
    protected $description = 'Generate the public sitemap.xml for the temple portal';

    public function handle(): int
    {
        $baseUrl = rtrim(config('app.url', 'http://localhost'), '/');

        $sitemap = Sitemap::create();

        // Static core pages
        $staticPages = [
            '/'          => ['priority' => 1.0, 'changefreq' => Url::CHANGE_FREQUENCY_DAILY],
            '/seva'      => ['priority' => 0.9, 'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY],
            '/donate'    => ['priority' => 0.9, 'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY],
            '/darshan'   => ['priority' => 0.8, 'changefreq' => Url::CHANGE_FREQUENCY_DAILY],
            '/events'    => ['priority' => 0.8, 'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY],
            '/gallery'   => ['priority' => 0.7, 'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY],
            '/contact'   => ['priority' => 0.6, 'changefreq' => Url::CHANGE_FREQUENCY_MONTHLY],
            '/blog'      => ['priority' => 0.7, 'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY],
            '/rules'     => ['priority' => 0.5, 'changefreq' => Url::CHANGE_FREQUENCY_MONTHLY],
            '/trustees'  => ['priority' => 0.5, 'changefreq' => Url::CHANGE_FREQUENCY_MONTHLY],
        ];

        foreach ($staticPages as $path => $meta) {
            $sitemap->add(
                Url::create($baseUrl . $path)
                    ->setPriority($meta['priority'])
                    ->setChangeFrequency($meta['changefreq'])
            );
        }

        // Dynamic Pages (published)
        Page::where('status', PageStatus::PUBLISHED)
            ->whereNotNull('slug')
            ->cursor()
            ->each(function (Page $page) use ($sitemap, $baseUrl) {
                $sitemap->add(
                    Url::create($baseUrl . '/' . ltrim($page->slug, '/'))
                        ->setPriority(0.6)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                        ->setLastModificationDate($page->updated_at ?? now())
                );
            });

        // Dynamic Blog Posts (published)
        BlogPost::where('status', PageStatus::PUBLISHED)
            ->whereNotNull('slug')
            ->cursor()
            ->each(function (BlogPost $post) use ($sitemap, $baseUrl) {
                $sitemap->add(
                    Url::create($baseUrl . '/blog/' . ltrim($post->slug, '/'))
                        ->setPriority(0.7)
                        ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                        ->setLastModificationDate($post->updated_at ?? now())
                );
            });

        $outputPath = public_path('sitemap.xml');
        $sitemap->writeToFile($outputPath);

        $this->info("Sitemap generated at: {$outputPath}");
        Log::info('sitemap:generate: sitemap written to public/sitemap.xml');

        return self::SUCCESS;
    }
}
