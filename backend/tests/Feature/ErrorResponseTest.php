<?php

namespace Tests\Feature;

use App\Exceptions\MissingKeyException;
use App\Libraries\Http;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Config\Encryption;
use Config\Exceptions;
use Config\Florist;
use Config\Services;
use Tests\Support\FeatureTestCase;
use Throwable;

/**
 * Port of the Laravel backend's tests/Feature/ErrorResponseTest.php: a missing encryption key explains the fix only
 * in debug mode, and an API error never prints a second JSON body after the response went out.
 *
 * @internal
 */
final class ErrorResponseTest extends FeatureTestCase
{
    /**
     * The app's exception handler (Config\Exceptions::handler(), what CodeIgniter calls for an error nothing caught)
     * for this request, like Laravel's ExceptionHandler::render(). CodeIgniter's handler sends its answer itself, so
     * this returns what it printed.
     */
    private function render(IncomingRequest $request, Throwable $e): string
    {
        return $this->capture(static fn () => config(Exceptions::class)->handler(500, $e)->handle($e, $request, service('response'), 500, EXIT_ERROR));
    }

    /** Laravel's Request::create($path) made the current request ($this->app->instance('request', …)). */
    private function request(string $path): IncomingRequest
    {
        $config  = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, ltrim($path, '/')), null, new UserAgent());
        Services::injectMock('request', $request);

        return $request;
    }

    public function test_missing_app_key_says_how_to_fix_it_only_in_debug_mode(): void
    {
        // Laravel's MissingAppKeyException: no key in .env.
        config(Encryption::class)->key = '';

        config(Florist::class)->debug = true;
        $res = $this->getJson('/api/admin/status');
        $this->assertStatusCode($res, 500);
        $this->assertStringContainsString('php spark key:generate', $this->json($res, 'error'));

        // The live site doesn't tell visitors how it is set up.
        config(Florist::class)->debug = false;
        $this->assertSame('Server error. Please try again.', $this->json($this->getJson('/api/admin/status'), 'error'));
    }

    public function test_an_error_after_the_response_went_out_is_not_printed_again(): void
    {
        $request = $this->request('/api/admin/status');

        // Before the response is sent, errors render as usual (one JSON body).
        $this->startRequest();
        $first = $this->render($request, new MissingKeyException());
        $this->assertNotSame('', $first);
        $this->assertSame(['error'], array_keys((array) json_decode($first, true)), "One JSON body. Printed: {$first}");

        // Sent, and CodeIgniter is winding down: its last event (post_system) fired and CodeIgniter::sendResponse()
        // sent this request's answer (here the missing-key error, as in the Laravel incident). An error from then on
        // (a fatal one reaches the same handler through CodeIgniter's shutdown handler) must not print a second body:
        // it would break the JSON already sent.
        $this->startRequest();
        Http::earlyError(Http::describe(new MissingKeyException())[1], 500);
        Events::trigger('post_system');
        $sent = $this->capture(static fn () => service('response')->send());
        $this->assertSame(['error'], array_keys((array) json_decode($sent, true)), "One JSON body. Sent: {$sent}");
        $this->assertSame('', $this->render($request, new MissingKeyException()));
    }

    /** A new request: CodeIgniter::run() fires pre_system first, and the request has a response of its own. */
    private function startRequest(): void
    {
        Services::resetSingle('response');
        Events::trigger('pre_system');
    }

    /** What $print echoes. */
    private function capture(callable $print): string
    {
        ob_start();

        try {
            $print();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
