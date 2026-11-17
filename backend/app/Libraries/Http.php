<?php

namespace App\Libraries;

use App\Exceptions\ApiException;
use App\Exceptions\MissingKeyException;
use CodeIgniter\Exceptions\HTTPExceptionInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Florist;
use Throwable;

/**
 * API responses: readable JSON (₹, /uploads/… unescaped) that browsers and proxies never cache, and errors as
 * {"error": "..."}, the shape both React apps show to people.
 */
final class Http
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** Answers from the controllers (the Laravel version's ApiJson middleware: "no-store", plus Symfony's "private"). */
    public const NO_STORE = 'no-store, private';

    /**
     * Everything decided before a controller runs (no login, CSRF, unknown URL, rate limit), redirects, downloads
     * and pages: the Laravel version sent these with Symfony's default "no-cache, private".
     */
    public const NO_CACHE = 'no-cache, private';

    /** Status codes CodeIgniter has no reason phrase for. */
    private const REASONS = [419 => 'Page Expired'];

    /** Symfony's RedirectResponse page, which the Laravel version sent with every redirect. */
    private const REDIRECT_PAGE = '<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8" />
        <meta http-equiv="refresh" content="0;url=\'%1$s\'" />

        <title>Redirecting to %1$s</title>
    </head>
    <body>
        Redirecting to <a href="%1$s">%1$s</a>.
    </body>
</html>';

    public static function json(mixed $data, int $status = 200, ?ResponseInterface $response = null, string $cacheControl = self::NO_STORE): ResponseInterface
    {
        $response ??= service('response');

        return self::cacheControl($response, $cacheControl)
            ->setStatusCode($status, self::REASONS[$status] ?? '')
            ->setContentType('application/json', 'UTF-8')
            ->setBody(json_encode($data, self::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /** Replaces the Cache-Control header (CodeIgniter's setHeader() would add to its default "no-store, max-age=0, no-cache"). */
    public static function cacheControl(ResponseInterface $response, string $value): ResponseInterface
    {
        return $response->removeHeader('Cache-Control')->setHeader('Cache-Control', $value);
    }

    /** {"error": "..."} plus any extra fields, e.g. ['must_change_password' => true]. */
    public static function error(string $message, int $status = 422, array $extra = [], ?ResponseInterface $response = null, string $cacheControl = self::NO_STORE): ResponseInterface
    {
        return self::json(['error' => $message] + $extra, $status, $response, $cacheControl);
    }

    /** An error decided before any controller ran: not logged in, CSRF, unknown URL, rate limit, upload too big. */
    public static function earlyError(string $message, int $status, array $extra = []): ResponseInterface
    {
        return self::error($message, $status, $extra, null, self::NO_CACHE);
    }

    /**
     * A redirect to a path on this site ("/admin/login?error=…") or to another site (Google), the way the Laravel
     * version sent it: an absolute Location built from the request's own host, and Symfony's small HTML page. Uses
     * the shared response, so cookies set during the request (session, login, XSRF) go out with it.
     */
    public static function redirect(string $location, int $status = 302): ResponseInterface
    {
        $url = self::absoluteUrl($location);

        return self::cacheControl(service('response'), self::NO_CACHE)
            ->setStatusCode($status)
            ->setHeader('Location', $url)
            ->setContentType('text/html', 'utf-8')
            ->setBody(sprintf(self::REDIRECT_PAGE, htmlspecialchars($url, ENT_QUOTES, 'UTF-8')));
    }

    /** "/admin/login?x" → "https://<the host the browser asked for>/admin/login?x" (Laravel's url()). */
    public static function absoluteUrl(string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $request = service('request');
        $host    = $request instanceof \CodeIgniter\HTTP\IncomingRequest ? $request->getHeaderLine('Host') : '';
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
            $base = parse_url(config('App')->baseURL);
            $host = ($base['host'] ?? 'localhost') . (isset($base['port']) ? ':' . $base['port'] : '');
        }
        $scheme = $request instanceof \CodeIgniter\HTTP\IncomingRequest && $request->isSecure() ? 'https' : 'http';

        return $scheme . '://' . $host . '/' . ltrim($location, '/');
    }

    /**
     * The status and the message people see for an exception, as the Laravel version's error handler chose them.
     *
     * @return array{0: int, 1: string}
     */
    public static function describe(Throwable $e): array
    {
        $debug = config(Florist::class)->isDebug();

        if ($e instanceof ApiException) {
            return [$e->status(), $e->getMessage()];
        }
        if ($e instanceof PageNotFoundException) {
            return [404, 'Not found.'];
        }
        if ($e instanceof MissingKeyException) {
            return [500, $debug ? $e->getMessage() : 'Server error. Please try again.'];
        }
        if ($e instanceof HTTPExceptionInterface && $e->getCode() >= 400 && $e->getCode() < 600) {
            $status = (int) $e->getCode();

            return [$status, self::messageFor($status, $e->getMessage())];
        }

        return [500, $debug ? $e->getMessage() : 'Server error. Please try again.'];
    }

    public static function messageFor(int $status, string $fallback = ''): string
    {
        return match ($status) {
            401     => 'Please log in again.',
            404     => 'Not found.',
            405     => 'Method not allowed.',
            413     => 'These photos are too large to upload together. Please add fewer at a time.',
            419     => 'Your session expired. Please refresh the page and try again.',
            429     => 'Too many requests. Please wait a minute and try again.',
            default => $fallback !== '' ? $fallback : 'Request failed.',
        };
    }
}
