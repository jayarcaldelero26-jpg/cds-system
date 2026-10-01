import { Children, Fragment, isValidElement, useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import FloatingField from './FloatingField';
import { setNativeSelectValueAndDispatch } from './nativeSelectChange.mjs';

function optionText(children) {
    return Children.toArray(children).map(child => {
        if (typeof child === 'string' || typeof child === 'number') return String(child);
        return isValidElement(child) ? optionText(child.props.children) : '';
    }).join('');
}

function getOptions(children) {
    const options = [];
    const visit = (nodes, groupDisabled = false) => Children.forEach(nodes, child => {
        if (!isValidElement(child)) return;
        if (child.type === Fragment) return visit(child.props.children, groupDisabled);
        if (child.type === 'option') options.push({ value: String(child.props.value ?? optionText(child.props.children)), label: child.props.children, disabled: groupDisabled || Boolean(child.props.disabled), key: child.key ?? `${options.length}` });
        else if (child.type === 'optgroup') visit(child.props.children, groupDisabled || Boolean(child.props.disabled));
    });
    visit(children);
    return options;
}

export default function FloatingSelect({
    label, error, hint, size = 'default', focusTone, variant = 'calendar', className = '', leadingIcon = null,
    hideEmptyBlurredValue = false, showFocusRing = true, children, value, defaultValue, onChange, 'aria-label': ariaLabel, ...props
}) {
    const generatedId = useId().replace(/:/g, '');
    const fieldId = props.id || `floating-select-${generatedId}`;
    const controlled = value !== undefined;
    const [uncontrolledValue, setUncontrolledValue] = useState(String(defaultValue ?? ''));
    const selectedValue = String(controlled ? value ?? '' : uncontrolledValue);
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const [menuStyle, setMenuStyle] = useState(null);
    const selectRef = useRef(null);
    const triggerRef = useRef(null);
    const menuRef = useRef(null);
    const options = useMemo(() => getOptions(children), [children]);
    const selectedOption = options.find(option => option.value === selectedValue);
    const placeholder = options.find(option => option.value === '' && !option.disabled);
    const activeOption = options[activeIndex];
    const { id: _id, name, required, disabled, readOnly, form, autoComplete, ...nativeAttributes } = props;
    const isDisabled = Boolean(disabled || readOnly);

    const positionMenu = () => {
        const trigger = triggerRef.current;
        if (!trigger) return;
        const rect = trigger.getBoundingClientRect();
        const margin = 8;
        const availableBelow = window.innerHeight - rect.bottom - margin;
        const availableAbove = rect.top - margin;
        const placeAbove = availableBelow < 176 && availableAbove > availableBelow;
        const maxHeight = Math.max(112, Math.min(288, placeAbove ? availableAbove : availableBelow));
        setMenuStyle({ position: 'fixed', left: Math.max(margin, Math.min(rect.left, window.innerWidth - rect.width - margin)), width: Math.min(rect.width, window.innerWidth - margin * 2), maxHeight, ...(placeAbove ? { bottom: window.innerHeight - rect.top + 4 } : { top: rect.bottom + 4 }) });
    };
    useEffect(() => {
        if (!open) return undefined;
        positionMenu();
        const reposition = () => positionMenu();
        const outside = event => { if (!triggerRef.current?.contains(event.target) && !menuRef.current?.contains(event.target)) closeMenu(false); };
        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
        document.addEventListener('pointerdown', outside, true);
        return () => { window.removeEventListener('resize', reposition); window.removeEventListener('scroll', reposition, true); document.removeEventListener('pointerdown', outside, true); };
    }, [open]);
    useEffect(() => {
        if (value !== undefined) return;
        const ownerForm = selectRef.current?.form;
        if (!ownerForm) return undefined;
        const reset = () => window.setTimeout(() => setUncontrolledValue(String(selectRef.current?.value ?? defaultValue ?? '')), 0);
        ownerForm.addEventListener('reset', reset);
        return () => ownerForm.removeEventListener('reset', reset);
    }, [value, defaultValue]);

    const closeMenu = (returnFocus = true) => {
        setOpen(false);
        if (returnFocus) requestAnimationFrame(() => triggerRef.current?.focus());
    };
    const choose = option => {
        if (!option || option.disabled) return;
        if (!controlled) setUncontrolledValue(option.value);
        setNativeSelectValueAndDispatch(selectRef.current, option.value);
        closeMenu(true);
    };
    const openMenu = () => {
        if (isDisabled) return;
        const selectedIndex = options.findIndex(option => option.value === selectedValue && !option.disabled);
        setActiveIndex(selectedIndex >= 0 ? selectedIndex : Math.max(0, options.findIndex(option => !option.disabled)));
        setOpen(true);
    };
    const onKeyDown = event => {
        if (isDisabled) return;
        if (!open && ['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) { event.preventDefault(); openMenu(); return; }
        if (!open) return;
        if (event.key === 'Escape' || event.key === 'Tab') { if (event.key === 'Escape') event.preventDefault(); closeMenu(event.key === 'Escape'); return; }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); const direction = event.key === 'ArrowDown' ? 1 : -1; setActiveIndex(current => { let next = current; for (let tries = 0; tries < options.length; tries++) { next = (next + direction + options.length) % options.length; if (!options[next]?.disabled) return next; } return current; }); return; }
        if (event.key === 'Home' || event.key === 'End') { event.preventDefault(); const candidates = options.map((option, index) => option.disabled ? -1 : index).filter(index => index >= 0); setActiveIndex(event.key === 'Home' ? candidates[0] : candidates[candidates.length - 1]); return; }
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); choose(activeOption); }
    };

    return <FloatingField label={label} hideLabel={!label} value={selectedValue} defaultValue={defaultValue} error={error} hint={hint} size={size} focusTone={focusTone || (variant === 'legacy' ? 'blue' : 'green')} variant={variant} disabled={disabled} readOnly={readOnly} required={required} className={className} hasLeadingIcon={Boolean(leadingIcon)} showFocusRing={showFocusRing}>
        {({ nativeProps, sizeClasses, fieldShapeClass }) => {
            const selectClasses = ['absolute left-0 top-0 h-px w-px opacity-0', 'pointer-events-none'];
            const triggerClasses = ['flex w-full items-center justify-between gap-2 border-0 bg-transparent text-left outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-700 dark:focus-visible:ring-green-400', fieldShapeClass, sizeClasses, leadingIcon ? 'pl-10' : '', 'text-gray-800 dark:text-gray-100', isDisabled ? 'cursor-not-allowed text-gray-500 dark:text-gray-400' : 'cursor-pointer'];
            const hideEmpty = hideEmptyBlurredValue && !selectedValue;
            return <>
                {leadingIcon && <span className="pointer-events-none absolute inset-y-0 left-3 z-10 flex items-center text-emerald-700 dark:text-emerald-400">{leadingIcon}</span>}
                <button ref={triggerRef} id={fieldId} type="button" role="combobox" aria-label={ariaLabel || undefined} aria-haspopup="listbox" aria-expanded={open} aria-controls={`${fieldId}-listbox`} aria-activedescendant={open && activeOption ? `${fieldId}-option-${activeIndex}` : undefined} aria-required={required || undefined} aria-invalid={Boolean(error) || undefined} aria-describedby={error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined} disabled={isDisabled} onClick={() => open ? closeMenu(false) : openMenu()} onKeyDown={onKeyDown} className={triggerClasses.join(' ')}>
                    <span className={`min-w-0 flex-1 truncate ${hideEmpty ? 'text-transparent' : selectedOption ? 'text-gray-800 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'}`}>{selectedOption?.label ?? placeholder?.label ?? ''}</span>
                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.75" className={`h-4 w-4 shrink-0 text-gray-500 transition-transform dark:text-gray-400 ${open ? 'rotate-180 text-green-700 dark:text-green-300' : ''}`}><path d="m5 7.5 5 5 5-5" strokeLinecap="round" strokeLinejoin="round" /></svg>
                </button>
                <select {...nativeAttributes} id={`${fieldId}-native`} ref={selectRef} name={name} required={required} disabled={disabled} form={form} autoComplete={autoComplete} value={selectedValue} onChange={onChange} onInvalid={event => { event.preventDefault(); triggerRef.current?.focus(); openMenu(); }} aria-hidden="true" tabIndex={-1} className={selectClasses.join(' ')}>{children}</select>
                {open && typeof document !== 'undefined' && createPortal(<div ref={menuRef} id={`${fieldId}-listbox`} role="listbox" aria-labelledby={fieldId} className="cds-select-menu overflow-auto p-1.5" style={menuStyle}>
                    {options.map((option, index) => <div key={option.key} id={`${fieldId}-option-${index}`} role="option" aria-selected={option.value === selectedValue} aria-disabled={option.disabled || undefined} onMouseEnter={() => setActiveIndex(index)} onMouseDown={event => event.preventDefault()} onClick={() => choose(option)} className={`cds-select-option flex min-h-9 w-full items-center rounded-md px-3 py-2 text-left text-sm ${index === activeIndex ? 'cds-select-option-active' : ''} ${option.disabled ? 'cursor-not-allowed opacity-45' : 'cursor-pointer'}`}>{option.label}</div>)}
                </div>, document.body)}
            </>;
        }}
    </FloatingField>;
}
