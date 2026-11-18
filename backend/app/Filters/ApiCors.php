<?php

namespace App\Filters;

use App\Libraries\Http;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Florist;

/**
 * Lets the website (example.com) and the dashboard (admin.example.com) call this API (api.example.com) from the
 * browser, with the login cookies, when florist.separateDomains is on. Only the origins in
 * Config\Florist::allowedOrigins() get access; every other site is refused by the browser as before.
 *
 * A required filter: before() answers the browser's preflight (OPTIONS) before routing, and SecurityHeaders::apply()
 * calls headers() on every answer, also on errors and early answers (401, 419, 429 …), so the React apps can always
 * read the {"error": "..."} message.
 */
class ApiCors implements FilterInterface
{
    public const METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    public const HEADERS = 'Accept, Content-Type, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN';

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest || $request->getMethod() !== 'OPTIONS'
            || ! $request->hasHeader('Access-Control-Request-Method') || self::allowedOrigin($request) === null) {
            return null;
        }

        // The preflight: SecurityHeaders adds Access-Control-Allow-Origin/-Credentials on the way out.
        return Http::cacheControl(service('response'), Http::NO_CACHE)
            ->setStatusCode(204)
            ->setHeader('Access-Control-Allow-Methods', self::METHODS)
            ->setHeader('Access-Control-Allow-Headers', self::HEADERS)
            ->setHeader('Access-Control-Max-Age', '7200')
            ->setBody('');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /** Adds the CORS headers when the request comes from one of the shop's own sites. */
    public static function headers(IncomingRequest $request, ResponseInterface $response): void
    {
        if (! config(Florist::class)->separateDomains || ! self::isApi($request)) {
            return;
        }
        // Answers differ per Origin, so caches must keep them apart.
        $response->appendHeader('Vary', 'Origin');
        $origin = self::allowedOrigin($request);
        if ($origin !== null) {
            $response->setHeader('Access-Control-Allow-Origin', $origin);
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    /** The request's Origin header when it is one of the shop's own sites, else null. */
    public static function allowedOrigin(IncomingRequest $request): ?string
    {
        $origin = rtrim(strtolower($request->getHeaderLine('Origin')), '/');
        if ($origin === '' || ! self::isApi($request)) {
            return null;
        }

        return in_array($origin, config(Florist::class)->allowedOrigins(), true) ? $origin : null;
    }

    private static function isApi(IncomingRequest $request): bool
    {
        $path = trim($request->getPath(), '/');

        return $path === 'api' || str_starts_with($path, 'api/');
    }
}
