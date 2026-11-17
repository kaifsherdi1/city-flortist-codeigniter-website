<?php

namespace App\Filters;

use App\Libraries\ClientIp;
use App\Libraries\Http;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Public website API: generous for real visitors, but slows down scraping and request floods. */
class ApiThrottle implements FilterInterface
{
    public const PER_MINUTE = 240;

    private static ?int $remaining = null;

    public function before(RequestInterface $request, $arguments = null)
    {
        $limiter = service('rateLimiter');
        $key     = 'api:' . ClientIp::of($request);

        if ($limiter->tooManyAttempts($key, self::PER_MINUTE)) {
            self::$remaining = null;

            return Http::earlyError(Http::messageFor(429), 429)
                ->setHeader('Retry-After', (string) $limiter->availableIn($key))
                ->setHeader('X-RateLimit-Limit', (string) self::PER_MINUTE)
                ->setHeader('X-RateLimit-Remaining', '0');
        }
        $limiter->hit($key, 60);
        self::$remaining = $limiter->remaining($key, self::PER_MINUTE);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (self::$remaining !== null) {
            $response->setHeader('X-RateLimit-Limit', (string) self::PER_MINUTE);
            $response->setHeader('X-RateLimit-Remaining', (string) self::$remaining);
        }

        return $response;
    }
}
