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
        saffron: {
          50: '#FFF7ED', 100: '#FFEDD5', 200: '#FED7AA', 300: '#FDBA74',
          400: '#FB923C', 500: '#F97316', 600: '#EA580C', 700: '#C2410C',
          800: '#9A3412', 900: '#7C2D12',
        },
        maroon: {
          50: '#FDF2F8', 100: '#FCE7F3', 200: '#FBCFE8',
          500: '#881337', 700: '#5B0F2A', 900: '#3B0718',
        },
      },
      fontFamily: {
        sans: ['"Noto Sans Gujarati"', '"Noto Sans Devanagari"', '"Noto Sans"', 'sans-serif'],
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
    require('@tailwindcss/forms'),
  ],
}
