export default function Input({ className = '', error = false, ...props }) {
    return <input className={`block w-full rounded-ui border bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-green-700 focus:ring-green-700 disabled:cursor-not-allowed disabled:bg-gray-100 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500 dark:disabled:bg-gray-800 ${error ? 'border-red-500 focus:border-red-600 focus:ring-red-600' : 'border-gray-300 dark:border-gray-600'} ${className}`} {...props} />;
}
