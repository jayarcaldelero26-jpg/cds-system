import FloatingInput from './Form/FloatingInput';

export default function FormField({ label, error, hint, id, className = '', size = 'default', ...props }) {
    return <FloatingInput id={id} label={label} error={error} hint={hint} size={size} className={className} {...props} />;
}
