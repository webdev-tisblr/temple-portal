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
    function private_file_redirect(string $path, ?string $filename = null, bool $inline = false, ?string $contentType = null): \Illuminate\Http\RedirectResponse
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
