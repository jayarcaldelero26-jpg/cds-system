import { constants as fsConstants } from 'node:fs';
import {
    copyFile,
    lstat,
    mkdir,
    readFile,
    readdir,
    rename,
    unlink,
} from 'node:fs/promises';
import path from 'node:path';

const manifestName = 'manifest.json';
const stagingPrefix = '.vite-build-staging-';
const manifestTemporaryPrefix = '.vite-build-manifest-';

const filesystem = { copyFile, lstat, mkdir, readFile, readdir, rename, unlink };

function assertSafeRootLayout({ publicRoot, buildRoot, stagingRoot, token }) {
    if (typeof token !== 'string' || !/^[A-Za-z0-9._-]+$/.test(token)) {
        throw new Error('A safe per-run token is required for Vite build promotion.');
    }

    const publicPath = path.resolve(publicRoot);
    const activePath = path.resolve(buildRoot);
    const stagingPath = path.resolve(stagingRoot);

    if (path.dirname(activePath) !== publicPath || path.basename(activePath) !== 'build') {
        throw new Error(`Refusing to promote into an unexpected active build path: ${buildRoot}`);
    }
    if (path.dirname(stagingPath) !== publicPath
        || path.basename(stagingPath) !== `${stagingPrefix}${token}`) {
        throw new Error(`Refusing to promote from an unexpected staging path: ${stagingRoot}`);
    }

    return { publicPath, activePath, stagingPath };
}

function resolveBuildFile(buildRoot, relativeFile) {
    if (typeof relativeFile !== 'string'
        || relativeFile.length === 0
        || relativeFile.includes('\\')
        || path.posix.isAbsolute(relativeFile)
        || path.win32.isAbsolute(relativeFile)) {
        throw new Error(`Unsafe Vite build file path: ${relativeFile}`);
    }

    const segments = relativeFile.split('/');
    if (segments.some(segment => segment.length === 0 || segment === '.' || segment === '..')) {
        throw new Error(`Unsafe Vite build file path: ${relativeFile}`);
    }

    const resolved = path.resolve(buildRoot, ...segments);
    const relative = path.relative(buildRoot, resolved);
    if (!relative || relative === '..' || relative.startsWith(`..${path.sep}`) || path.isAbsolute(relative)) {
        throw new Error(`Vite build file escapes the active build directory: ${relativeFile}`);
    }

    return resolved;
}

async function optionalStat(target, operations) {
    try {
        return await operations.lstat(target);
    } catch (error) {
        if (error?.code === 'ENOENT') return null;
        throw error;
    }
}

async function assertRealDirectory(target, operations, label) {
    const info = await optionalStat(target, operations);
    if (!info || !info.isDirectory() || info.isSymbolicLink()) {
        throw new Error(`Expected a real ${label} directory at ${target}.`);
    }
}

async function collectStagedFiles(directory, root, operations) {
    const entries = await operations.readdir(directory, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const target = path.join(directory, entry.name);
        if (entry.isSymbolicLink()) {
            throw new Error(`Symbolic links are not allowed in a staged Vite build: ${entry.name}`);
        }
        if (entry.isDirectory()) {
            files.push(...await collectStagedFiles(target, root, operations));
            continue;
        }
        if (!entry.isFile()) {
            throw new Error(`Unsupported entry in staged Vite build: ${entry.name}`);
        }

        const relative = path.relative(root, target).split(path.sep).join('/');
        resolveBuildFile(root, relative);
        files.push(relative);
    }

    return files;
}

async function readExistingBuildFile(buildRoot, relativeFile, operations) {
    const segments = relativeFile.split('/');
    let current = buildRoot;

    for (let index = 0; index < segments.length; index += 1) {
        current = path.join(current, segments[index]);
        const info = await optionalStat(current, operations);
        if (!info) return null;
        if (info.isSymbolicLink()) {
            throw new Error(`Symbolic links are not allowed in the active Vite build: ${relativeFile}`);
        }
        if (index < segments.length - 1) {
            if (!info.isDirectory()) {
                throw new Error(`An active Vite asset parent is not a directory: ${relativeFile}`);
            }
            continue;
        }
        if (!info.isFile()) {
            throw new Error(`An active Vite asset path is not a regular file: ${relativeFile}`);
        }
        return operations.readFile(current);
    }

    return null;
}

async function ensureAssetParentDirectories(buildRoot, relativeFile, operations) {
    const segments = relativeFile.split('/').slice(0, -1);
    let current = buildRoot;

    for (const segment of segments) {
        current = path.join(current, segment);
        let info = await optionalStat(current, operations);
        if (!info) {
            try {
                await operations.mkdir(current);
            } catch (error) {
                if (error?.code !== 'EEXIST') throw error;
            }
            info = await optionalStat(current, operations);
        }
        if (!info || !info.isDirectory() || info.isSymbolicLink()) {
            throw new Error(`Cannot safely create an active Vite asset directory: ${current}`);
        }
    }
}

async function removeVerifiedTemporaryManifest(temporaryManifest, expectedBytes, operations) {
    const info = await optionalStat(temporaryManifest, operations);
    if (!info || !info.isFile() || info.isSymbolicLink()) return;

    try {
        const currentBytes = await operations.readFile(temporaryManifest);
        if (currentBytes.equals(expectedBytes)) await operations.unlink(temporaryManifest);
    } catch (error) {
        if (error?.code !== 'ENOENT') throw error;
    }
}

async function assertManifestHasNotChanged(buildRoot, previousManifestBytes, operations) {
    const currentManifestBytes = await readExistingBuildFile(buildRoot, manifestName, operations);
    if (previousManifestBytes === null) {
        if (currentManifestBytes !== null) {
            throw new Error('The active Vite manifest appeared during promotion; refusing to replace it.');
        }
        return;
    }
    if (currentManifestBytes === null || !currentManifestBytes.equals(previousManifestBytes)) {
        throw new Error('The active Vite manifest changed during promotion; refusing to replace it.');
    }
}

/**
 * Add staged immutable assets to public/build and atomically activate the staged manifest last.
 * The optional operations seam is for deterministic filesystem-failure tests; production callers
 * omit it and use Node's real filesystem operations.
 */
export async function promoteViteBuild({ publicRoot, buildRoot, stagingRoot, token, operations: overrides }) {
    const roots = assertSafeRootLayout({ publicRoot, buildRoot, stagingRoot, token });
    const operations = { ...filesystem, ...overrides };

    await assertRealDirectory(roots.publicPath, operations, 'public');

    const activeBeforeCreation = await optionalStat(roots.activePath, operations);
    if (activeBeforeCreation && (!activeBeforeCreation.isDirectory() || activeBeforeCreation.isSymbolicLink())) {
        throw new Error(`Refusing to mutate an unexpected active build path: ${roots.activePath}`);
    }
    if (!activeBeforeCreation) {
        try {
            await operations.mkdir(roots.activePath);
        } catch (error) {
            if (error?.code !== 'EEXIST') throw error;
        }
    }
    await assertRealDirectory(roots.activePath, operations, 'active build');
    await assertRealDirectory(roots.stagingPath, operations, 'staging build');

    const stagedFiles = (await collectStagedFiles(roots.stagingPath, roots.stagingPath, operations)).sort();
    if (!stagedFiles.includes(manifestName)) {
        throw new Error('The verified Vite staging directory is missing manifest.json.');
    }

    const stagedManifestPath = resolveBuildFile(roots.stagingPath, manifestName);
    const activeManifestPath = resolveBuildFile(roots.activePath, manifestName);
    const temporaryManifestName = `${manifestTemporaryPrefix}${token}.tmp`;
    const temporaryManifestPath = resolveBuildFile(roots.activePath, temporaryManifestName);
    const stagedManifestBytes = await operations.readFile(stagedManifestPath);
    const previousManifestBytes = await readExistingBuildFile(roots.activePath, manifestName, operations);
    const assetFiles = stagedFiles.filter(file => file !== manifestName);
    const stagedAssetBytes = new Map();

    // Check every collision before copying any new asset.
    for (const relativeFile of assetFiles) {
        const stagedPath = resolveBuildFile(roots.stagingPath, relativeFile);
        const bytes = await operations.readFile(stagedPath);
        stagedAssetBytes.set(relativeFile, bytes);
        const activeBytes = await readExistingBuildFile(roots.activePath, relativeFile, operations);
        if (activeBytes !== null && !activeBytes.equals(bytes)) {
            throw new Error(`Cannot safely publish while an active asset differs at ${relativeFile}.`);
        }
    }

    let copiedAssetCount = 0;
    let reusedAssetCount = 0;

    for (const relativeFile of assetFiles) {
        const stagedPath = resolveBuildFile(roots.stagingPath, relativeFile);
        const activePath = resolveBuildFile(roots.activePath, relativeFile);
        const expectedBytes = stagedAssetBytes.get(relativeFile);
        const currentBytes = await readExistingBuildFile(roots.activePath, relativeFile, operations);

        if (currentBytes !== null) {
            if (!currentBytes.equals(expectedBytes)) {
                throw new Error(`An active Vite asset changed after preflight: ${relativeFile}.`);
            }
            reusedAssetCount += 1;
            continue;
        }

        await ensureAssetParentDirectories(roots.activePath, relativeFile, operations);
        try {
            await operations.copyFile(stagedPath, activePath, fsConstants.COPYFILE_EXCL);
            copiedAssetCount += 1;
        } catch (error) {
            if (error?.code !== 'EEXIST') throw error;
            const racedBytes = await readExistingBuildFile(roots.activePath, relativeFile, operations);
            if (racedBytes === null || !racedBytes.equals(expectedBytes)) throw error;
            reusedAssetCount += 1;
        }

        const copiedBytes = await readExistingBuildFile(roots.activePath, relativeFile, operations);
        if (copiedBytes === null || !copiedBytes.equals(expectedBytes)) {
            throw new Error(`A staged asset did not verify after copying: ${relativeFile}.`);
        }
    }

    for (const relativeFile of assetFiles) {
        const stagedPath = resolveBuildFile(roots.stagingPath, relativeFile);
        const stagedBytes = await operations.readFile(stagedPath);
        const activeBytes = await readExistingBuildFile(roots.activePath, relativeFile, operations);
        if (!stagedBytes.equals(stagedAssetBytes.get(relativeFile))
            || activeBytes === null
            || !activeBytes.equals(stagedBytes)) {
            throw new Error(`A staged Vite asset is not safely available before manifest activation: ${relativeFile}.`);
        }
    }

    if (!(await operations.readFile(stagedManifestPath)).equals(stagedManifestBytes)) {
        throw new Error('The verified staging manifest changed during promotion.');
    }
    await assertManifestHasNotChanged(roots.activePath, previousManifestBytes, operations);

    let temporaryManifestOwned = false;
    try {
        await operations.copyFile(stagedManifestPath, temporaryManifestPath, fsConstants.COPYFILE_EXCL);
        temporaryManifestOwned = true;
        const temporaryInfo = await operations.lstat(temporaryManifestPath);
        if (!temporaryInfo.isFile() || temporaryInfo.isSymbolicLink()) {
            throw new Error('The temporary Vite manifest is not a regular file.');
        }
        if (!(await operations.readFile(temporaryManifestPath)).equals(stagedManifestBytes)) {
            throw new Error('The temporary Vite manifest differs from the verified staging manifest.');
        }

        await assertManifestHasNotChanged(roots.activePath, previousManifestBytes, operations);
        await operations.rename(temporaryManifestPath, activeManifestPath);
        temporaryManifestOwned = false;
    } catch (error) {
        if (temporaryManifestOwned) {
            try {
                await removeVerifiedTemporaryManifest(temporaryManifestPath, stagedManifestBytes, operations);
            } catch {
                // Leave a temp file in place if it cannot be proven safe to remove.
            }
        }
        throw error;
    }

    return { assetCount: assetFiles.length, copiedAssetCount, reusedAssetCount };
}
