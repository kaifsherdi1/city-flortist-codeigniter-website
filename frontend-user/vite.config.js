import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The public website, served at /. The owner dashboard is its own app in ../frontend-dashboard (served at /admin).
//
// Development: the CodeIgniter backend runs on :8000 (cd backend && php spark serve --host 127.0.0.1 --port 8000)
// and the dashboard's dev server on :5174 (cd frontend-dashboard && npm run dev). /admin is passed through to it,
// so the whole site, dashboard included, opens at http://localhost:5173 just like on the live server.
const backend = 'http://127.0.0.1:8000';
const dashboard = 'http://localhost:5174';
const proxy = {
  '/api': backend,
  '/uploads': backend,
  '/sitemap.xml': backend,
  '/admin': { target: dashboard, ws: true }, // ws: the dashboard's live reload
};

export default defineConfig({
  plugins: [react()],
  server: { port: 5173, proxy },
  preview: { proxy: { '/api': backend, '/uploads': backend, '/sitemap.xml': backend } },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    chunkSizeWarningLimit: 1200,
    rollupOptions: {
      output: {
        // No manual chunk for three.js: it was pulling React's scheduler into the three chunk, so the
        // home page had to download and parse all of three (~250 KB gzipped) before showing anything.
        // Left alone, Vite puts it in the lazily loaded PetalScene chunk.
        // React and the animation libraries get their own chunks so they cache separately from the site code.
        manualChunks(id) {
          if (/[\\/]node_modules[\\/](react|react-dom|react-router|react-router-dom|scheduler|cookie|set-cookie-parser)[\\/]/.test(id)) return 'react';
          if (/[\\/]node_modules[\\/](gsap|@gsap|lenis)[\\/]/.test(id)) return 'motion';
        },
      },
    },
  },
});
