<?php

namespace App\Libraries\Auth;

use CodeIgniter\Cookie\Cookie;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Cookie as CookieConfig;
use Config\Session as SessionConfig;

/**
 * The session used by the dashboard and customer accounts, and its CSRF protection, the same way Laravel does it
 * for the React apps: the token lives in the session, is handed to the browser in the readable XSRF-TOKEN cookie,
 * and every change (POST/PUT/PATCH/DELETE) must send it back in the X-XSRF-TOKEN header.
 */
final class SessionState
{
    public const TOKEN_KEY = '_token';

    public const COOKIE = 'XSRF-TOKEN';

    /** True once this request started the session: the XSRF-TOKEN cookie then goes out with the response. */
    private static bool $active = false;

    /** Starts the session (once per request) and makes sure it has a CSRF token. */
    public static function start(): void
    {
        session();
        self::$active = true;
        self::token();
    }

    public static function active(): bool
    {
        return self::$active;
    }

    /** Called at the start of every request (see Config\Events). */
    public static function reset(): void
    {
        self::$active = false;
    }

    public static function token(): string
    {
        $token = session(self::TOKEN_KEY);
        if (! is_string($token) || strlen($token) !== 40) {
            $token = self::regenerateToken();
        }

        return $token;
    }

    public static function regenerateToken(): string
    {
        $token = bin2hex(random_bytes(20));
        session()->set(self::TOKEN_KEY, $token);

        return $token;
    }

    /** Does the request carry this session's CSRF token (X-XSRF-TOKEN or X-CSRF-TOKEN header, or a _token field)? */
    public static function tokenMatches(IncomingRequest $request, ?string $sentField = null): bool
    {
        $token = session(self::TOKEN_KEY);
        $sent  = $request->getHeaderLine('X-XSRF-TOKEN');
        if ($sent === '') {
            $sent = $request->getHeaderLine('X-CSRF-TOKEN');
        }
        if ($sent === '') {
            $sent = (string) $sentField;
        }

        return is_string($token) && $token !== '' && $sent !== '' && hash_equals($token, $sent);
    }

    /** The cookie the React apps read the token from (not HttpOnly: JavaScript must be able to read it). */
    public static function tokenCookie(): Cookie
    {
        $cookie = config(CookieConfig::class);

        return new Cookie(self::COOKIE, self::token(), [
            'expires'  => time() + config(SessionConfig::class)->expiration,
            'path'     => '/',
            'domain'   => $cookie->domain,
            'secure'   => $cookie->secure,
            'httponly' => false,
            'samesite' => Cookie::SAMESITE_LAX,
            'raw'      => true,
        ]);
    }

    /** Logs everyone out of this browser: all session data goes, the session gets a new id and a new CSRF token. */
    public static function invalidate(): void
    {
        self::flush();
        session()->regenerate(true);
        self::regenerateToken();
    }

    /** Removes every value from the session (keeps the id). */
    public static function flush(): void
    {
        $session = session();
        foreach (array_keys($_SESSION ?? []) as $key) {
            $session->remove((string) $key);
        }
    }
}
