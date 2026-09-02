import { Icon } from '@iconify/react';
import Input from './Input';
import FloatingSelect from './Form/FloatingSelect';

function FieldIcon({ icon }) {
    return <Icon icon={'lucide:' + icon} width="18" height="18" aria-hidden="true" />;
}

export function AuthField({ icon, error, children, ...props }) {
    const className = children ? '[&>div>input]:pr-11' : '';

    return (
        <div className="relative">
            <Input {...props} error={error} className={className} leadingIcon={<FieldIcon icon={icon} />} focusTone="blue" showFocusRing={false} />
            {children && <span className="absolute right-2 top-6 z-20 flex h-11 items-center">{children}</span>}
        </div>
    );
}

export function AuthSelect({ icon, error, ...props }) {
    return <FloatingSelect {...props} error={error} leadingIcon={<FieldIcon icon={icon} />} hideEmptyBlurredValue focusTone="blue" showFocusRing={false} />;
}

export default AuthField;
