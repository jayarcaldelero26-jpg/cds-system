import { randomUUID } from 'node:crypto';
import { lstat, mkdir, rm, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { build } from 'vite';
import { promoteViteBuild } from './vite-build-promotion.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const publicRoot = path.join(projectRoot, 'public');
const buildRoot = path.join(publicRoot, 'build');
const token = `${process.pid}-${randomUUID()}`;
const stagingName = `.vite-build-staging-${token}`;
const stagingRoot = path.join(publicRoot, stagingName);
const stagingRelative = path.posix.join('public', stagingName);
const verifier = path.join(projectRoot, 'scripts', 'verify-vite-build.mjs');

function ensureOwnStagingPath(target) {
    const resolved = path.resolve(target);
    if (path.dirname(resolved) !== publicRoot || path.basename(resolved) !== stagingName) {
        throw new Error(`Refusing to clean an unexpected Vite staging path: ${target}`);
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

async function removeOwnStagingTree() {
    ensureOwnStagingPath(stagingRoot);
    let stagingInfo;
    try {
        stagingInfo = await lstat(stagingRoot);
    } catch (error) {
        if (error?.code === 'ENOENT') return;
        throw error;
    }
    if (!stagingInfo.isDirectory() || stagingInfo.isSymbolicLink()) {
        throw new Error(`Refusing to clean a non-directory Vite staging path: ${stagingRoot}`);
    }
    await rm(stagingRoot, { recursive: true, force: true });
}

ensureOwnStagingPath(stagingRoot);
await mkdir(publicRoot, { recursive: true });

let promoted = false;

try {
    if (await exists(path.join(publicRoot, 'hot'))) {
        throw new Error('Refusing to remove public/hot; stop the Vite dev server before running the guarded production build.');
    }

    await build({
        configLoader: 'native',
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

    const promotion = await promoteViteBuild({ publicRoot, buildRoot, stagingRoot, token });
    promoted = true;

    try {
        await removeOwnStagingTree();
    } catch (error) {
        console.warn(`New assets are active; this run's staging directory remains at ${stagingName}: ${error.message}`);
    }

    console.log(`Promoted ${promotion.assetCount} verified assets by atomically switching the manifest; ${promotion.reusedAssetCount} existing assets were retained.`);
} catch (error) {
    if (!promoted && await exists(stagingRoot)) {
        try {
            await removeOwnStagingTree();
        } catch (cleanupError) {
            console.warn(`Could not remove failed staging build ${stagingName}: ${cleanupError.message}`);
        }
    }
    throw error;
}
