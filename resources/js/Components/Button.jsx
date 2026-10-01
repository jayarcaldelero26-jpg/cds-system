import { forwardRef } from 'react';

const actionGradient = 'bg-gradient-to-b from-green-800 to-green-950 text-white hover:from-green-700 hover:to-green-900';
const dangerGradient = 'bg-gradient-to-b from-red-700 to-red-400 text-white hover:from-red-800 hover:to-red-500';
const neutralAction = 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700';
const variants = {
    primary: actionGradient,
    secondary: `border border-green-950 ${actionGradient}`,
    danger: `border border-red-800 ${dangerGradient}`,
    warning: 'border border-orange-800 bg-gradient-to-b from-orange-600 to-orange-900 text-white hover:from-orange-500 hover:to-orange-800',
    ghost: actionGradient,
    cancel: neutralAction,
    back: neutralAction,
};
const sizes = {
    default: 'min-h-10 rounded-ui px-4 py-2 text-sm',
    compact: 'min-h-0',
};

const Button = forwardRef(function Button({ variant = 'primary', size = 'default', className = '', type = 'button', children, ...props }, ref) {
    const neutral = variant === 'cancel' || variant === 'back';
    return <button ref={ref} type={type} data-cds-action="true" data-cds-action-variant={variant} className={`cds-button-interaction inline-flex items-center justify-center font-semibold ${neutral ? '' : '!text-white'} transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-green-400 dark:focus-visible:ring-offset-gray-900 ${sizes[size] || sizes.default} ${variants[variant] || variants.primary} ${className}`} {...props}>{children}</button>;
});

export default Button;
