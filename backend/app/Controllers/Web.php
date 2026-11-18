<?php

namespace App\Controllers;

use App\Libraries\Catalog;
use App\Libraries\Http;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Florist;

/**
 * The React apps (built from frontend-user/ and frontend-dashboard/ into public/) and the sitemap. On Hostinger,
 * public/.htaccess serves the HTML files directly; these cover `php spark serve` and any server without those rules.
 * No session is started here, so page views don't create sessions.
 *
 * @property IncomingRequest $request
 */
class Web extends Controller
{
    /** GET / : the public website (frontend-user → public/index.html). */
    public function index(): ResponseInterface
    {
        return $this->page('index.html');
    }

    /** GET /admin and /admin/… : the owner dashboard (frontend-dashboard → public/admin.html). */
    public function admin(): ResponseInterface
    {
        $florist = config(Florist::class);
        if ($florist->separateDomains) {
            // The dashboard lives on its own subdomain: /admin/orders → https://admin.example.com/orders
            $rest = preg_replace('#^admin/?#', '', trim($this->request->getPath(), '/'));

            return Http::redirect($florist->adminPage((string) $rest));
        }

        return $this->page('admin.html');
    }

    /** GET /up : health check for uptime monitors (the same page the Laravel version showed). */
    public function health(): ResponseInterface
    {
        $started = defined('CI_START') ? CI_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $html    = (string) file_get_contents(APPPATH . 'Views/pages/up.html');
        $html    = str_replace('{{ms}}', (string) max(0, (int) round((microtime(true) - (float) $started) * 1000)), $html);

        return Http::cacheControl($this->response, Http::NO_CACHE)
            ->setStatusCode(200)
            ->setContentType('text/html', 'utf-8')
            ->setBody($html);
    }

    /** GET /sitemap.xml : every public page for search engines (home, shop, each category and each live product). Always current. */
    public function sitemap(): ResponseInterface
    {
        $base = rtrim(config(Florist::class)->siteUrl, '/');
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_XML1);
        $urls = [[$base . '/', 'daily', '1.0'], [$base . '/shop', 'daily', '0.9'], [$base . '/privacy', 'yearly', '0.2'], [$base . '/terms', 'yearly', '0.2']];
        foreach (Catalog::publicCategories() as $c) {
            $urls[] = [$base . '/shop?cat=' . rawurlencode($c['slug']), 'weekly', '0.8'];
        }
        foreach (Catalog::allProducts() as $p) {
            $urls[] = [$base . '/product/' . rawurlencode($p['slug']), 'weekly', '0.7'];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $freq, $priority]) {
            $xml .= "  <url><loc>{$e($loc)}</loc><changefreq>{$freq}</changefreq><priority>{$priority}</priority></url>\n";
        }

        return Http::cacheControl($this->response, 'max-age=3600, public')
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'application/xml; charset=utf-8')
            ->setBody($xml . '</urlset>' . "\n");
    }

    /**
     * Every URL no route matched (Config\Routing::$override404), answered like the Laravel version, whose catch-all
     * route accepted GET for any path:
     *  - GET under /api: {"error": "Not found."} (404); under /uploads: a 404 page (a missing photo is never
     *    answered with the website); anything else is a React route of the public website, so it gets index.html;
     *  - OPTIONS: 200 with the Allow header;
     *  - any other method: 405 Method not allowed (JSON under /api).
     */
    public function notFound(?string $message = null): ResponseInterface
    {
        $path   = trim($this->request->getPath(), '/');
        $method = $this->request->getMethod();
        $api    = $path === 'api' || str_starts_with($path, 'api/');

        if ($method === 'GET' || $method === 'HEAD') {
            if ($api) {
                return Http::earlyError(Http::messageFor(404), 404);
            }
            if ($path === 'uploads' || str_starts_with($path, 'uploads/')) {
                return Http::cacheControl($this->response, Http::NO_CACHE)
                    ->setStatusCode(404)
                    ->setContentType('text/html', 'utf-8')
                    ->setBody((string) file_get_contents(APPPATH . 'Views/pages/not_found.html'));
            }

            return $this->page('index.html');
        }

        $allowed = array_values(array_unique(array_merge(['GET', 'HEAD'], $this->routeMethods($path))));
        if ($method === 'OPTIONS') {
            return Http::cacheControl($this->response, Http::NO_CACHE)
                ->setStatusCode(200)
                ->setHeader('Allow', implode(',', $allowed))
                ->setBody('');
        }
        if ($api) {
            return Http::earlyError(Http::messageFor(405), 405)->setHeader('Allow', implode(', ', $allowed));
        }

        return Http::cacheControl($this->response, Http::NO_CACHE)
            ->setStatusCode(405)
            ->setHeader('Allow', implode(', ', $allowed))
            ->setContentType('text/plain', 'UTF-8')
            ->setBody("Method not allowed.\n");
    }

    private function page(string $file): ResponseInterface
    {
        $florist = config(Florist::class);
        if ($florist->separateDomains) {
            // This domain only answers the API (api.example.com); the website is on florist.siteUrl.
            return Http::redirect($florist->websitePage('/' . ltrim($this->request->getPath(), '/')));
        }
        $path = rtrim($florist->publicPath, '/\\') . DIRECTORY_SEPARATOR . $file;
        if (! is_file($path)) {
            return Http::cacheControl($this->response, Http::NO_CACHE)
                ->setStatusCode(404)
                ->setContentType('text/plain', 'utf-8')
                ->setBody(
                    "The website hasn't been built into backend/public yet.\n"
                    . "During development open the Vite site instead: cd frontend-user && npm run dev → http://localhost:5173\n",
                );
        }

        return Http::cacheControl($this->response, Http::NO_CACHE)
            ->setStatusCode(200)
            ->setContentType('text/html', 'utf-8')
            ->setBody((string) file_get_contents($path));
    }

    /**
     * The HTTP methods the app's own routes accept for this path.
     *
     * @return list<string>
     */
    private function routeMethods(string $path): array
    {
        $routes  = service('routes');
        $allowed = [];
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb, false)) as $from) {
                if (preg_match('#^' . $from . '$#u', $path) === 1) {
                    $allowed[] = $verb;
                    break;
                }
            }
        }

        return $allowed;
    }
}
