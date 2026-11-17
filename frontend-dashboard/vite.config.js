import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The owner dashboard, served at /admin on the same domain as the website (it uses the same login cookies).
//
// Development: http://localhost:5174/admin (or http://localhost:5173/admin, which the website's dev server
// passes through to here). The CodeIgniter backend runs on :8000
// (cd backend && php spark serve --host 127.0.0.1 --port 8000).
//
// Build: dist/admin.html + dist/admin-assets/. The deploy copies both into backend/public/ next to the
// website's index.html and assets/, so the two apps never overwrite each other's files. The page is
// admin.html, not admin/index.html: with a real admin/ folder, PHP's built-in server (php spark serve)
// would answer /admin itself instead of handing it to CodeIgniter.
const backend = 'http://127.0.0.1:8000';
const proxy = { '/api': backend, '/uploads': backend };

const PAGE = 'admin.html';

/** Writes the page as dist/admin.html, and serves it for every /admin/... URL in `vite preview`. */
function adminPage() {
  return {
    name: 'admin-page',
    enforce: 'post',
    generateBundle(_options, bundle) {
      const page = bundle['index.html'];
      if (page) {
        page.fileName = PAGE;
        bundle[PAGE] = page;
        delete bundle['index.html'];
      }
    },
    // Vite only answers its base with the slash ("/admin/"); let "/admin" open the dashboard too.
    configureServer(server) {
      server.middlewares.use((req, _res, next) => {
        if (req.url === '/admin' || req.url.startsWith('/admin?')) req.url = '/admin/' + req.url.slice(6);
        next();
      });
    },
    configurePreviewServer(server) {
      server.middlewares.use((req, _res, next) => {
        const path = req.url.split('?')[0];
        if (path === '/admin' || (path.startsWith('/admin/') && !path.includes('.'))) req.url = '/' + PAGE;
        next();
      });
    },
  };
}

export default defineConfig(({ command }) => ({
  plugins: [react(), adminPage()],
  // In dev everything lives under /admin/ so the website's dev server can pass /admin through to this one.
  // In the build, assets sit in /admin-assets/ at the site root (see above).
  base: command === 'serve' ? '/admin/' : '/',
  server: { port: 5174, strictPort: true, proxy },
  preview: { port: 4174, proxy },
  build: {
    outDir: 'dist',
    assetsDir: 'admin-assets',
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (/[\\/]node_modules[\\/](react|react-dom|react-router|react-router-dom|scheduler|cookie|set-cookie-parser)[\\/]/.test(id)) return 'react';
        },
      },
    },
  },
}));
