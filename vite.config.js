import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            // Self-hosted at build time (no CDN → CSP stays clean).
            // Fraunces = editorial display serif (headings/numbers),
            // Hanken Grotesk = body/UI; Schibsted Grotesk remains as the
            // fallback face during the visual-refresh rollout.
            fonts: [
                bunny('Fraunces', {
                    weights: [500, 600, 700],
                }),
                bunny('Schibsted Grotesk', {
                    weights: [400, 500, 600, 700, 800],
                }),
                bunny('Hanken Grotesk', {
                    weights: [400, 500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
