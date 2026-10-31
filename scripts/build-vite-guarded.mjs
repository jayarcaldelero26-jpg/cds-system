import { randomUUID } from 'node:crypto';
import { constants as fsConstants } from 'node:fs';
import { copyFile, mkdir, readFile, readdir, rename, rm, stat } from 'node:fs/promises';
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

async function stagedFiles(directory, root = directory) {
    const entries = await readdir(directory, { withFileTypes: true });
    const files = [];
    for (const entry of entries) {
        const target = path.join(directory, entry.name);
        if (entry.isDirectory()) files.push(...await stagedFiles(target, root));
        else if (entry.isFile()) files.push(path.relative(root, target));
    }
    return files;
}

async function promoteWithManifestSwitch() {
    await mkdir(buildRoot, { recursive: true });
    const files = await stagedFiles(stagingRoot);
    const assets = files.filter(file => file !== 'manifest.json');

    // Keep the old manifest live until every new asset is safely present.
    for (const file of assets) {
        const stagedPath = path.join(stagingRoot, file);
        const activePath = path.join(buildRoot, file);
        if (await exists(activePath) && !(await readFile(stagedPath)).equals(await readFile(activePath))) {
            throw new Error(`Cannot safely publish while an active asset differs at ${file}.`);
        }
    }

    for (const file of assets) {
        const stagedPath = path.join(stagingRoot, file);
        const activePath = path.join(buildRoot, file);
        if (await exists(activePath)) continue;
        await mkdir(path.dirname(activePath), { recursive: true });
        try {
            await copyFile(stagedPath, activePath, fsConstants.COPYFILE_EXCL);
        } catch (error) {
            if (error?.code !== 'EEXIST' || !(await readFile(stagedPath)).equals(await readFile(activePath))) throw error;
        }
        if (!(await readFile(stagedPath)).equals(await readFile(activePath))) {
            throw new Error(`A staged asset did not verify after copying: ${file}.`);
        }
    }

    const temporaryManifest = path.join(buildRoot, `.vite-build-manifest-${token}.tmp`);
    try {
        await copyFile(path.join(stagingRoot, 'manifest.json'), temporaryManifest);
        if (!(await readFile(path.join(stagingRoot, 'manifest.json'))).equals(await readFile(temporaryManifest))) {
            throw new Error('The temporary Vite manifest differs from the verified staging manifest.');
        }
        await rename(temporaryManifest, path.join(buildRoot, 'manifest.json'));
    } catch (error) {
        await rm(temporaryManifest, { force: true }).catch(() => {});
        throw error;
    }

    console.log(`Promoted ${assets.length} verified assets by atomically switching the manifest; existing assets were retained.`);
}

ensureDirectPublicChild(stagingRoot);
ensureDirectPublicChild(backupRoot);
await mkdir(publicRoot, { recursive: true });

let previousBuildMoved = false;
let promoted = false;

try {
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

    await rm(path.join(publicRoot, 'hot'), { force: true });

    if (await exists(buildRoot)) {
        try {
            await rename(buildRoot, backupRoot);
            previousBuildMoved = true;
        } catch (error) {
            if (process.platform !== 'win32' || !['EPERM', 'EACCES', 'EBUSY'].includes(error?.code)) throw error;
            console.warn(`Windows did not allow renaming the active build directory (${error.code}); switching to a verified asset copy and atomic manifest replacement.`);
            await promoteWithManifestSwitch();
            promoted = true;
        }
    }

    if (!promoted) {
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
    }

    if (previousBuildMoved) {
        try {
            await removeBuildTree(backupRoot);
        } catch (error) {
            console.warn(`New assets are active; prior build backup remains at ${backupName}: ${error.message}`);
        }
    }

    if (promoted && await exists(stagingRoot)) {
        try {
            await removeBuildTree(stagingRoot);
        } catch (error) {
            console.warn(`New assets are active; the temporary staging directory remains at ${stagingName}: ${error.message}`);
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
