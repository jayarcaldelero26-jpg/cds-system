import SettingsShell from '@/Components/Admin/SettingsShell';

export default function SettingsIndex() {
    return <SettingsShell>
        <div className="flex min-h-[220px] items-center justify-center text-center"><div><p className="text-xs font-extrabold uppercase tracking-[0.16em] text-gray-500 dark:text-gray-400">Administration workspace</p><h2 className="mt-2 text-xl font-extrabold text-gray-900 dark:text-white">Select a settings area</h2><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-500 dark:text-gray-400">Choose a section from the navigation to manage configuration, connected services, or system health.</p></div></div>
    </SettingsShell>;
}
