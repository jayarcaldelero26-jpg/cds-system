import assert from 'node:assert/strict';
import { constants as fsConstants } from 'node:fs';
import {
    copyFile,
    mkdtemp,
    mkdir,
    readFile,
    readdir,
    rename,
    rm,
    writeFile,
} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { promoteViteBuild } from '../../scripts/vite-build-promotion.mjs';

async function withFixture(run) {
    const root = await mkdtemp(path.join(os.tmpdir(), 'cds-vite-promotion-'));
    const publicRoot = path.join(root, 'public');
    const buildRoot = path.join(publicRoot, 'build');

    await mkdir(publicRoot, { recursive: true });

    try {
        await run({ root, publicRoot, buildRoot });
    } finally {
        await rm(root, { recursive: true, force: true });
    }
}

async function writeTree(root, files) {
    for (const [relativeFile, contents] of Object.entries(files)) {
        const target = path.join(root, relativeFile);
        await mkdir(path.dirname(target), { recursive: true });
        await writeFile(target, contents);
    }
}

async function createStaging(publicRoot, token, files) {
    const stagingRoot = path.join(publicRoot, `.vite-build-staging-${token}`);
    await mkdir(stagingRoot, { recursive: true });
    await writeTree(stagingRoot, files);
    return stagingRoot;
}

function manifest(entries) {
    return Buffer.from(JSON.stringify(entries, null, 2));
}

async function publish({ publicRoot, buildRoot, token, files, operations }) {
    const stagingRoot = await createStaging(publicRoot, token, files);
    const result = await promoteViteBuild({ publicRoot, buildRoot, stagingRoot, token, operations });
    return { stagingRoot, result };
}

test('first publish creates an absent build directory with every staged asset before the manifest', async () => {
    await withFixture(async ({ publicRoot, buildRoot }) => {
        const nextManifest = manifest({
            'resources/js/app.jsx': { file: 'assets/app-new.js', css: ['assets/app-new.css'] },
            'pdfjs/wasm/decoder': { file: 'pdfjs/wasm/decoder.wasm' },
        });
        const { result } = await publish({
            publicRoot,
            buildRoot,
            token: 'first-publish',
            files: {
                'manifest.json': nextManifest,
                'assets/app-new.js': 'new application JavaScript',
                'assets/app-new.css': 'new stylesheet',
                'pdfjs/wasm/decoder.wasm': Buffer.from([0, 1, 2, 3, 255]),
            },
        });

        assert.deepEqual(result, { assetCount: 3, copiedAssetCount: 3, reusedAssetCount: 0 });
        assert.deepEqual(await readFile(path.join(buildRoot, 'manifest.json')), nextManifest);
        assert.equal(await readFile(path.join(buildRoot, 'assets/app-new.js'), 'utf8'), 'new application JavaScript');
        assert.equal(await readFile(path.join(buildRoot, 'assets/app-new.css'), 'utf8'), 'new stylesheet');
        assert.deepEqual(await readFile(path.join(buildRoot, 'pdfjs/wasm/decoder.wasm')), Buffer.from([0, 1, 2, 3, 255]));
    });
});

test('new generations retain old lazy chunks, CSS, decoder bytes, and identical shared assets', async () => {
    await withFixture(async ({ publicRoot, buildRoot }) => {
        const oldManifest = manifest({ 'resources/js/app.jsx': { file: 'assets/app-old.js' } });
        const oldFiles = {
            'manifest.json': oldManifest,
            'assets/old-only-lazy.js': 'old lazy module bytes',
            'assets/old-generation.css': 'old stylesheet bytes',
            'pdfjs/wasm/old-decoder.wasm': Buffer.from([9, 8, 7, 6]),
            'assets/shared.js': 'identical shared bytes',
        };
        await mkdir(buildRoot, { recursive: true });
        await writeTree(buildRoot, oldFiles);

        const newManifest = manifest({
            'resources/js/app.jsx': { file: 'assets/generation-two/app.js', css: ['assets/styles/new.css'] },
            'resources/js/lazy.jsx': { file: 'assets/chunks/new-lazy.js' },
            'pdfjs/wasm/new-decoder': { file: 'pdfjs/wasm/new-decoder.wasm' },
        });
        const { result } = await publish({
            publicRoot,
            buildRoot,
            token: 'generation-two',
            files: {
                'manifest.json': newManifest,
                'assets/generation-two/app.js': 'generation two application',
                'assets/chunks/new-lazy.js': 'generation two lazy module',
                'assets/styles/new.css': 'generation two stylesheet',
                'pdfjs/wasm/new-decoder.wasm': Buffer.from([1, 3, 5, 7]),
                'assets/shared.js': 'identical shared bytes',
            },
        });

        assert.deepEqual(result, { assetCount: 5, copiedAssetCount: 4, reusedAssetCount: 1 });
        assert.deepEqual(await readFile(path.join(buildRoot, 'manifest.json')), newManifest);
        assert.equal(await readFile(path.join(buildRoot, 'assets/old-only-lazy.js'), 'utf8'), oldFiles['assets/old-only-lazy.js']);
        assert.equal(await readFile(path.join(buildRoot, 'assets/old-generation.css'), 'utf8'), oldFiles['assets/old-generation.css']);
        assert.deepEqual(await readFile(path.join(buildRoot, 'pdfjs/wasm/old-decoder.wasm')), oldFiles['pdfjs/wasm/old-decoder.wasm']);
        assert.equal(await readFile(path.join(buildRoot, 'assets/shared.js'), 'utf8'), 'identical shared bytes');
        assert.equal(await readFile(path.join(buildRoot, 'assets/generation-two/app.js'), 'utf8'), 'generation two application');
        assert.equal(await readFile(path.join(buildRoot, 'assets/chunks/new-lazy.js'), 'utf8'), 'generation two lazy module');
        assert.equal(await readFile(path.join(buildRoot, 'assets/styles/new.css'), 'utf8'), 'generation two stylesheet');
        assert.deepEqual(await readFile(path.join(buildRoot, 'pdfjs/wasm/new-decoder.wasm')), Buffer.from([1, 3, 5, 7]));
    });
});

test('a same-path different-byte collision fails before copying or switching the manifest', async () => {
    await withFixture(async ({ publicRoot, buildRoot }) => {
        const oldManifest = manifest({ 'resources/js/app.jsx': { file: 'assets/shared.js' } });
        await mkdir(buildRoot, { recursive: true });
        await writeTree(buildRoot, {
            'manifest.json': oldManifest,
            'assets/shared.js': 'old immutable bytes',
        });

        const stageFiles = {
            'manifest.json': manifest({ 'resources/js/app.jsx': { file: 'assets/shared.js' } }),
            'assets/shared.js': 'different new bytes',
            'assets/new-only.css': 'must not be copied during failed preflight',
        };
        const stagingRoot = await createStaging(publicRoot, 'collision', stageFiles);

        await assert.rejects(
            promoteViteBuild({ publicRoot, buildRoot, stagingRoot, token: 'collision' }),
            /active asset differs at assets\/shared\.js/,
        );

        assert.deepEqual(await readFile(path.join(buildRoot, 'manifest.json')), oldManifest);
        assert.equal(await readFile(path.join(buildRoot, 'assets/shared.js'), 'utf8'), 'old immutable bytes');
        await assert.rejects(readFile(path.join(buildRoot, 'assets/new-only.css')), { code: 'ENOENT' });
    });
});

test('copy failure leaves the old manifest live while the new asset set is incomplete', async () => {
    await withFixture(async ({ publicRoot, buildRoot }) => {
        const oldManifest = manifest({ 'resources/js/app.jsx': { file: 'assets/old-app.js' } });
        await mkdir(buildRoot, { recursive: true });
        await writeTree(buildRoot, {
            'manifest.json': oldManifest,
            'assets/old-app.js': 'old application bytes',
        });

        const stagingRoot = await createStaging(publicRoot, 'copy-failure', {
            'manifest.json': manifest({ 'resources/js/app.jsx': { file: 'assets/first-new.js' } }),
            'assets/first-new.js': 'first copied asset',
            'nested/second-new.css': 'second asset will fail',
        });
        const failingOperations = {
            copyFile: async (source, destination, flags) => {
                if (destination === path.join(buildRoot, 'nested/second-new.css')) {
                    assert.deepEqual(await readFile(path.join(buildRoot, 'manifest.json')), oldManifest);
                    assert.equal(await readFile(path.join(buildRoot, 'assets/first-new.js'), 'utf8'), 'first copied asset');
                    throw Object.assign(new Error('simulated copy failure'), { code: 'EIO' });
                }
                return copyFile(source, destination, flags);
            },
        };

        await assert.rejects(
            promoteViteBuild({ publicRoot, buildRoot, stagingRoot, token: 'copy-failure', operations: failingOperations }),
            /simulated copy failure/,
        );

        assert.deepEqual(await readFile(path.join(buildRoot, 'manifest.json')), oldManifest);
        assert.equal(await readFile(path.join(buildRoot, 'assets/old-app.js'), 'utf8'), 'old application bytes');
        assert.equal(await readFile(path.join(buildRoot, 'assets/first-new.js'), 'utf8'), 'first copied asset');
        await assert.rejects(readFile(path.join(buildRoot, 'nested/second-new.css')), { code: 'ENOENT' });
    });
});

test('manifest rename failure preserves the active manifest and old files', async () => {
    await withFixture(async ({ publicRoot, buildRoot }) => {
        const oldManifest = manifest({ 'resources/js/app.jsx': { file: 'assets/old-app.js' } });
        await mkdir(buildRoot, { recursive: true });
        await writeTree(buildRoot, {
            'manifest.json': oldManifest,
            'assets/old-app.js': 'old application bytes',
        });

        const stagingRoot = await createStaging(publicRoot, 'rename-failure', {
            'manifest.json': manifest({ 'resources/js/app.jsx': { file: 'assets/new-app.js' } }),
            'assets/new-app.js': 'new application bytes',
        });
        const failingOperations = {
            rename: async (source, destination) => {
                if (destination === path.join(buildRoot, 'manifest.json')) {
                    assert.deepEqual(await readFile(destination), oldManifest);
                    assert.equal(await readFile(path.join(buildRoot, 'assets/new-app.js'), 'utf8'), 'new application bytes');
                    throw Object.assign(new Error('simulated manifest rename failure'), { code: 'EACCES' });
                }
                return rename(source, destination);
            },
        };

        await assert.rejects(
            promoteViteBuild({ publicRoot, buildRoot, stagingRoot, token: 'rename-failure', operations: failingOperations }),
            /simulated manifest rename failure/,
        );

        assert.deepEqual(await readFile(path.join(buildRoot, 'manifest.json')), oldManifest);
        assert.equal(await readFile(path.join(buildRoot, 'assets/old-app.js'), 'utf8'), 'old application bytes');
        assert.equal(await readFile(path.join(buildRoot, 'assets/new-app.js'), 'utf8'), 'new application bytes');
        assert.deepEqual((await readdir(buildRoot)).filter(file => file.startsWith('.vite-build-manifest-')), []);
    });
});

test('repeated identical publications reuse immutable files without overwriting them', async () => {
    await withFixture(async ({ publicRoot, buildRoot }) => {
        const sameManifest = manifest({ 'resources/js/app.jsx': { file: 'assets/shared-app.js' } });
        await mkdir(buildRoot, { recursive: true });
        await writeTree(buildRoot, {
            'manifest.json': sameManifest,
            'assets/shared-app.js': 'same immutable application',
        });

        for (const token of ['repeat-one', 'repeat-two']) {
            const stagingRoot = await createStaging(publicRoot, token, {
                'manifest.json': sameManifest,
                'assets/shared-app.js': 'same immutable application',
            });
            let assetCopyCalls = 0;
            const trackingOperations = {
                copyFile: async (source, destination, flags) => {
                    if (destination === path.join(buildRoot, 'assets/shared-app.js')) assetCopyCalls += 1;
                    return copyFile(source, destination, flags);
                },
            };
            const result = await promoteViteBuild({ publicRoot, buildRoot, stagingRoot, token, operations: trackingOperations });
            assert.deepEqual(result, { assetCount: 1, copiedAssetCount: 0, reusedAssetCount: 1 });
            assert.equal(assetCopyCalls, 0);
            assert.equal(await readFile(path.join(buildRoot, 'assets/shared-app.js'), 'utf8'), 'same immutable application');
            assert.deepEqual(await readFile(path.join(buildRoot, 'manifest.json')), sameManifest);
        }
    });
});

test('an identical EEXIST race is accepted without overwriting the raced asset', async () => {
    await withFixture(async ({ publicRoot, buildRoot }) => {
        const oldManifest = manifest({ 'resources/js/app.jsx': { file: 'assets/old-app.js' } });
        await mkdir(buildRoot, { recursive: true });
        await writeTree(buildRoot, {
            'manifest.json': oldManifest,
            'assets/old-app.js': 'old application bytes',
        });
        const nextManifest = manifest({ 'resources/js/app.jsx': { file: 'assets/raced-new.js' } });
        const stagingRoot = await createStaging(publicRoot, 'identical-race', {
            'manifest.json': nextManifest,
            'assets/raced-new.js': 'identical raced bytes',
        });
        let raced = false;
        const racingOperations = {
            copyFile: async (source, destination, flags) => {
                if (destination === path.join(buildRoot, 'assets/raced-new.js') && !raced) {
                    raced = true;
                    await copyFile(source, destination, flags);
                    throw Object.assign(new Error('simulated competing exclusive create'), { code: 'EEXIST' });
                }
                return copyFile(source, destination, flags);
            },
        };

        const result = await promoteViteBuild({
            publicRoot,
            buildRoot,
            stagingRoot,
            token: 'identical-race',
            operations: racingOperations,
        });

        assert.equal(raced, true);
        assert.deepEqual(result, { assetCount: 1, copiedAssetCount: 0, reusedAssetCount: 1 });
        assert.equal(await readFile(path.join(buildRoot, 'assets/raced-new.js'), 'utf8'), 'identical raced bytes');
        assert.deepEqual(await readFile(path.join(buildRoot, 'manifest.json')), nextManifest);
    });
});

test('promotion refuses a staging directory outside the public root', async () => {
    await withFixture(async ({ root, publicRoot, buildRoot }) => {
        const outsideStaging = path.join(root, 'outside-staging');
        await mkdir(outsideStaging);
        await assert.rejects(
            promoteViteBuild({ publicRoot, buildRoot, stagingRoot: outsideStaging, token: 'outside' }),
            /unexpected staging path/,
        );
        await assert.rejects(readFile(path.join(buildRoot, 'manifest.json')), { code: 'ENOENT' });
    });
});
