import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));
const hotFile = path.join(projectRoot, '..', 'public', 'hot');

if (fs.existsSync(hotFile)) {
    fs.rmSync(hotFile, { force: true });
    console.log('Removed stale public/hot.');
}
