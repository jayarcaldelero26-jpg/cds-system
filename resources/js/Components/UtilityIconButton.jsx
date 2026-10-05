export default function UtilityIconButton({ children, className = '', type = 'button', compact = false, ...props }) {
    const sizeClasses = compact === 'tight' ? 'h-5 w-8 min-h-5 min-w-8' : compact ? 'h-8 w-8 min-h-8 min-w-8' : 'min-h-10 min-w-10';
    return <button type={type} className={`inline-flex ${sizeClasses} items-center justify-center rounded-lg text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white ${className}`} {...props}>{children}</button>;
}
