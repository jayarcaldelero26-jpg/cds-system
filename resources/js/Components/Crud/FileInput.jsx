import Tooltip from '@/Components/Tooltip';

export function FileInput({ className = '', tooltip = 'Choose a file to upload.', ...props }) {
    return <Tooltip content={tooltip} className="block w-full"><input {...props} type="file" className={`block w-full cursor-pointer rounded-xl border border-gray-300 bg-white text-xs text-gray-500 shadow-sm file:mr-4 file:rounded-xl file:border-0 file:bg-green-50 file:px-4 file:py-2.5 file:text-xs file:font-semibold file:text-green-700 hover:file:bg-green-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 ${className}`} /></Tooltip>;
}

export default FileInput;
