import FloatingField from './FloatingField';

export default function FloatingInput({ label, error, hint, size = 'default', alwaysFloat = false, className = '', ...props }) {
    const supportsPlaceholder = !['date', 'datetime-local', 'month', 'time', 'week', 'color', 'range'].includes(props.type);
    return <FloatingField label={label} value={props.value} defaultValue={props.defaultValue} error={error} hint={hint} size={size} alwaysFloat={alwaysFloat || props.type === 'date' || props.type === 'datetime-local' || props.type === 'month'} disabled={props.disabled} readOnly={props.readOnly} required={props.required} className={className}>
        {({ ref, nativeProps, sizeClasses, showPlaceholder }) => {
            const placeholderClasses = supportsPlaceholder ? `placeholder:opacity-0 focus:placeholder:text-gray-400 focus:placeholder:opacity-100 dark:focus:placeholder:text-gray-500 ${showPlaceholder ? 'placeholder:text-gray-400 placeholder:opacity-100 dark:placeholder:text-gray-500' : ''}` : '';
            return <input {...props} {...nativeProps} ref={ref} className={`block w-full rounded-ui bg-transparent text-gray-900 outline-none focus-visible:ring-0 focus-visible:ring-offset-0 dark:text-gray-100 ${placeholderClasses} ${sizeClasses} ${props.className || ''}`} />;
        }}
    </FloatingField>;
}
