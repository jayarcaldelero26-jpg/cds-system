import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
            refresh: true,
        }),
        react(),
    ],

    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        cors: true,

        hmr: {
            host: 'edats-cds',
            port: 5173,
        },

        origin: 'http://edats-cds:5173',
    },
});
