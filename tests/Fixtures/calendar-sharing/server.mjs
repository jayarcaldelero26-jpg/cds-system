import path from 'node:path';
import { readFileSync } from 'node:fs';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const fixtureRoot = path.resolve('tests/Fixtures/calendar-sharing');
const manifest = JSON.parse(readFileSync(path.resolve('public/build/manifest.json'), 'utf8'));
const appCss = manifest['resources/js/app.jsx']?.css?.[0];

if (!appCss) throw new Error('The built application stylesheet is unavailable for the fixture.');

const localFixtureCss = {
    name: 'calendar-sharing-fixture-css',
    configureServer(viteServer) {
        viteServer.middlewares.use('/calendar-sharing-fixture.css', (_request, response) => {
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
    plugins: [react(), localFixtureCss],
    resolve: {
        alias: [
            { find: '@inertiajs/react', replacement: path.join(fixtureRoot, 'inertiaMock.jsx') },
            { find: '@iconify/react', replacement: path.join(fixtureRoot, 'iconStub.jsx') },
            { find: '@/Layouts/AuthenticatedLayout', replacement: path.join(fixtureRoot, 'layoutStub.jsx') },
            { find: '@', replacement: path.resolve('resources/js') },
        ],
    },
    server: { host: '127.0.0.1', port: 4181, strictPort: true, hmr: false },
});

await server.listen();
console.log('isolated Calendar sharing fixture listening on 127.0.0.1:4181');
