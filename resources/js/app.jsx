import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'CDS Information Management System';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    // 🚀 GI-UPDATE KINI NGA PROGRESS CONFIGURATION 🚀
    progress: {
        // Gihimong Green aron motakdo sa imong CDS theme
        color: '#166534',

        // Mosiga lang kon molapas og 250ms ang loading (hinay nga connection)
        delay: 250,

        // Gi-off ang loading circle sa upper right aron limpyo tan-awon
        showSpinner: false
    },
});
