import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#f0fdf4',
                    100: '#dcfce7',
                    500: '#16a34a',
                    600: '#15803d',
                    700: '#166534',
                    800: '#14532d',
                    900: '#052e16',
                },
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            borderRadius: {
                ui: '0.625rem',
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(15 23 42 / 0.05)',
            },
            spacing: {
                18: '4.5rem',
                22: '5.5rem',
            },
        },
    },

    plugins: [forms],
};
