<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;
use Config\Florist;
use RuntimeException;

/**
 * "Continue with Google" (OAuth 2.0 authorization code flow), what Laravel Socialite did in the Laravel version.
 * redirectUrl() sends the browser to Google with a random state kept in the session; user() checks that state when
 * Google sends the browser back, swaps the code for a token and reads the profile.
 * Get it with service('googleOAuth'); tests replace it with Services::injectMock('googleOAuth', …).
 */
class GoogleOAuth
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/auth';

    public const TOKEN_URL = 'https://www.googleapis.com/oauth2/v4/token';

    public const USER_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    private const STATE_KEY = 'google_oauth_state';

    public function __construct(protected Florist $config)
    {
    }

    public function configured(): bool
    {
        return $this->config->googleConfigured();
    }

    /** The dashboard's callback URL (florist.googleRedirect, or <app.baseURL>/api/admin/oauth/google/callback). */
    public function adminCallbackUrl(): string
    {
        return $this->config->googleRedirect !== ''
            ? $this->config->googleRedirect
            : rtrim(config('App')->baseURL, '/') . '/api/admin/oauth/google/callback';
    }

    /** Where to send the browser to sign in with Google. */
    public function redirectUrl(string $callbackUrl): string
    {
        $state = bin2hex(random_bytes(20));
        session()->set(self::STATE_KEY, $state);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->config->googleClientId,
            'redirect_uri'  => $callbackUrl,
            'scope'         => 'openid profile email',
            'response_type' => 'code',
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The Google account that just signed in. Throws when the sign-in was cancelled, failed or didn't start here.
     *
     * @return array{id: string, email: string, email_verified: bool, name: string}
     */
    public function user(string $callbackUrl, IncomingRequest $request): array
    {
        $expected = session(self::STATE_KEY);
        session()->remove(self::STATE_KEY);
        $state = (string) $request->getGet('state');
        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $state)) {
            throw new RuntimeException('Google sign-in: the state does not match (the sign-in did not start on this browser).');
        }
        $code = (string) $request->getGet('code');
        if ($code === '') {
            throw new RuntimeException('Google sign-in was cancelled: ' . (string) $request->getGet('error'));
        }

        $http  = service('curlrequest', ['timeout' => 15, 'http_errors' => false], null, null, false);
        $token = json_decode($http->post(self::TOKEN_URL, [
            'form_params' => [
                'client_id'     => $this->config->googleClientId,
                'client_secret' => $this->config->googleClientSecret,
                'code'          => $code,
                'redirect_uri'  => $callbackUrl,
                'grant_type'    => 'authorization_code',
            ],
            'headers' => ['Accept' => 'application/json'],
        ])->getBody(), true);
        if (! is_array($token) || empty($token['access_token'])) {
            throw new RuntimeException('Google sign-in: no access token (' . ($token['error'] ?? 'unknown error') . ').');
        }

        $http    = service('curlrequest', ['timeout' => 15, 'http_errors' => false], null, null, false);
        $profile = json_decode($http->get(self::USER_URL, [
            'headers' => ['Authorization' => 'Bearer ' . $token['access_token'], 'Accept' => 'application/json'],
        ])->getBody(), true);
        if (! is_array($profile) || empty($profile['sub'])) {
            throw new RuntimeException('Google sign-in: the profile could not be read.');
        }

        return [
            'id'             => (string) $profile['sub'],
            'email'          => (string) ($profile['email'] ?? ''),
            'email_verified' => filter_var($profile['email_verified'] ?? $profile['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name'           => (string) ($profile['name'] ?? ''),
        ];
    }
}
