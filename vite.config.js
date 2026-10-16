import { defineConfig } from 'vite';
import {driverPwa} from './resources/js/modules/DriverPortal/pwa-build.mjs';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        driverPwa(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.ts'],
            refresh: true,
            // Hermetic PWA verification uses the existing system-font fallback.
            fonts: process.env.TRANSPORTE_BUILD_OFFLINE === '1' ? [] : [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
        vue(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
