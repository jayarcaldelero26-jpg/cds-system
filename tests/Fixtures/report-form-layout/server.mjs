import path from 'node:path';
import { readFileSync } from 'node:fs';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const manifest = JSON.parse(readFileSync(path.resolve('public/build/manifest.json'), 'utf8'));
const appCss = manifest['resources/js/app.jsx']?.css?.[0];

if (!appCss) throw new Error('The built application stylesheet is unavailable for the form layout fixture.');

const fixtureCss = {
    name: 'report-form-layout-fixture-css',
    configureServer(server) {
        server.middlewares.use('/report-form-layout-fixture.css', (_request, response) => {
            const css = readFileSync(path.resolve('public/build', appCss), 'utf8')
                .replace(/^@import\s+["'][^"']+["'];\s*/, '');
            response.statusCode = 200;
            response.setHeader('Content-Type', 'text/css; charset=utf-8');
            response.setHeader('Cache-Control', 'no-store');
            response.end(css);
        });
    },
};

const server = await createServer({
    configFile: false,
    root: process.cwd(),
    plugins: [react(), fixtureCss],
    resolve: {
        alias: [{ find: '@', replacement: path.resolve('resources/js') }],
    },
    server: { host: '127.0.0.1', port: 4182, strictPort: true, hmr: false },
});

await server.listen();
console.log('report form layout fixture listening on 127.0.0.1:4182');
