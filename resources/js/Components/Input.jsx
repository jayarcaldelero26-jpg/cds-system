import FloatingInput from './Form/FloatingInput';

export default function Input({ label, hint, className = '', error = false, ...props }) {
    const resolvedLabel = label || props['aria-label'] || props.placeholder || props.name || props.id || 'Field';
    return <FloatingInput label={resolvedLabel} hint={hint} error={error} className={className} {...props} />;
}
