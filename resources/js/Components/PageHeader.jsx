export default function PageHeader({ title, description, actions }) {
    return (
        <div className="relative overflow-hidden rounded-xl bg-gradient-to-r from-green-600 via-green-700 to-green-800 p-6 text-white shadow-md">
            {/* Subtle decorative circle */}
            <div className="pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full bg-white/10" />

            <div className="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-white">
                        {title}
                    </h1>

                    {description && (
                        <p className="mt-1 text-sm text-green-100">
                            {description}
                        </p>
                    )}
                </div>

                {actions && (
                    <div className="flex items-center gap-3">
                        {actions}
                    </div>
                )}
            </div>
        </div>
    );
}
