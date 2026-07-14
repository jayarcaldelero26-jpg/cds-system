const variants = {
    primary: 'bg-brand-700 text-white shadow-sm hover:bg-brand-800',
    secondary: 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700',
    danger: 'bg-red-700 text-white shadow-sm hover:bg-red-800',
    ghost: 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800',
};

export default function Button({ variant = 'primary', className = '', type = 'button', children, ...props }) {
    return <button type={type} className={`inline-flex min-h-10 items-center justify-center rounded-ui px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-green-400 dark:focus:ring-offset-gray-900 ${variants[variant] || variants.primary} ${className}`} {...props}>{children}</button>;
}
