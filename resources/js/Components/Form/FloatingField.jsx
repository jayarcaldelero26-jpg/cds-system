import { useEffect, useId, useRef, useState } from 'react';

const sizeClasses = {
    default: 'h-11 px-3 py-2.5 text-sm leading-5 font-normal',
    sm: 'h-10 px-3 py-2 text-sm leading-5 font-normal',
};

const legacySizeClasses = {
    default: 'h-11 px-3 py-2.5 text-sm',
    sm: 'h-10 px-2.5 py-2 text-xs',
};

export function useFloatingValue({ value, defaultValue, alwaysFloat = false }) {
    const [focused, setFocused] = useState(false);
    const ref = useRef(null);
    const hasContent = candidate => candidate !== undefined && candidate !== null && String(candidate).length > 0;
    const [nativeHasValue, setNativeHasValue] = useState(() => hasContent(value !== undefined ? value : defaultValue));
    const hasValue = alwaysFloat || nativeHasValue;

    useEffect(() => {
        if (value !== undefined) setNativeHasValue(hasContent(value));
    }, [value]);

    useEffect(() => {
        if (value !== undefined) return undefined;

        const node = ref.current;
        if (!node) return undefined;
        const check = () => setNativeHasValue(hasContent(node.value));
        check();
        const timer = window.setTimeout(check, 150);
        node.addEventListener('input', check);
        node.addEventListener('change', check);
        return () => {
            window.clearTimeout(timer);
            node.removeEventListener('input', check);
            node.removeEventListener('change', check);
        };
    }, [value]);

    return { ref, focused, hasValue, setFocused, setNativeHasValue, floated: focused || hasValue };
}

export default function FloatingField({
    id,
    label,
    value,
    defaultValue,
    error,
    hint,
    size = 'default',
    disabled = false,
    readOnly = false,
    required = false,
    className = '',
    children,
    alwaysFloat = false,
    multiline = false,
    focusTone = 'green',
    variant = 'calendar',
    hasLeadingIcon = false,
    showFocusRing = true,
}) {
    const generatedId = useId();
    const fieldId = id || 'field-' + generatedId.replace(/:/g, '');
    const { floated, focused, hasValue, setFocused, setNativeHasValue, ref } = useFloatingValue({ value, defaultValue, alwaysFloat });
    const invalid = Boolean(error);
    const calendarStyle = variant !== 'legacy';
    const background = disabled || readOnly ? 'bg-gray-50 dark:bg-gray-800' : 'bg-white dark:bg-gray-900';
    const focusBorder = focusTone === 'green' ? 'border-green-700 dark:border-green-500' : 'border-blue-600';
    const focusText = focusTone === 'green' ? 'text-green-700 dark:text-green-400' : 'text-blue-600 dark:text-blue-400';
    const border = invalid ? 'border-red-500' : focused ? focusBorder : 'border-gray-300 dark:border-gray-600';
    const labelColor = invalid ? 'text-red-600 dark:text-red-400' : focused ? focusText : 'text-gray-600 dark:text-gray-300';
    const focusRingClass = showFocusRing && calendarStyle
        ? focusTone === 'green'
            ? ' focus-within:ring-1 focus-within:ring-green-700/20 dark:focus-within:ring-green-500/25'
            : ' focus-within:ring-1 focus-within:ring-blue-700/20 dark:focus-within:ring-blue-500/25'
        : '';
    return (
        <div
            className={'min-w-0 ' + className}
            onFocus={() => setFocused(true)}
            onAnimationStart={(event) => event.animationName === 'floating-field-autofill' && setNativeHasValue(event.target?.value !== undefined && event.target?.value !== '')}
            onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false); }}
        >
            <label htmlFor={fieldId} className={'mb-1.5 block min-w-0 break-words text-xs font-semibold leading-4 ' + labelColor}>
                {label}{required && <span className="ml-0.5 whitespace-nowrap text-red-500 dark:text-red-400">*</span>}
            </label>
            <div className={'relative min-w-0 border shadow-none transition-colors duration-150 ' + (calendarStyle ? 'rounded-lg' : 'rounded-ui') + focusRingClass + ' ' + background + ' ' + border}>
                {children({
                    ref,
                    nativeProps: { id: fieldId, disabled, readOnly, required, 'aria-invalid': invalid || undefined, 'aria-describedby': error ? fieldId + '-error' : hint ? fieldId + '-hint' : undefined },
                    fieldId,
                    floated,
                    focused,
                    hasValue,
                    showPlaceholder: focused && !hasValue,
                    sizeClasses: (calendarStyle ? sizeClasses : legacySizeClasses)[size] || (calendarStyle ? sizeClasses.default : legacySizeClasses.default),
                    fieldShapeClass: calendarStyle ? 'rounded-lg' : 'rounded-ui',
                })}
            </div>
            {hint && <p id={fieldId + '-hint'} className="mt-1 text-xs text-gray-500 dark:text-gray-400">{hint}</p>}
            {error && <p id={fieldId + '-error'} className="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{error}</p>}
        </div>
    );
}
