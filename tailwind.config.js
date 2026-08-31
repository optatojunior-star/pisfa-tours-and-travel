import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * PISFA design tokens.
 *
 * The palette is generated from the logo by deploy/make-brand-palette.php and
 * pasted in below. Regenerate it rather than hand-editing, so the values stay
 * traceable to the artwork.
 */
const brand = {
    50: '#f0faf9', 100: '#e0f5f3', 200: '#b3f9f2', 300: '#7af5e8',
    400: '#38f0dd', 500: '#08a090', 600: '#07887a', 700: '#066f64',
    800: '#04574e', 900: '#03443d', 950: '#022b27',
};

const accent = {
    50: '#faf3f0', 100: '#f5e8e0', 200: '#f8cdb4', 300: '#f3a77d',
    400: '#ec7b3b', 500: '#f07028', 600: '#ee6011', 700: '#d6570f',
    800: '#be4d0d', 900: '#ab450c', 950: '#933c0a',
};

const ink = {
    50: '#f9fafb', 100: '#f3f5f6', 200: '#e1e6ea', 300: '#bdccdb',
    400: '#829eba', 500: '#56789a', 600: '#45617d', 700: '#374d62',
    800: '#2c3d4f', 900: '#212e3b', 950: '#141c24',
};

/**
 * Contrast-corrective aliases.
 *
 * The application was built against emerald/amber/slate before the brand
 * existed, and those names appear in roughly five thousand class attributes.
 * Rather than rewrite every view — a large, risky, untestable diff — the old
 * names are redefined to point at the brand scales.
 *
 * The mapping is deliberately NOT one-to-one in the middle of the range. Text
 * sits at 600-800, and neither emerald-600 (3.77:1) nor the brand colour itself
 * (3.26:1) reaches the 4.5:1 that WCAG AA requires. Each mid step therefore
 * maps one or two stops darker, which is what actually fixes the contrast
 * complaints. The light end is decorative and maps straight across.
 */
const emerald = {
    50: brand[50], 100: brand[100], 200: brand[200], 300: brand[300],
    400: brand[500],   // was a mid-tone fill; brand value is the right weight
    500: brand[600],
    600: brand[700],   // 3.77:1 -> 6.06:1
    700: brand[800],   // 5.05:1 -> 8.47:1
    800: brand[800],
    900: brand[900], 950: brand[950],
};

const amber = {
    50: accent[50], 100: accent[100], 200: accent[200], 300: accent[300],
    400: accent[500],  // the logo orange, for large fills on dark grounds
    500: accent[600],
    600: accent[700],
    700: accent[800],  // first AA-safe step for text on white
    800: accent[900],
    900: accent[950], 950: accent[950],
};

export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                brand,
                accent,
                ink,

                // Legacy names, remapped. New work should use brand/accent/ink.
                emerald,
                amber,
                slate: ink,
                stone: ink,
            },

            fontFamily: {
                // Inter is self-hosted in resources/css/fonts.css, so the site
                // renders identically offline and on a first paint. The stack
                // behind it matters: without a real fallback the previous
                // configuration silently fell back to whatever the OS provided,
                // which is why type looked different on every machine.
                sans: ['Inter', 'Inter var', ...defaultTheme.fontFamily.sans],
            },

            // A single spacing rhythm. Sections, cards and stacks pick from
            // these rather than inventing one-off values, which is what made
            // spacing look uneven.
            spacing: {
                'section-y': '5rem',
                'section-y-lg': '7rem',
                'gutter': '1rem',
                'gutter-lg': '2rem',
            },

            /*
             * Three radii, not six.
             *
             * The views had reached for sm, md, lg, xl, 2xl and 3xl more or less
             * at random - 1334 rounded-xl against 446 rounded-3xl and 204
             * rounded-2xl - so corners disagreed between neighbouring cards.
             *
             * Rather than rewrite two thousand class attributes, the stock names
             * are collapsed onto a three-step rhythm: controls, the default, and
             * panels. Existing markup keeps working and starts agreeing with
             * itself. New work should use control/card, which say what they are
             * for.
             */
            borderRadius: {
                control: '0.625rem',   // inputs, buttons, chips
                DEFAULT: '0.625rem',
                card: '1rem',          // cards, panels, dialogs

                sm: '0.375rem',
                md: '0.625rem',
                lg: '0.625rem',
                xl: '0.875rem',
                '2xl': '1rem',
                '3xl': '1rem',
            },

            maxWidth: {
                prose: '68ch',
            },
        },
    },

    plugins: [forms],
};
