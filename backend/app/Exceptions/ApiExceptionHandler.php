<?php

namespace App\Exceptions;

use App\Filters\SecurityHeaders;
use App\Libraries\Http;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Errors nothing else caught. Under /api they come back as {"error": "..."} (a real message only in debug mode,
 * "Server error. Please try again." on the live site); everything else gets CodeIgniter's usual error page.
 */
final class ApiExceptionHandler implements ExceptionHandlerInterface
{
    /** This request's answer has gone out (see Config\Events): set at post_system, cleared at pre_system. */
    private static bool $responseSent = false;

    public function __construct(private readonly ExceptionHandlerInterface $fallback)
    {
    }

    public static function responseSent(bool $sent = true): void
    {
        self::$responseSent = $sent;
    }

    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        // The answer already went out and CodeIgniter is winding down (e.g. a session can't be saved at shutdown).
        // Printing this error too would put two bodies in one response, which the React apps can't read; the
        // error is still logged (Config\Exceptions::$log runs before this handler). The Laravel version did the same.
        if (self::$responseSent) {
            return;
        }

        if (! $request instanceof IncomingRequest || ! self::isApi($request)) {
            $this->fallback->handle($exception, $request, $response, $statusCode, $exitCode);

            return;
        }

        [$status, $message] = Http::describe($exception);
        $response = Http::error($message, $status, [], $response, Http::NO_CACHE);
        SecurityHeaders::apply($request, $response);
        $response->send();

        if (ENVIRONMENT !== 'testing') {
            // @codeCoverageIgnoreStart
            exit($exitCode);
            // @codeCoverageIgnoreEnd
        }
    }

    public static function isApi(IncomingRequest $request): bool
    {
        $path = trim($request->getPath(), '/');

        return $path === 'api' || str_starts_with($path, 'api/');
    }
}
