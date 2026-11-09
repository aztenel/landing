import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/*
 * Prime Mobile design tokens (see ../docs/design/src/erp.py).
 * Colors are RGB channels in CSS variables so opacity modifiers (bg-ok/10) work in Chrome 109.
 * Themes: :root (light), .dark, .accent-graphite (firm setting), .legacy (old laptop mode).
 */
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/**/*.php',
        './vendor/filament/**/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Onest', 'Segoe UI', 'Arial', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'Consolas', 'monospace'],
            },
            colors: {
                background: v('background'),
                foreground: v('foreground'),
                card: v('card'),
                primary: { DEFAULT: v('primary'), foreground: v('primary-foreground') },
                muted: { DEFAULT: v('muted'), foreground: v('muted-foreground') },
                border: v('border'),
                input: v('input'),
                ring: v('ring'),
                ok: v('ok'),
                warn: v('warn'),
                bad: v('bad'),
                photo: v('photo'),
                wa: '#1FA855',
            },
            borderRadius: { lg: '10px', xl: '14px', '2xl': '18px' },
        },
    },
    plugins: [forms, typography],
};
