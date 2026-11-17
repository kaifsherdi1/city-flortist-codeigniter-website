<?php

namespace App\Exceptions;

use App\Libraries\Http;
use CodeIgniter\HTTP\ResponsableInterface;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/**
 * Stops the request with {"error": "..."} and a status code (Laravel's HttpResponseException). Controllers throw it
 * through $this->fail(); ApiController::_remap() and CodeIgniter itself turn it into the response.
 */
class ApiException extends RuntimeException implements ResponsableInterface
{
    /**
     * @param array<string, mixed> $extra        More fields for the JSON body, e.g. ['must_change_password' => true]
     * @param string               $cacheControl Http::NO_STORE (a controller's answer) or Http::NO_CACHE (decided before it)
     */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly array $extra = [],
        private readonly string $cacheControl = Http::NO_STORE,
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function getResponse(): ResponseInterface
    {
        return Http::error($this->getMessage(), $this->status, $this->extra, null, $this->cacheControl);
    }
}
