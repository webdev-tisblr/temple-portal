import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // clean-youtube.js is also a standalone entry so the lightweight
            // /pages/{slug}/embed WebView page can load it without all of app.js.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/clean-youtube.js'],
            refresh: true,
        }),
    ],
});
