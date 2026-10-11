import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse } from '@babel/parser';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const defaultBuildRoot = path.join(projectRoot, 'public', 'build');
const configuredBuildRoot = process.env.CDS_VITE_BUILD_DIR;
const buildRoot = configuredBuildRoot
    ? path.resolve(projectRoot, configuredBuildRoot)
    : defaultBuildRoot;
const publicRoot = path.join(projectRoot, 'public');
if (buildRoot !== defaultBuildRoot && !buildRoot.startsWith(`${publicRoot}${path.sep}`)) {
    throw new Error('A temporary Vite verification directory must remain under public/.');
}
const manifestPath = path.join(buildRoot, 'manifest.json');
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
const appEntry = 'resources/js/app.jsx';

if (!manifest[appEntry]) {
    throw new Error(`The Vite manifest is missing its app entry: ${appEntry}`);
}

const pending = [appEntry];
const visited = new Set();
const parsedFiles = new Set();
const relativeImports = new Set();

function resolvedAssetPath(file) {
    const resolved = path.resolve(buildRoot, file);
    if (!resolved.startsWith(`${buildRoot}${path.sep}`)) {
        throw new Error(`The Vite manifest points outside public/build: ${file}`);
    }
    return resolved;
}

function addRelativeImports(ast, sourceFile) {
    const sources = [];
    for (const statement of ast.program.body) {
        if ((statement.type === 'ImportDeclaration'
            || statement.type === 'ExportNamedDeclaration'
            || statement.type === 'ExportAllDeclaration')
            && statement.source?.value) {
            sources.push(statement.source.value);
        }
    }

    const visit = node => {
        if (!node || typeof node !== 'object') return;
        if (node.type === 'ImportExpression' && node.source?.type === 'StringLiteral') {
            sources.push(node.source.value);
        }
        for (const value of Object.values(node)) {
            if (Array.isArray(value)) value.forEach(visit);
            else if (value && typeof value === 'object') visit(value);
        }
    };
    visit(ast.program);

    for (const specifier of sources) {
        if (!specifier.startsWith('.')) continue;
        const dependency = path.resolve(path.dirname(sourceFile), specifier);
        relativeImports.add(dependency);
        if (!dependency.startsWith(`${buildRoot}${path.sep}`)) {
            throw new Error(`A generated JavaScript import escapes public/build: ${specifier}`);
        }
    }
}

while (pending.length) {
    const key = pending.pop();
    if (visited.has(key)) continue;
    visited.add(key);

    const entry = manifest[key];
    if (!entry) throw new Error(`The Vite manifest references a missing entry: ${key}`);

    for (const dependency of [...(entry.imports || []), ...(entry.dynamicImports || [])]) {
        if (!manifest[dependency]) {
            throw new Error(`The Vite manifest entry ${key} references missing dependency ${dependency}`);
        }
        pending.push(dependency);
    }

    if (!entry.file || !/\.m?js$/i.test(entry.file)) continue;

    const filePath = resolvedAssetPath(entry.file);
    const bytes = await readFile(filePath);
    if (bytes.length === 0 || bytes.includes(0)) {
        throw new Error(`Generated JavaScript contains an empty or NUL byte: ${entry.file}`);
    }

    const ast = parse(bytes.toString('utf8'), { sourceType: 'module', sourceFilename: key });
    addRelativeImports(ast, filePath);
    parsedFiles.add(filePath);
}

for (const dependencyPath of relativeImports) {
    try {
        await readFile(dependencyPath);
    } catch {
        throw new Error(`A generated JavaScript import does not exist: ${path.relative(buildRoot, dependencyPath)}`);
    }
}

console.log(`Verified ${parsedFiles.size} ESM assets and ${relativeImports.size} generated relative imports reachable from ${appEntry}.`);
