import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FlashSuccessDialog from '../Components/FlashSuccessDialog';

function ThemeIcon({ darkMode }) {
    return darkMode ? (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4" aria-hidden="true"><circle cx="12" cy="12" r="3.5" /><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" /></svg>
    ) : (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4" aria-hidden="true"><path d="M20.5 15.3A8.5 8.5 0 0 1 8.7 3.5 8.5 8.5 0 1 0 20.5 15.3Z" /></svg>
    );
}

function BrandDivider() {
    return <div className="mt-4 flex items-center justify-center gap-2 text-emerald-700/55 dark:text-emerald-300/50" aria-hidden="true"><span className="h-px w-9 bg-current" /><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-3.5 w-3.5"><path d="M12 20V10" /><path d="M12 13c-4 0-6-2.4-6-6 4 0 6 2.4 6 6ZM12 16c4 0 6-2.4 6-6-4 0-6 2.4-6 6Z" /></svg><span className="h-px w-9 bg-current" /></div>;
}

export default function AuthLayout({ title, children, contentClassName = '', cleanBackground = false }) {
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

    return (
        <>
            <Head title={title} />
            <FlashSuccessDialog />
            <main
                className="relative flex min-h-screen items-center justify-center overflow-x-hidden bg-cover bg-center bg-no-repeat px-4 py-16 pt-20 sm:px-6 sm:py-12"
                style={{ backgroundImage: "url('/images/cds-smart-background.png')" }}
            >
                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-emerald-950/55 via-emerald-950/45 to-slate-950/55" aria-hidden="true" />
                <button type="button" onClick={toggleTheme} className="absolute right-4 top-4 z-10 inline-flex h-9 items-center gap-1.5 rounded-full border border-emerald-950/10 bg-white/80 px-3 text-xs font-semibold text-emerald-900 shadow-sm backdrop-blur-sm transition hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:border-emerald-100/15 dark:bg-slate-900/80 dark:text-emerald-200 dark:hover:bg-slate-800 sm:right-6 sm:top-6" aria-label={darkMode ? 'Switch to light mode' : 'Switch to dark mode'}>
                    <ThemeIcon darkMode={darkMode} />
                    <span>{darkMode ? 'Light' : 'Dark'}</span>
                </button>

                <section className={"relative z-10 w-full " + (contentClassName || "max-w-[33.75rem]")} aria-label="Authentication">
                    <div className="rounded-[1.65rem] border border-emerald-950/[0.10] bg-white/[0.96] p-5 shadow-[0_24px_65px_-34px_rgba(26,87,61,0.38)] dark:border-emerald-100/[0.14] dark:bg-[#172d27]/[0.96] dark:shadow-[0_24px_65px_-34px_rgba(0,0,0,0.8)] sm:p-7 md:p-8">
                        <header className="text-center">
                            <div className="flex items-center justify-center gap-4 sm:gap-5">
                                <img src="/images/DENR%20LOGO.png" alt="Department of Environment and Natural Resources logo" className="h-[4.35rem] w-[4.35rem] object-contain sm:h-[4.75rem] sm:w-[4.75rem]" />
                                <span className="h-11 w-px bg-emerald-900/15 dark:bg-emerald-100/15" aria-hidden="true" />
                                <img src="/images/CDS%20Logo.png" alt="Conservation and Development Section logo" className="h-16 w-16 object-contain sm:h-[4.25rem] sm:w-[4.25rem]" />
                            </div>
                            <h1 className="mt-3 text-3xl font-bold leading-none tracking-tight text-emerald-900 dark:text-emerald-300 sm:text-[2.1rem]">CDS-SMART</h1>
                            <p className="mt-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">Conservation and Development Section - Submission Monitoring and Reminder Tool</p>
                            <p className="mt-1 text-[10px] font-semibold uppercase tracking-[0.11em] text-emerald-800 dark:text-emerald-400">PENRO Davao Oriental</p>
                            <BrandDivider />
                        </header>
                        {children}
                    </div>
                                        <footer className="mt-3 text-center text-xs leading-5 text-slate-600 dark:text-slate-400">
                        {cleanBackground ? (
                            <>
                                <p>CDS-SMART is a system of the Conservation and Development Section, PENRO Davao Oriental.</p>
                                <p className="mt-1">For authorized users only. System activities may be monitored and logged.</p>
                                <p className="mt-1">© 2026 Provincial Environment and Natural Resources Office. All rights reserved.</p>
                            </>
                        ) : (
                            <>Department of Environment and Natural Resources<br />PENRO Davao Oriental</>
                        )}
                    </footer>
                </section>
            </main>
        </>
    );
}
