import path from 'node:path';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const server = await createServer({
    configFile: false,
    root: process.cwd(),
    plugins: [react()],
    resolve: { alias: { '@': path.resolve('resources/js') } },
    server: { host: '127.0.0.1', port: 4179, strictPort: true },
});

await server.listen();
console.log('isolated Vite QA server listening on 127.0.0.1:4179');
