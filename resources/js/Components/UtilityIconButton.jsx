export default function UtilityIconButton({ children, className = '', type = 'button', ...props }) {
    return <button type={type} className={`inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white ${className}`} {...props}>{children}</button>;
}
