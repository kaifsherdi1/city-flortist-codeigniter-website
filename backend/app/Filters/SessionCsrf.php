<?php

namespace App\Filters;

use App\Exceptions\MissingKeyException;
use App\Libraries\Auth\SessionState;
use App\Libraries\Crypto;
use App\Libraries\Http;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Laravel's `web` middleware for /api/admin and /api/account: starts the session and checks the CSRF token on every
 * change. A wrong or missing token gets 419, which makes the React apps fetch a fresh token and try once more.
 */
class SessionCsrf implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! Crypto::hasKey()) {
            return Http::earlyError(Http::describe(new MissingKeyException())[1], 500);
        }

        SessionState::start();

        if ($request instanceof IncomingRequest && ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $field = $request->getPost(SessionState::TOKEN_KEY);
            if (! SessionState::tokenMatches($request, is_string($field) ? $field : null)) {
                // Like Laravel, this answer carries only the session cookie; the React app fetches a fresh
                // XSRF-TOKEN with GET status and tries once more.
                SessionState::reset();

                return Http::earlyError(Http::messageFor(419), 419);
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
