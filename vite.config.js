import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: './',
  plugins: [react()],
  build: {
    outDir: 'htdocs/public',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/tokens.jsx',
      output: {
        entryFileNames: 'assets/tokens.js',
        chunkFileNames: 'assets/[name].js',
        assetFileNames: assetInfo => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'assets/tokens.css';
          }
          return 'assets/[name][extname]';
        },
      },
    },
  },
});
