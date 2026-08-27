import FloatingField from './FloatingField';

export default function FloatingTextarea({ label, error, hint, size = 'default', className = '', rows = 4, ...props }) {
    return <FloatingField label={label} value={props.value} defaultValue={props.defaultValue} error={error} hint={hint} size={size} multiline disabled={props.disabled} readOnly={props.readOnly} required={props.required} className={className}>
        {({ ref, nativeProps, sizeClasses, showPlaceholder }) => <textarea {...props} {...nativeProps} ref={ref} rows={rows} className={`block min-h-28 w-full resize-y rounded-ui bg-transparent text-gray-900 outline-none focus-visible:ring-0 focus-visible:ring-offset-0 placeholder:opacity-0 focus:placeholder:text-gray-400 focus:placeholder:opacity-100 dark:text-gray-100 dark:focus:placeholder:text-gray-500 ${showPlaceholder ? 'placeholder:text-gray-400 placeholder:opacity-100 dark:placeholder:text-gray-500' : ''} ${sizeClasses} ${props.className || ''}`} />}
    </FloatingField>;
}
