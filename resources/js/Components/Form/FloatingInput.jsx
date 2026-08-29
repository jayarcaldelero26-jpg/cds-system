import FloatingField from './FloatingField';

export default function FloatingInput({ label, error, hint, size = 'default', alwaysFloat = false, focusTone, variant = 'calendar', className = '', ...props }) {
    const supportsPlaceholder = !['date', 'datetime-local', 'month', 'time', 'week', 'color', 'range'].includes(props.type);
    const nativeSurface = variant === 'legacy'
        ? 'bg-transparent text-gray-900 outline-none focus-visible:ring-0 focus-visible:ring-offset-0 dark:text-gray-100'
        : '!border-0 !bg-transparent !shadow-none !outline-none text-gray-800 focus:!border-0 focus:!shadow-none focus:!outline-none focus-visible:!ring-0 focus-visible:!ring-offset-0 dark:text-gray-100';
    return <FloatingField label={label} value={props.value} defaultValue={props.defaultValue} error={error} hint={hint} size={size} alwaysFloat={alwaysFloat} focusTone={focusTone || (variant === 'legacy' ? 'blue' : 'green')} variant={variant} disabled={props.disabled} readOnly={props.readOnly} required={props.required} className={className}>
        {({ ref, nativeProps, sizeClasses, fieldShapeClass, showPlaceholder }) => {
            const placeholderClasses = supportsPlaceholder ? `placeholder:font-normal placeholder:opacity-0 focus:placeholder:text-gray-400 focus:placeholder:opacity-100 dark:focus:placeholder:text-gray-500 ${showPlaceholder ? 'placeholder:text-gray-400 placeholder:opacity-100 dark:placeholder:text-gray-500' : ''}` : '';
            return <input {...props} {...nativeProps} ref={ref} className={`block w-full ${fieldShapeClass} ${nativeSurface} ${placeholderClasses} ${sizeClasses} ${props.className || ''}`} />;
        }}
    </FloatingField>;
}
