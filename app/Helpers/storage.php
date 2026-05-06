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
