<?php

namespace App\Filters;

use App\Libraries\Auth\SessionState;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Browser security headers on everything CodeIgniter answers (a required filter, so 404s and early answers get
 * them too). public/.htaccess sets the same ones for files Apache serves directly on Hostinger; this covers
 * `php spark serve` and any host without those rules. Also hands out the XSRF-TOKEN cookie on every response of a
 * request that used the session.
 */
class SecurityHeaders implements FilterInterface
{
    /**
     * Content-Security-Policy: even if someone managed to slip HTML into the page, the browser only runs this
     * site's own scripts. Keep in step with public/.htaccess. Styles allow 'unsafe-inline' for React style props.
     */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; "
        . "frame-src https://www.google.com https://maps.google.com; worker-src 'self' blob:; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        self::apply($request, $response);

        return $response;
    }

    public static function apply(RequestInterface $request, ResponseInterface $response): void
    {
        $response->setHeader('Content-Security-Policy', self::CSP);
        $response->setHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if (! $request instanceof IncomingRequest) {
            return;
        }
        // The website and dashboard on their own subdomains may read the API's answers (see ApiCors).
        ApiCors::headers($request, $response);
        if ($request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        // The owner's dashboard and its API should never appear in search results.
        $path = trim($request->getPath(), '/');
        if ($path === 'admin' || preg_match('#^(admin|api/admin)/#', $path . '/') === 1) {
            $response->setHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        // The React apps read the CSRF token from this cookie (see SessionState).
        if (SessionState::active()) {
            $response->setCookie(SessionState::tokenCookie());
        }
    }
}
