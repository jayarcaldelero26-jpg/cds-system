import { createRequire } from 'node:module';
import { resolve } from 'node:path';

// Vite 8's SSR module runner can evaluate React 19's CommonJS entry as ESM,
// where `module` is undefined. Route only React package imports back through
// Node's native CommonJS loader so rendered tests and components share one
// React instance without changing application or production resolution.
export function viteReactInterop() {
    const require = createRequire(resolve('package.json'));
    const portalSource = resolve('resources/js/Components/ModalPortal.jsx').replaceAll('\\', '/');
    const portalImports = new Set(['@/Components/ModalPortal', portalSource, portalSource.slice(0, -4)]);
    const sources = [
        'react', 'react/jsx-runtime', 'react/jsx-dev-runtime',
        'react-dom', 'react-dom/client', 'react-dom/server',
        'use-sync-external-store/shim',
        'use-sync-external-store/shim/with-selector',
        'use-sync-external-store/with-selector',
        'use-sync-external-store/with-selector.js',
        'eventemitter3',
        'react-is',
    ];
    const modules = new Map(sources.map(source => [source, `\0test-react:${source}`]));

    return {
        name: 'test-react-native-cjs-interop',
        enforce: 'pre',
        resolveId(source, importer, options) {
            // Markup fixtures inspect dialog contents; real portal placement has
            // dedicated tests and must stay enabled in every browser module.
            const serverFixture = options?.ssr || this.environment?.config?.consumer === 'server';
            if (serverFixture && portalImports.has(source.replaceAll('\\', '/'))) return '\0test-modal-portal-inline';
            return modules.get(source);
        },
        load(id) {
            if (id === '\0test-modal-portal-inline') return 'export default function ModalPortalMarkupFixture({ children }) { return children; }';
            const source = [...modules].find(([, virtualId]) => virtualId === id)?.[0];
            if (!source) return null;
            const exports = Object.keys(require(source))
                .filter(name => name !== 'default' && /^[A-Za-z_$][\w$]*$/.test(name));
            return [
                `import { createRequire } from 'node:module';`,
                `const require = createRequire(${JSON.stringify(resolve('package.json'))});`,
                `const namespace = require(${JSON.stringify(source)});`,
                `export default namespace;`,
                ...exports.map(name => `export const ${name} = namespace[${JSON.stringify(name)}];`),
            ].join('\n');
        },
    };
}
