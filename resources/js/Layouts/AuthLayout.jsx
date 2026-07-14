import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function AuthLayout({ title, children }) {
    const [darkMode, setDarkMode] = useState(false);

    useEffect(() => {
        const savedTheme = window.localStorage.getItem('cds-theme');
        const shouldUseDark = savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);
        setDarkMode(shouldUseDark);
        document.documentElement.classList.toggle('dark', shouldUseDark);
    }, []);

    const toggleTheme = () => {
        const next = !darkMode;
        setDarkMode(next);
        document.documentElement.classList.toggle('dark', next);
        window.localStorage.setItem('cds-theme', next ? 'dark' : 'light');
    };

    return <><Head title={title} /><main className="relative flex min-h-screen items-center justify-center overflow-hidden bg-gray-100 px-4 py-10 dark:bg-gray-950 sm:px-6"><div className="pointer-events-none absolute inset-x-0 top-0 h-1 bg-green-800 dark:bg-green-600" /><button type="button" onClick={toggleTheme} className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-ui border border-gray-300 bg-white text-gray-600 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 sm:right-6 sm:top-6" aria-label={darkMode ? 'Use light mode' : 'Use dark mode'}>{darkMode ? 'Light' : 'Dark'}</button><section className="w-full max-w-md" aria-label="Authentication"><div className="rounded-xl border border-gray-200 bg-white p-6 shadow-card dark:border-gray-700 dark:bg-gray-900 sm:p-8"><header className="text-center"><img src="/images/DENR%20LOGO.png" alt="Department of Environment and Natural Resources logo" className="mx-auto h-20 w-20 object-contain sm:h-24 sm:w-24" /><h1 className="mt-5 text-2xl font-bold leading-tight tracking-tight text-gray-900 dark:text-white">Conservation and Development<br />Information Management System</h1><p className="mt-2 text-sm font-medium text-green-800 dark:text-green-400">DENR PENRO Mati</p><div className="mx-auto mt-5 h-px w-16 bg-green-700 dark:bg-green-500" /></header>{children}</div><footer className="mt-5 text-center text-xs text-gray-500 dark:text-gray-400">Department of Environment and Natural Resources<br />Province of Davao Oriental</footer></section></main></>;
}
