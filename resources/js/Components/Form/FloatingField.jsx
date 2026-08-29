import { useEffect, useId, useRef, useState } from 'react';

const sizeClasses = {
    default: 'min-h-11 px-3 py-2.5 text-sm leading-5 font-normal',
    sm: 'min-h-10 px-3 py-2 text-sm leading-5 font-normal',
};

const legacySizeClasses = {
    default: 'min-h-11 px-3 py-2.5 text-sm',
    sm: 'min-h-9 px-2.5 py-1.5 text-xs',
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

export default function FloatingField({ id, label, value, defaultValue, error, hint, size = 'default', disabled = false, readOnly = false, required = false, className = '', children, alwaysFloat = false, multiline = false, focusTone = 'green', variant = 'calendar' }) {
    const generatedId = useId();
    const fieldId = id || `field-${generatedId.replace(/:/g, '')}`;
    const { floated, focused, hasValue, setFocused, setNativeHasValue, ref } = useFloatingValue({ value, defaultValue, alwaysFloat });
    const invalid = Boolean(error);
    const calendarStyle = variant !== 'legacy';
    const background = disabled || readOnly ? 'bg-gray-50 dark:bg-gray-800' : 'bg-white dark:bg-gray-900';
    const focusBorder = focusTone === 'green' ? 'border-green-700 dark:border-green-500' : 'border-blue-600';
    const focusText = focusTone === 'green' ? 'text-green-700 dark:text-green-400' : 'text-blue-600 dark:text-blue-400';
    const border = invalid
        ? 'border-red-500'
        : focused
            ? focusBorder
            : 'border-gray-300 dark:border-gray-600';
    const labelColor = invalid ? 'text-red-600 dark:text-red-400' : focused ? focusText : 'text-gray-600 dark:text-gray-300';
    const labelPosition = floated ? `left-2 top-0 -translate-y-1/2 px-1 text-xs${calendarStyle ? ' leading-4' : ''}` : multiline ? `left-3 top-3 text-sm${calendarStyle ? ' leading-5' : ''}` : `left-3 top-1/2 -translate-y-1/2 text-sm${calendarStyle ? ' leading-5' : ''}`;
    const nativeProps = { id: fieldId, disabled, readOnly, required, 'aria-invalid': invalid || undefined, 'aria-describedby': error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined };
    return <div className={`relative ${className}`} onFocus={() => setFocused(true)} onAnimationStart={(event) => event.animationName === 'floating-field-autofill' && setNativeHasValue(event.target?.value !== undefined && event.target.value !== '')} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false); }}>
        <div className={`relative border shadow-none transition-colors duration-150 ${calendarStyle ? 'rounded-lg focus-within:ring-1 focus-within:ring-green-700/20 dark:focus-within:ring-green-500/25' : 'rounded-ui'} ${background} ${border}`}>
            {children({
                ref,
                nativeProps,
                fieldId,
                floated,
                focused,
                hasValue,
                showPlaceholder: focused && !hasValue,
                sizeClasses: (calendarStyle ? sizeClasses : legacySizeClasses)[size] || (calendarStyle ? sizeClasses.default : legacySizeClasses.default),
                fieldShapeClass: calendarStyle ? 'rounded-lg' : 'rounded-ui',
            })}
            <label htmlFor={fieldId} className={`pointer-events-none absolute z-10 origin-left whitespace-nowrap font-medium transition-all duration-150 ${background} ${labelPosition} ${labelColor}`}>{label}{required && <span className={`ml-0.5 text-red-500${calendarStyle ? ' text-xs leading-4 dark:text-red-400' : ''}`}>*</span>}</label>
        </div>
        {hint && <p id={`${fieldId}-hint`} className="mt-1 text-xs text-gray-500 dark:text-gray-400">{hint}</p>}
        {error && <p id={`${fieldId}-error`} className="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{error}</p>}
    </div>;
}
