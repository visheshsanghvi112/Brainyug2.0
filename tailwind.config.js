import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: 'rgb(var(--color-primary) / <alpha-value>)',
                'primary-600': 'rgb(var(--color-primary-600) / <alpha-value>)',
                muted: 'rgb(var(--color-muted) / <alpha-value>)',
                surface: 'rgb(var(--color-surface) / <alpha-value>)',
            },
        },
    },

    plugins: [forms],
};
