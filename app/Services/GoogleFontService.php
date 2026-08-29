<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Makes any Google font usable by the server-side card renderer.
 *
 * Pango (and fontconfig underneath it) can only draw a family that exists as a
 * font FILE on this machine. The VPS ships a handful of Noto faces and nothing
 * else, so "pick any Google font for this text block" needs the font fetched
 * and registered before the first render that uses it.
 *
 * How it works:
 *
 *   • The CATALOGUE (which families exist, what weights, do they cover
 *     Gujarati) is a static JSON file in the repo — resources/data/
 *     google-fonts.json, refreshable with `php artisan fonts:sync-catalogue`.
 *     A dropdown of 1,900 names must not depend on a live HTTP call.
 *
 *   • INSTALLING a family hits the same css2 endpoint a browser does, with a
 *     plain User-Agent so Google answers with .ttf rather than .woff2 —
 *     fontconfig reads TrueType, not WOFF. The files land under
 *     storage/app/fonts/<Family>/ and a generated fontconfig file points at
 *     that directory, which ShapedText passes to pango via FONTCONFIG_FILE.
 *
 *   • Installs are idempotent and cached: a family already on disk is a
 *     no-op, so the download happens once per family per server, not per card.
 *
 * FAIL-OPEN throughout. A font that cannot be fetched (no network, a family
 * renamed at Google, a locked-down box) leaves the renderer to fall back on
 * fontconfig's own substitution, which produces a readable card in a different
 * face — much better than a failed render or a card full of tofu.
 *
 * A caveat worth knowing when choosing a font: most Google families are
 * Latin-only. A Gujarati or Hindi card set in one of them falls back
 * per-glyph, so the Indic text will NOT be in the chosen face. `families()`
 * flags the ones that actually cover those scripts.
 */
class GoogleFontService
{
    /** Weights fetched for a family — enough for normal and bold text. */
    private const WEIGHTS = [400, 700];

    private const CSS_ENDPOINT = 'https://fonts.googleapis.com/css2';

    /** A UA old enough that Google serves TrueType instead of WOFF2. */
    private const TTF_USER_AGENT = 'Mozilla/5.0 (Windows NT 6.1; WOW64)';

    /** Where downloaded families live, and where the fontconfig file sits. */
    public function fontsRoot(): string
    {
        return storage_path('app/fonts');
    }

    public function directoryFor(string $family): string
    {
        return $this->fontsRoot().'/'.$this->slug($family);
    }

    /**
     * The catalogue: every family, cheapest-first for a dropdown.
     *
     * @return list<array{family: string, category: string, weights: list<int>, italic: bool, indic: bool}>
     */
    public function families(): array
    {
        return Cache::remember('google-fonts.catalogue.v1', 86400, function (): array {
            $path = resource_path('data/google-fonts.json');

            if (! is_file($path)) {
                return [];
            }

            $rows = json_decode((string) file_get_contents($path), true);

            if (! is_array($rows)) {
                return [];
            }

            return collect($rows)
                ->map(fn (array $r): array => [
                    'family' => (string) $r['f'],
                    'category' => (string) ($r['c'] ?? ''),
                    'weights' => array_map('intval', $r['w'] ?? [400]),
                    'italic' => (bool) ($r['i'] ?? false),
                    // Covers Gujarati or Devanagari, i.e. safe for a card in
                    // the temple's own languages.
                    'indic' => ! empty($r['s'] ?? []),
                ])
                ->all();
        });
    }

    /** Options for a Filament/HTML select: family => label. */
    public function selectOptions(): array
    {
        $options = [];

        foreach ($this->families() as $font) {
            $options[$font['family']] = $font['family']
                .($font['indic'] ? ' — covers ગુ/हि' : '');
        }

        return $options;
    }

    /** Is this a family we know about at all? */
    public function isKnown(string $family): bool
    {
        return collect($this->families())->contains(fn (array $f) => $f['family'] === $family);
    }

    /**
     * Ensure a family's font files are on disk and visible to fontconfig.
     *
     * Returns true when the family can be asked for by name after this call.
     * Never throws: see the class doc-comment on failing open.
     */
    public function ensureInstalled(string $family): bool
    {
        $family = trim($family);

        if ($family === '' || ! $this->isKnown($family)) {
            return false;
        }

        $dir = $this->directoryFor($family);

        if (is_dir($dir) && glob($dir.'/*.ttf')) {
            // Already here. Still make sure the config exists — a wiped
            // storage dir or a fresh deploy can leave files without it.
            $this->writeFontConfig();

            return true;
        }

        try {
            $urls = $this->fontFileUrls($family);

            if ($urls === []) {
                return false;
            }

            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                return false;
            }

            $written = 0;
            foreach ($urls as $index => $url) {
                $response = Http::timeout(20)->withHeaders(['User-Agent' => self::TTF_USER_AGENT])->get($url);

                if (! $response->successful()) {
                    continue;
                }

                file_put_contents($dir.'/'.$this->slug($family).'-'.$index.'.ttf', $response->body());
                $written++;
            }

            if ($written === 0) {
                return false;
            }

            $this->writeFontConfig();

            // fontconfig caches per directory; without this the freshly
            // written files are invisible until the cache happens to expire.
            $this->refreshFontCache();

            Log::info('GoogleFont installed', ['family' => $family, 'files' => $written]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('GoogleFont install failed — renderer falls back to a substitute face', [
                'family' => $family,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Path to the generated fontconfig file, or null when nothing has been
     * installed yet. ShapedText passes this to pango as FONTCONFIG_FILE.
     */
    public function fontConfigPath(): ?string
    {
        $path = $this->fontsRoot().'/fonts.conf';

        return is_file($path) ? $path : null;
    }

    /**
     * A fontconfig file that adds our download directory to the system's own
     * config rather than replacing it — the bundled Noto Indic faces must stay
     * available as the fallback for scripts a Latin family cannot draw.
     */
    private function writeFontConfig(): void
    {
        $root = $this->fontsRoot();

        if (! is_dir($root) && ! @mkdir($root, 0775, true) && ! is_dir($root)) {
            return;
        }

        $xml = <<<XML
        <?xml version="1.0"?>
        <!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd">
        <fontconfig>
          <!-- GENERATED by App\\Services\\GoogleFontService. Do not hand-edit. -->
          <include ignore_missing="yes">/etc/fonts/fonts.conf</include>
          <dir>{$root}</dir>
          <cachedir>{$root}/.cache</cachedir>
        </fontconfig>
        XML;

        file_put_contents($root.'/fonts.conf', $xml."\n");
    }

    /**
     * Rebuild fontconfig's cache for our directory. Best-effort: the binary
     * may not exist, and pango still finds the files without it on most
     * builds — it is just slower on the first render.
     */
    private function refreshFontCache(): void
    {
        $root = escapeshellarg($this->fontsRoot());
        @exec("FONTCONFIG_FILE={$root}/fonts.conf fc-cache -f {$root} 2>/dev/null");
    }

    /**
     * Resolve a family to its .ttf URLs via the css2 endpoint.
     *
     * @return list<string>
     */
    private function fontFileUrls(string $family): array
    {
        $font = collect($this->families())->firstWhere('family', $family);

        $weights = array_values(array_intersect(self::WEIGHTS, $font['weights'] ?? []));
        if ($weights === []) {
            $weights = [reset($font['weights']) ?: 400];
        }

        // css2 rejects an axis list that a family does not have, so italics
        // are only requested from families that ship them.
        $spec = ($font['italic'] ?? false)
            ? 'ital,wght@'.collect([0, 1])
                ->crossJoin($weights)
                ->map(fn (array $pair): string => $pair[0].','.$pair[1])
                ->implode(';')
            : 'wght@'.implode(';', $weights);

        $response = Http::timeout(15)
            ->withHeaders(['User-Agent' => self::TTF_USER_AGENT])
            ->get(self::CSS_ENDPOINT, ['family' => $family.':'.$spec]);

        if (! $response->successful()) {
            return [];
        }

        preg_match_all('/url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.ttf)\)/', $response->body(), $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function slug(string $family): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]+/', '-', $family);
    }
}
