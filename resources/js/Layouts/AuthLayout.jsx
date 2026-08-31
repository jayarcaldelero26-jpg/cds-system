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

export default function AuthLayout({ title, children, contentClassName = '' }) {
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
            <main className="relative flex min-h-screen items-center justify-center overflow-x-hidden bg-gradient-to-b from-[#fbfdfb] via-[#f3faf5] to-[#e7f4ea] px-4 py-16 pt-20 dark:from-[#10231f] dark:via-[#132b25] dark:to-[#0d1d1a] sm:px-6 sm:py-12">
                <div className="pointer-events-none absolute inset-0" aria-hidden="true">
                    <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_rgba(255,255,255,0.92)_0%,_rgba(255,255,255,0.35)_44%,_transparent_72%)] dark:bg-[radial-gradient(ellipse_at_center,_rgba(25,56,47,0.56)_0%,_transparent_68%)]" />
                    <svg className="absolute inset-x-0 bottom-[10%] hidden h-[46%] w-full text-emerald-800/[0.07] dark:text-emerald-200/[0.08] sm:block" viewBox="0 0 1440 420" preserveAspectRatio="none"><path d="M0 360C155 270 236 191 347 249c105 55 171-137 304-106 92 21 93 121 205 65 135-67 183-250 327-142 91 68 142 142 257 88v366H0Z" fill="currentColor" /><path d="M0 398c151-38 229-145 372-96 122 42 143-65 273-60 152 6 216 107 352 63 157-51 228-143 443-96v211H0Z" fill="currentColor" opacity=".55" /></svg>
                    <svg className="absolute bottom-0 right-0 hidden h-64 w-[44rem] max-w-[68%] text-emerald-900/[0.08] dark:text-emerald-100/[0.08] sm:block" viewBox="0 0 704 255" preserveAspectRatio="none"><path d="M0 255V174l21-41 21 41v-81l31-62 31 62v57l24-48 24 48v105h24v-69l29-57 29 57v69h24v-95l34-68 34 68v35l25-50 25 50v60h24v-113l38-76 38 76v113h27v-72l29-58 29 58v72h24v-46l22-44 22 44v46h26v-95l33-66 33 66v95h29v-56l27-54 27 54v56h29v-132l41-82 41 82v132h44v90H0Z" fill="currentColor" /></svg>
                    <svg className="absolute -bottom-12 -left-12 hidden h-60 w-60 rotate-[-18deg] text-emerald-800/[0.10] dark:text-emerald-200/[0.10] sm:block" viewBox="0 0 180 180"><path d="M44 165C14 114 33 55 83 21c24 50 9 101-39 144ZM65 150c16-44 29-73 55-105M104 162c-7-47 5-80 36-110 23 45 9 88-36 110Z" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>
                    <svg className="absolute -bottom-14 -right-8 hidden h-64 w-64 rotate-[18deg] text-emerald-800/[0.10] dark:text-emerald-200/[0.10] sm:block" viewBox="0 0 180 180"><path d="M136 166c30-51 11-110-39-144-24 50-9 101 39 144ZM115 151C99 107 86 78 60 46M76 163c7-47-5-80-36-110-23 45-9 88 36 110Z" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>
                    <svg className="absolute inset-x-0 bottom-0 h-24 w-full text-emerald-700/[0.10] dark:text-emerald-100/[0.08]" viewBox="0 0 1440 80" preserveAspectRatio="none"><path d="M0 62c202-46 283 17 497-10 235-29 325-76 559-39 177 28 255 6 384-35v82H0Z" fill="currentColor" /></svg>
                </div>

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
                            <h1 className="mt-3 text-3xl font-bold leading-none tracking-tight text-emerald-900 dark:text-emerald-300 sm:text-[2.1rem]">eDATS</h1>
                            <p className="mt-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">Enhanced Digital Alert and Tracking System</p>
                            <p className="mt-1 text-[10px] font-semibold uppercase tracking-[0.11em] text-emerald-800 dark:text-emerald-400">PENRO Mati – Conservation and Development Section</p>
                            <BrandDivider />
                        </header>
                        {children}
                    </div>
                    <footer className="mt-3 text-center text-xs leading-5 text-slate-600 dark:text-slate-400">Department of Environment and Natural Resources<br />PENRO Mati</footer>
                </section>
            </main>
        </>
    );
}
