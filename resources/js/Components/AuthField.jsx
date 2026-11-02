import { Icon } from '@iconify/react';
import Input from './Input';
import FloatingSelect from './Form/FloatingSelect';

function FieldIcon({ icon }) {
    return <Icon icon={'lucide:' + icon} width="18" height="18" aria-hidden="true" />;
}

export function PasswordVisibilityToggle({ visible, onToggle }) {
    return <button
        type="button"
        onClick={onToggle}
        aria-label={visible ? 'Hide password' : 'Show password'}
        aria-pressed={visible}
        title={visible ? 'Hide password' : 'Show password'}
        className="inline-flex h-9 w-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-1 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white dark:focus-visible:ring-offset-gray-900"
    >
        <Icon icon={visible ? 'lucide:eye-off' : 'lucide:eye'} width="17" height="17" aria-hidden="true" />
    </button>;
}

export function AuthField({ icon, error, children, ...props }) {
    return (
        <Input {...props} error={error} leadingIcon={<FieldIcon icon={icon} />} trailingAdornment={children} focusTone="blue" showFocusRing={false} />
    );
}

export function AuthSelect({ icon, error, ...props }) {
    return <FloatingSelect {...props} error={error} leadingIcon={<FieldIcon icon={icon} />} hideEmptyBlurredValue focusTone="blue" showFocusRing={false} />;
}

export default AuthField;
