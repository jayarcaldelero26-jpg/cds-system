import Tooltip from '@/Components/Tooltip';

export function FileInput({ className = '', tooltip = 'Choose a file to upload.', variant = 'calendar', ...props }) {
    const fieldClasses = variant === 'legacy'
        ? 'rounded-xl border border-gray-300 bg-white text-xs text-gray-500 shadow-sm file:mr-4 file:rounded-xl file:border-0 file:bg-green-50 file:px-4 file:py-2.5 file:text-xs file:font-semibold file:text-green-700 hover:file:bg-green-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300'
        : 'min-h-11 rounded-lg border border-gray-300 bg-white text-sm leading-5 font-normal text-gray-800 shadow-none outline-none transition file:mr-3 file:my-1 file:rounded-md file:border-0 file:bg-green-50 file:px-3 file:py-1.5 file:text-sm file:leading-5 file:font-normal file:text-green-800 hover:file:bg-green-100 focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:file:bg-green-950/50 dark:file:text-green-300 dark:hover:file:bg-green-950 dark:focus:border-green-500 dark:focus:ring-green-500/25';
    return <Tooltip content={tooltip} className="block w-full"><input {...props} type="file" className={`block w-full cursor-pointer disabled:cursor-not-allowed disabled:opacity-60 ${fieldClasses} ${className}`} /></Tooltip>;
}

export default FileInput;
