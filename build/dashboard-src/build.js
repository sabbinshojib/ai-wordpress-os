/**
 * esbuild bundler for the AI WordPress OS admin console.
 * Output: ../../assets/js/admin-dashboard.js (self-contained IIFE).
 */
const esbuild = require('esbuild');
const path = require('path');

const isWatch = process.argv.includes('--watch');

const options = {
  entryPoints: [path.join(__dirname, 'src', 'app.jsx')],
  bundle: true,
  format: 'iife',
  target: ['es2020'],
  outfile: path.join(__dirname, '..', '..', 'assets', 'js', 'admin-dashboard.js'),
  minify: !isWatch,
  sourcemap: false,
  jsx: 'automatic',
  define: {
    'process.env.NODE_ENV': '"production"',
  },
  banner: {
    js: '/* AI WordPress OS admin console — built with esbuild */',
  },
  logLevel: 'info',
};

(async () => {
  if (isWatch) {
    const ctx = await esbuild.context(options);
    await ctx.watch();
  } else {
    await esbuild.build(options);
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
