import Input from './Input';

export default function FormField({ label, error, hint, id, className = '', ...props }) { return <label htmlFor={id} className={`block text-sm font-medium text-gray-700 dark:text-gray-200 ${className}`}><span>{label}</span><Input id={id} error={Boolean(error)} className="mt-1.5" {...props} />{hint && <span className="mt-1.5 block text-xs font-normal text-gray-500 dark:text-gray-400">{hint}</span>}{error && <p className="mt-1.5 text-sm font-normal text-red-700 dark:text-red-300">{error}</p>}</label>; }
