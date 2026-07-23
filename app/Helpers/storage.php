<?php

use Illuminate\Support\Facades\Storage;

if (! function_exists('image_url')) {
    /**
     * Resolve an uploaded image's public URL on Cloudflare R2.
     *
     * Pass the relative key stored in the model's *_path column
     * (e.g. 'sevas/abc.jpg'). Returns null for null/empty input so
     * views can render it inside conditionals safely.
     *
     * Replaces the legacy `image_url($path)` pattern that pointed
     * at the local /file/* route serving storage/app/public.
     */
    function image_url(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        // If a model accidentally stores an absolute URL (e.g. user pasted
        // an https://… link into a TextInput), pass it through as-is.
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return Storage::disk('r2')->url($path);
    }
}

if (! function_exists('clean_rich_html')) {
    /**
     * Conservative cleanup for RichEditor HTML before it is persisted.
     * Pasted content (Word / web pages) arrives padded with &nbsp; runs and
     * empty paragraphs; this normalises whitespace WITHOUT touching the
     * author's words or markup:
     *   • &nbsp; / U+00A0 → regular space
     *   • runs of spaces/tabs collapsed to one
     *   • spaces trimmed just inside <p>/<h*>/<li> boundaries
     *   • paragraphs left empty by the above are dropped
     * Applied globally on RichEditor save (AppServiceProvider) and by the
     * one-time content cleanup migration.
     */
    function clean_rich_html(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $out = str_replace(["\xC2\xA0", '&nbsp;', '&#160;'], ' ', $html);
        $out = preg_replace('/[ \t]{2,}/u', ' ', $out) ?? $out;
        $out = preg_replace('/(<(?:p|h[1-6]|li)[^>]*>)\s+/iu', '$1', $out) ?? $out;
        $out = preg_replace('/\s+(<\/(?:p|h[1-6]|li)>)/iu', '$1', $out) ?? $out;
        $out = preg_replace('/<p[^>]*>\s*<\/p>/iu', '', $out) ?? $out;

        return trim($out);
    }
}

if (! function_exists('text_preview')) {
    /**
     * Plain-text preview of HTML content for cards/lists. Strips tags AND
     * decodes entities (so `&nbsp;`, `&amp;` don't leak as literal text once
     * Blade re-escapes with {{ }}), then collapses whitespace. Output with
     * {{ }} — the returned string is plain text, escaped once by Blade.
     */
    function text_preview(?string $html, ?int $limit = null): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse runs of whitespace (incl. the non-breaking space U+00A0).
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text)) ?? '');

        return $limit !== null ? \Illuminate\Support\Str::limit($text, $limit) : $text;
    }
}

if (! function_exists('youtube_video_id')) {
    /**
     * Extract the 11-char video id from any YouTube URL form we accept in
     * admin inputs: watch?v=, youtu.be/, embed/, shorts/, live/, /v/ —
     * with or without the nocookie host. Returns null when the URL isn't
     * YouTube so callers can fall back to a native <video> tag.
     *
     * Single source of truth for the parsing that used to be copy-pasted
     * into every view; used by <x-yt-clean> (the chromeless player).
     */
    function youtube_video_id(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (preg_match(
            '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([\w-]{6,})~i',
            $url,
            $m
        )) {
            return $m[1];
        }

        return null;
    }
}

if (! function_exists('private_file_redirect')) {
    /**
     * Redirect to a short-lived presigned URL for a file on the PRIVATE R2
     * bucket, so the bytes stream straight from storage instead of being
     * downloaded into PHP and re-sent (the old, slow `->get()` + `response()`
     * pattern). The caller must have already ensured the object exists.
     *
     * @param  string       $path      Key on the r2_private disk.
     * @param  string|null  $filename  Suggested download name; null keeps stored name.
     * @param  bool         $inline    true = render inline (e.g. an image), false = attachment.
     */
    /**
     * NOTE: no return type — inside a Livewire/Filament action redirect()
     * returns Livewire's Redirector (not RedirectResponse), and a strict
     * type here TypeError'd every admin PDF download button.
     *
     * @return \Illuminate\Http\RedirectResponse|\Livewire\Features\SupportRedirects\Redirector
     */
    function private_file_redirect(string $path, ?string $filename = null, bool $inline = false, ?string $contentType = null)
    {
        $disposition = $inline ? 'inline' : 'attachment';

        $options = [];
        if ($filename !== null) {
            $options['ResponseContentDisposition'] =
                $disposition . '; filename="' . str_replace('"', '', $filename) . '"';
        } elseif ($inline) {
            $options['ResponseContentDisposition'] = 'inline';
        }
        if ($contentType !== null) {
            $options['ResponseContentType'] = $contentType;
        }

        // 10-minute window: long enough for a client (browser, dio, or
        // WhatsApp/Meta fetching a media header) to follow the redirect,
        // short enough that the link isn't a durable share token.
        $url = Storage::disk('r2_private')->temporaryUrl($path, now()->addMinutes(10), $options);

        return redirect()->away($url);
    }
}
