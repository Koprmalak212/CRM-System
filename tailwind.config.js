// Tailwind build configuration. The palette lives in CSS variables (see theme_style() in includes/core/theme.php), so one build serves
// both the light and the dark theme. Build the stylesheet with:  npm run build:css
const v = (n) => `rgb(var(--c-${n}) / <alpha-value>)`;

module.exports = {
  content: [
    './index.php',
    './{auth,users,companies,tickets,calendar,calls,links,invoices,quotes,contracts,monitoring,system,search,notifications,settings,privacy,extras,api}/*.php',
    './includes/**/*.php',
    './assets/js/*.js',
  ],
  theme: {
    extend: {
      fontFamily: { sans: ['"Hanken Grotesk"', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
      colors: {
        navy: { DEFAULT: '#111D36', 800: '#18284A', 700: '#22355E', 600: '#334A7A' },
        lime: { DEFAULT: '#C7F36B', 600: '#A8D84A' },
        butter: { DEFAULT: '#FFF1A8', 50: 'var(--c-butter50)', 100: 'var(--c-butter100)' },
        ground: v('ground'), panel: v('panel'), raised: v('raised'), deep: v('deep'), line: v('line'), ink: v('ink'), soft: v('soft'),
        coral: '#F0654F',
      },
      boxShadow: { panel: 'var(--shadow-panel)' },
    },
  },
};
