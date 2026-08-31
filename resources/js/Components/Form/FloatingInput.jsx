import FloatingField from './FloatingField';

export default function FloatingInput({
    label,
    error,
    hint,
    size = 'default',
    alwaysFloat = false,
    focusTone,
    variant = 'calendar',
    className = '',
    leadingIcon = null,
    showFocusRing = true,
    ...props
}) {
    const supportsPlaceholder = !['date', 'datetime-local', 'month', 'time', 'week', 'color', 'range'].includes(props.type);
    const nativeSurface = variant === 'legacy'
        ? 'bg-transparent text-gray-900 outline-none focus-visible:ring-0 focus-visible:ring-offset-0 dark:text-gray-100'
        : '!border-0 !bg-transparent !shadow-none !outline-none text-gray-800 focus:!border-0 focus:!shadow-none focus:!outline-none focus-visible:!ring-0 focus-visible:!ring-offset-0 dark:text-gray-100';
    return (
        <FloatingField
            label={label}
            value={props.value}
            defaultValue={props.defaultValue}
            error={error}
            hint={hint}
            size={size}
            alwaysFloat={alwaysFloat}
            focusTone={focusTone || (variant === 'legacy' ? 'blue' : 'green')}
            variant={variant}
            disabled={props.disabled}
            readOnly={props.readOnly}
            required={props.required}
            className={className}
            hasLeadingIcon={Boolean(leadingIcon)}
            showFocusRing={showFocusRing}
        >
            {({ ref, nativeProps, sizeClasses, fieldShapeClass, showPlaceholder }) => {
                const placeholderClasses = supportsPlaceholder
                    ? 'placeholder:font-normal placeholder:opacity-0 focus:placeholder:text-gray-400 focus:placeholder:opacity-100 dark:focus:placeholder:text-gray-500 ' + (showPlaceholder ? 'placeholder:text-gray-400 placeholder:opacity-100 dark:placeholder:text-gray-500' : '')
                    : '';
                const inputClasses = [
                    'block w-full',
                    fieldShapeClass,
                    nativeSurface,
                    placeholderClasses,
                    leadingIcon ? 'pl-10' : '',
                    sizeClasses,
                ].filter(Boolean).join(' ');

                return (
                    <>
                        {leadingIcon && <span className="pointer-events-none absolute inset-y-0 left-3 z-10 flex items-center text-emerald-700 dark:text-emerald-400">{leadingIcon}</span>}
                        <input {...props} {...nativeProps} ref={ref} className={inputClasses} />
                    </>
                );
            }}
        </FloatingField>
    );
}