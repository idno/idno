import { defineConfig } from 'vite';
import { resolve } from 'path';

// Builds Idno 2026's script and style sheet into the project's own js/ and css/ directories:
// js/idno-2026.min.js (with its source map) and css/idno-2026.min.css. outDir is the project root, so it is never emptied.
export default defineConfig({
  build: {
    outDir: '..',
    emptyOutDir: false,
    rollupOptions: {
      input: {
        'idno-2026': resolve(__dirname, 'src/js/main.js'),
      },
      output: {
        entryFileNames: 'js/[name].min.js',
        assetFileNames: 'css/[name].min[extname]',
      },
    },
    cssMinify: true,
    minify: 'terser',
    sourcemap: true,
  },
  css: {
    postcss: './postcss.config.js',
  },
});
