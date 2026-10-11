import { randomUUID } from 'node:crypto';
import { mkdir, rename, rm, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { build } from 'vite';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const publicRoot = path.join(projectRoot, 'public');
const buildRoot = path.join(publicRoot, 'build');
const token = `${process.pid}-${randomUUID()}`;
const stagingName = `.vite-build-staging-${token}`;
const backupName = `.vite-build-backup-${token}`;
const stagingRoot = path.join(publicRoot, stagingName);
const backupRoot = path.join(publicRoot, backupName);
const stagingRelative = path.posix.join('public', stagingName);
const verifier = path.join(projectRoot, 'scripts', 'verify-vite-build.mjs');

function ensureDirectPublicChild(target) {
    const resolved = path.resolve(target);
    if (path.dirname(resolved) !== publicRoot || !path.basename(resolved).startsWith('.vite-build-')) {
        throw new Error(`Refusing to mutate an unexpected build path: ${target}`);
    }
}

async function exists(target) {
    try {
        await stat(target);
        return true;
    } catch (error) {
        if (error?.code === 'ENOENT') return false;
        throw error;
    }
}

async function removeBuildTree(target) {
    ensureDirectPublicChild(target);
    await rm(target, { recursive: true, force: true });
}

ensureDirectPublicChild(stagingRoot);
ensureDirectPublicChild(backupRoot);
await mkdir(publicRoot, { recursive: true });

let previousBuildMoved = false;
let promoted = false;

try {
    await build({
        configFile: path.join(projectRoot, 'vite.config.js'),
        root: projectRoot,
        build: { outDir: stagingRoot, emptyOutDir: true },
    });
    const previousBuildDirectory = process.env.CDS_VITE_BUILD_DIR;
    process.env.CDS_VITE_BUILD_DIR = stagingRelative;
    try {
        await import(`${pathToFileURL(verifier).href}?build=${token}`);
    } finally {
        if (previousBuildDirectory === undefined) delete process.env.CDS_VITE_BUILD_DIR;
        else process.env.CDS_VITE_BUILD_DIR = previousBuildDirectory;
    }

    await rm(path.join(publicRoot, 'hot'), { force: true });

    if (await exists(buildRoot)) {
        await rename(buildRoot, backupRoot);
        previousBuildMoved = true;
    }

    try {
        await rename(stagingRoot, buildRoot);
        promoted = true;
    } catch (error) {
        if (previousBuildMoved && !(await exists(buildRoot))) {
            await rename(backupRoot, buildRoot);
            previousBuildMoved = false;
        }
        throw error;
    }

    if (previousBuildMoved) {
        try {
            await removeBuildTree(backupRoot);
        } catch (error) {
            console.warn(`New assets are active; prior build backup remains at ${backupName}: ${error.message}`);
        }
    }

    console.log('Verified assets promoted to public/build.');
} catch (error) {
    if (!promoted && await exists(stagingRoot)) {
        try {
            await removeBuildTree(stagingRoot);
        } catch (cleanupError) {
            console.warn(`Could not remove failed staging build ${stagingName}: ${cleanupError.message}`);
        }
    }
    throw error;
}
