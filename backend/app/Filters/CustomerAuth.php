<?php

namespace App\Filters;

use App\Libraries\Http;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Customer pages of the website (/api/account/orders): a customer login is required. Runs after `session`. */
class CustomerAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (service('customerAuth')->user() === null) {
            return Http::earlyError(Http::messageFor(401), 401);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
