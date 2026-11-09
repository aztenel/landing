import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

/*
 * Theme of the ERP panel (module «shell»). Filament preset + Prime Mobile tokens (../../tokens.css):
 * bg-background, text-muted-foreground, border-border, bg-primary (accent), text-ok / warn / bad …
 * Filament shades (bg-primary-600, text-gray-500) keep working — the accent fills both.
 * No color-mix / oklch / container queries: Chrome 109 (Windows 7) must render everything.
 */
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`

export default {
    presets: [preset],
    darkMode: 'class',
    content: [
        './app/Filament/**/*.php',
        './app/Livewire/**/*.php',
        './app/Support/Ui/**/*.php',
        './app/Domain/**/Enums/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
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
                'store-a': v('store-a'),
                'store-b': v('store-b'),
            },
            screens: {
                // TZ 04 §0.1: ≥ 1280 menu expanded, 1024–1279 icons, < 1024 iPhone layout.
                desk: '1024px',
                wide: '1280px',
                hd: '1440px',
            },
        },
    },
}
