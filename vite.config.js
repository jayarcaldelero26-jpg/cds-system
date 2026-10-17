import { readFileSync } from 'node:fs';
import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { pdfjsWasmAssets } from './scripts/pdfjs-wasm-assets.mjs';

function pdfjsWasmAssetPipeline() {
    let projectRoot;

    return {
        name: 'pdfjs-wasm-asset-pipeline',
        configResolved(config) {
            projectRoot = config.root;
        },
        configureServer(server) {
            server.middlewares.use('/build/pdfjs/wasm', (request, response, next) => {
                const filename = new URL(request.url || '/', 'http://vite.local').pathname.replace(/^\/+/, '');
                if (!pdfjsWasmAssets.includes(filename)) {
                    next();
                    return;
                }

                try {
                    const contents = readFileSync(path.join(projectRoot, 'node_modules/pdfjs-dist/wasm', filename));
                    response.statusCode = 200;
                    response.setHeader('Content-Type', filename.endsWith('.wasm') ? 'application/wasm' : 'text/javascript; charset=utf-8');
                    response.setHeader('Content-Length', contents.length);
                    response.setHeader('X-Content-Type-Options', 'nosniff');
                    response.end(request.method === 'HEAD' ? undefined : contents);
                } catch (error) {
                    next(error);
                }
            });
        },
        buildStart() {
            for (const filename of pdfjsWasmAssets) {
                this.emitFile({
                    type: 'asset',
                    fileName: `pdfjs/wasm/${filename}`,
                    source: readFileSync(path.join(projectRoot, 'node_modules/pdfjs-dist/wasm', filename)),
                });
            }
        },
    };
}

export default defineConfig({
    plugins: [
        pdfjsWasmAssetPipeline(),
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
            host: 'cds-smart',
            port: 5173,
        },

        origin: 'http://cds-smart:5173',
    },
});
