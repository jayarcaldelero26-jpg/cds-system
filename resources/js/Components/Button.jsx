import { forwardRef } from 'react';

const actionGradient = 'bg-gradient-to-b from-green-800 to-green-950 text-white hover:from-green-700 hover:to-green-900';
const secondaryAction = 'border border-green-700 bg-white text-green-900 hover:bg-green-50 dark:border-green-700 dark:bg-gray-900 dark:text-green-200 dark:hover:bg-gray-800';
const dangerGradient = 'bg-gradient-to-b from-red-800 to-red-950 text-white hover:from-red-900 hover:to-red-950';
const neutralAction = 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700';
const ghostAction = 'border border-transparent bg-transparent text-green-800 hover:bg-green-50 hover:text-green-950 dark:text-green-200 dark:hover:bg-green-950/40 dark:hover:text-green-100';
const variants = {
    primary: actionGradient,
    secondary: secondaryAction,
    danger: `border border-red-800 ${dangerGradient}`,
    warning: 'border border-orange-900 bg-gradient-to-b from-orange-700 to-orange-900 text-white hover:from-orange-800 hover:to-orange-950',
    ghost: ghostAction,
    cancel: neutralAction,
    back: neutralAction,
};
const sizes = {
    default: 'min-h-10 rounded-ui px-4 py-2 text-sm',
    prominent: 'min-h-11 rounded-ui px-4 py-2.5 text-sm',
    compact: 'min-h-8 rounded-ui px-2.5 py-1 text-xs',
};

const Button = forwardRef(function Button({ variant = 'primary', size = 'default', className = '', type = 'button', children, ...props }, ref) {
    const neutral = ['secondary', 'ghost', 'cancel', 'back'].includes(variant);
    return <button ref={ref} type={type} data-cds-action="true" data-cds-action-variant={variant} data-cds-size={size} className={`cds-button-interaction inline-flex items-center justify-center gap-2 font-semibold ${neutral ? '' : '!text-white'} transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-green-400 dark:focus-visible:ring-offset-gray-900 ${sizes[size] || sizes.default} ${variants[variant] || variants.primary} ${className}`} {...props}>{children}</button>;
});

export default Button;
