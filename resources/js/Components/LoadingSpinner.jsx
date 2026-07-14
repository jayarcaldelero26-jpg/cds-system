export default function LoadingSpinner({ label = 'Loading' }) {
    return <span className="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300" role="status"><span className="h-4 w-4 animate-spin rounded-full border-2 border-green-800 border-t-transparent dark:border-green-400" aria-hidden="true" />{label}<span className="sr-only">...</span></span>;
}
