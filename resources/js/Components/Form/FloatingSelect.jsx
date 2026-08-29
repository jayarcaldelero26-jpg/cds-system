import FloatingField from './FloatingField';

export default function FloatingSelect({ label, error, hint, size = 'default', focusTone, variant = 'calendar', className = '', children, value, onChange, ...props }) {
    const nativeSurface = variant === 'legacy'
        ? 'bg-transparent outline-none focus-visible:ring-0 focus-visible:ring-offset-0'
        : '!border-0 !bg-transparent !shadow-none !outline-none focus:!border-0 focus:!shadow-none focus:!outline-none focus-visible:!ring-0 focus-visible:!ring-offset-0';
    return <FloatingField label={label} value={value} defaultValue={props.defaultValue} error={error} hint={hint} size={size} focusTone={focusTone || (variant === 'legacy' ? 'blue' : 'green')} variant={variant} disabled={props.disabled} readOnly={props.readOnly} required={props.required} className={className}>
        {({ ref, nativeProps, sizeClasses, fieldShapeClass, focused, hasValue }) => <>
            <select {...props} {...nativeProps} ref={ref} value={value} onChange={onChange} className={`block w-full appearance-none !bg-none ${fieldShapeClass} ${nativeSurface} pr-9 focus:text-gray-800 dark:focus:text-gray-100 ${focused || hasValue ? 'text-gray-800 dark:text-gray-100' : 'text-transparent'} ${sizeClasses} ${props.className || ''}`}>{children}</select>
            <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-gray-500 dark:text-gray-400">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.75" className="h-4 w-4"><path d="m5 7.5 5 5 5-5" strokeLinecap="round" strokeLinejoin="round" /></svg>
            </span>
        </>}
    </FloatingField>;
}
