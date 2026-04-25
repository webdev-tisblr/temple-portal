/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.js',
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
  ],
  theme: {
    extend: {
      colors: {
        // ── Saffron / Kesari (primary) — matches AppColors ──────────────
        saffron: {
          50:  '#FDF3E8',
          100: '#FAE1C3',
          200: '#F4C994',
          300: '#ECA557',
          400: '#E8751A', // primary
          500: '#C45F12',
          600: '#9C480B',
          700: '#7C3608',
          800: '#5D2906',
          900: '#3F1B04',
        },
        // ── Maroon / Sindoor (sacred ink) ────────────────────────────────
        maroon: {
          50:  '#FBE6E6',
          100: '#F4C2C2',
          200: '#E59292',
          300: '#C75959',
          400: '#A83232',
          500: '#7A1E1E', // sacred
          600: '#5A1414',
          700: '#3E0C0C',
          900: '#1F0606',
        },
        // ── Gold / Haldi (accent) ────────────────────────────────────────
        gold: {
          50:  '#FBF1D7',
          100: '#F5E2A8',
          200: '#ECD08A',
          300: '#D8B45E',
          400: '#C89434', // accent
          500: '#A67622',
          600: '#7E5816',
          700: '#5A3E0F',
        },
        // ── Parchment (warm light surfaces) ──────────────────────────────
        parch: {
          0:   '#FFFCF5',
          50:  '#FBF5EA',
          100: '#F4EAD5',
          200: '#E8D9B8',
          300: '#D4BF91',
        },
        // ── Stone (neutrals / ink) ───────────────────────────────────────
        stone: {
          400: '#8A7860',
          500: '#5E4F3D',
          600: '#3E3226',
          700: '#2A1810',
        },
        // ── Dark theme surfaces ──────────────────────────────────────────
        nightInk: {
          DEFAULT: '#1A0F08',
          deeper:  '#120902',
          elev:    '#241710',
        },
      },
      fontFamily: {
        sans: ['"Hind Vadodara"', '"Noto Sans Gujarati"', '"Noto Sans Devanagari"', '"Noto Sans"', 'sans-serif'],
        serif: ['"Noto Serif Gujarati"', '"Noto Serif Devanagari"', 'serif'],
        display: ['"Noto Serif Gujarati"', 'serif'],
      },
      boxShadow: {
        'sacred': '0 4px 20px rgba(0, 0, 0, 0.3)',
        'sacred-hover': '0 8px 40px rgba(196, 154, 42, 0.10), 0 4px 20px rgba(0, 0, 0, 0.40)',
        'parch': '0 2px 12px rgba(0, 0, 0, 0.06)',
        'parch-hover': '0 6px 24px rgba(122, 30, 30, 0.10)',
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
    require('@tailwindcss/forms'),
  ],
}
