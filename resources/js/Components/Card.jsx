const variants = { default: 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900', subtle: 'border-gray-200 bg-gray-50/80 dark:border-gray-700 dark:bg-gray-800/70', elevated: 'border-transparent bg-white shadow-md dark:bg-gray-900' };

export default function Card({ children, className = '', padding = 'p-6', variant = 'default' }) {
    return <section className={`rounded-xl border shadow-card ${variants[variant] || variants.default} ${padding} ${className}`}>{children}</section>;
}
