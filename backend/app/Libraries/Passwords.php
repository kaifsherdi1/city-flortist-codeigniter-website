<?php

namespace App\Libraries;

/** bcrypt password hashes, compatible with the Laravel version (and the PHP site before it). */
final class Passwords
{
    public static function hash(string $password): string
    {
        // Cost 12 like Laravel; the test suite uses 4 so it runs quickly.
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => ENVIRONMENT === 'testing' ? 4 : 12]);
    }

    public static function check(string $password, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($password, $hash);
    }
}
