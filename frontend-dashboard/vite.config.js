import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

// The owner dashboard.
//
// Live: https://admin.cityfloristhubli.com (public_html/admin/ on Hostinger), calling the API at
// https://api.cityfloristhubli.com. The addresses come from .env.production (VITE_ADMIN_BASE=/ → the build is a
// plain dist/index.html + dist/admin-assets/, uploaded as the whole admin subdomain).
//
// Development: http://localhost:5174/admin (or http://localhost:5173/admin, which the website's dev server
// passes through to here). The CodeIgniter backend runs on :8000
// (cd backend && php spark serve --host 127.0.0.1 --port 8000).
//
// With VITE_ADMIN_BASE=/admin (the dashboard under the website's own domain) the build is dist/admin.html instead,
// to sit next to the website's index.html.
const backend = 'http://127.0.0.1:8000';
const proxy = { '/api': backend, '/uploads': backend };

const PAGE = 'admin.html';

/** Writes the page as dist/admin.html when the dashboard lives under /admin, and serves /admin/... in dev/preview. */
function adminPage(underAdmin) {
  return {
    name: 'admin-page',
    enforce: 'post',
    generateBundle(_options, bundle) {
      const page = bundle['index.html'];
      if (underAdmin && page) {
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
        if (underAdmin && (path === '/admin' || (path.startsWith('/admin/') && !path.includes('.')))) req.url = '/' + PAGE;
        else if (!underAdmin && !path.includes('.') && !path.startsWith('/api')) req.url = '/index.html';
        next();
      });
    },
  };
}

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const underAdmin = (env.VITE_ADMIN_BASE ?? '/admin').replace(/\/+$/, '') === '/admin';

  return {
    plugins: [react(), adminPage(underAdmin)],
    // In dev everything lives under /admin/ so the website's dev server can pass /admin through to this one.
    // In the build, assets sit in /admin-assets/ at the site root.
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
  };
});
