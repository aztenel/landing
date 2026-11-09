import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/storefront.css',
                'resources/css/filament/admin/theme.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    build: {
        // Chrome 109 (Windows 7) is the oldest browser we support.
        target: ['chrome109', 'safari15'],
        cssTarget: ['chrome109', 'safari15'],
    },
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
});
