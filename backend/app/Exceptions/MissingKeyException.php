<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * encryption.key is missing from .env (Laravel's MissingAppKeyException). Logins, "remember me" and two-step login
 * secrets all depend on it.
 */
class MissingKeyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('encryption.key is missing from backend/.env. In the backend folder run: php spark key:generate');
    }
}
