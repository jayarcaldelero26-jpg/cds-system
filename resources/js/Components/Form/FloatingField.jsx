import { useEffect, useId, useRef, useState } from 'react';

const sizeClasses = {
    default: 'min-h-11 px-3 py-2.5 text-sm',
    sm: 'min-h-9 px-2.5 py-1.5 text-xs',
};

export function useFloatingValue({ value, defaultValue, alwaysFloat = false }) {
    const [focused, setFocused] = useState(false);
    const [autofilled, setAutofilled] = useState(false);
    const ref = useRef(null);
    const suppliedValue = value !== undefined ? value : defaultValue;
    const hasValue = alwaysFloat || autofilled || (suppliedValue !== undefined && suppliedValue !== null && String(suppliedValue).length > 0);

    useEffect(() => {
        const node = ref.current;
        if (!node) return undefined;
        const check = () => setAutofilled(Boolean(node.value));
        check();
        const timer = window.setTimeout(check, 150);
        return () => window.clearTimeout(timer);
    }, []);

    return { ref, focused, hasValue, setFocused, setAutofilled, floated: focused || hasValue };
}

export default function FloatingField({ id, label, value, defaultValue, error, hint, size = 'default', disabled = false, readOnly = false, required = false, className = '', children, alwaysFloat = false, multiline = false }) {
    const generatedId = useId();
    const fieldId = id || `field-${generatedId.replace(/:/g, '')}`;
    const { floated, focused, hasValue, setFocused, setAutofilled, ref } = useFloatingValue({ value, defaultValue, alwaysFloat });
    const invalid = Boolean(error);
    const background = disabled || readOnly ? 'bg-gray-50 dark:bg-gray-800' : 'bg-white dark:bg-gray-900';
    const border = invalid
        ? 'border-red-500'
        : focused
            ? 'border-blue-600'
            : 'border-gray-300 dark:border-gray-600';
    const labelColor = invalid ? 'text-red-600 dark:text-red-400' : focused ? 'text-blue-600 dark:text-blue-400' : 'text-gray-600 dark:text-gray-300';
    const labelPosition = floated ? 'left-2 top-0 -translate-y-1/2 px-1 text-xs' : multiline ? 'left-3 top-3 text-sm' : 'left-3 top-1/2 -translate-y-1/2 text-sm';
    const nativeProps = { id: fieldId, disabled, readOnly, required, 'aria-invalid': invalid || undefined, 'aria-describedby': error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined };
    return <div className={`relative ${className}`} onFocus={() => setFocused(true)} onAnimationStart={(event) => event.animationName === 'floating-field-autofill' && setAutofilled(true)} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false); }}>
        <div className={`relative rounded-ui border shadow-none transition-colors duration-150 ${background} ${border}`}>
            {children({
                ref,
                nativeProps,
                fieldId,
                floated,
                focused,
                hasValue,
                showPlaceholder: focused && !hasValue,
                sizeClasses: sizeClasses[size] || sizeClasses.default,
            })}
            <label htmlFor={fieldId} className={`pointer-events-none absolute z-10 origin-left whitespace-nowrap font-medium transition-all duration-150 ${background} ${labelPosition} ${labelColor}`}>{label}{required && <span className="ml-0.5 text-red-500">*</span>}</label>
        </div>
        {hint && <p id={`${fieldId}-hint`} className="mt-1 text-xs text-gray-500 dark:text-gray-400">{hint}</p>}
        {error && <p id={`${fieldId}-error`} className="mt-1 text-sm text-red-700 dark:text-red-300" role="alert">{error}</p>}
    </div>;
}
