import FloatingField from './FloatingField';

export default function FloatingSelect({
    label,
    error,
    hint,
    size = 'default',
    focusTone,
    variant = 'calendar',
    className = '',
    leadingIcon = null,
    hideEmptyBlurredValue = false,
    showFocusRing = true,
    children,
    value,
    onChange,
    ...props
}) {
    const nativeSurface = variant === 'legacy'
        ? 'bg-transparent outline-none focus-visible:ring-0 focus-visible:ring-offset-0'
        : '!border-0 !bg-transparent !shadow-none !outline-none focus:!border-0 focus:!shadow-none focus:!outline-none focus-visible:!ring-0 focus-visible:!ring-offset-0';
    return (
        <FloatingField
            label={label}
            value={value}
            defaultValue={props.defaultValue}
            error={error}
            hint={hint}
            size={size}
            focusTone={focusTone || (variant === 'legacy' ? 'blue' : 'green')}
            variant={variant}
            disabled={props.disabled}
            readOnly={props.readOnly}
            required={props.required}
            className={className}
            hasLeadingIcon={Boolean(leadingIcon)}
            showFocusRing={showFocusRing}
        >
            {({ ref, nativeProps, sizeClasses, fieldShapeClass, focused, hasValue }) => {
                const hideEmptyValue = hideEmptyBlurredValue && !focused && !hasValue;
                const selectTextClasses = hideEmptyValue
                    ? '!text-transparent'
                    : 'text-gray-800 dark:text-gray-100';
                const selectClasses = [
                    'block w-full appearance-none !bg-none',
                    fieldShapeClass,
                    nativeSurface,
                    leadingIcon ? 'pl-10' : '',
                    'pr-9 disabled:text-gray-500 dark:disabled:text-gray-400 focus:text-gray-800 dark:focus:text-gray-100',
                    selectTextClasses,
                    sizeClasses,
                ].join(' ');

                return (
                    <>
                        {leadingIcon && <span className="pointer-events-none absolute inset-y-0 left-3 z-10 flex items-center text-emerald-700 dark:text-emerald-400">{leadingIcon}</span>}
                        <select {...props} {...nativeProps} ref={ref} value={value} onChange={onChange} className={selectClasses}>{children}</select>
                        <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-gray-500 dark:text-gray-400">
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.75" className="h-4 w-4"><path d="m5 7.5 5 5 5-5" strokeLinecap="round" strokeLinejoin="round" /></svg>
                        </span>
                    </>
                );
            }}
        </FloatingField>
    );
}