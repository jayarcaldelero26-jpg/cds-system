import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { AuthenticatedShell } from './Layouts/AuthenticatedLayout';

const appName = import.meta.env.VITE_APP_NAME || 'eDATS';
const pageModules = import.meta.glob('./Pages/**/*.jsx');

// Public and authentication screens provide their own layout (or intentionally
// render without one). Every other page is an authenticated application page.
const isPublicPage = (name) => name === 'Welcome' || name.startsWith('Auth/');

const resolve = async (name) => {
    const pageModule = await resolvePageComponent(`./Pages/${name}.jsx`, pageModules);
    const Page = pageModule.default ?? pageModule;

    if (!isPublicPage(name) && !Page.layout) {
        Page.layout = (pageElement) => <AuthenticatedShell>{pageElement}</AuthenticatedShell>;
    }

    return Page;
};

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve,
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    // 🚀 GI-UPDATE KINI NGA PROGRESS CONFIGURATION 🚀
    progress: { color: '#16a34a', delay: 150, showSpinner: false },
});
