<?php

namespace App\Libraries\Auth;

use App\Libraries\Crypto;
use App\Libraries\Text;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Model;
use Config\Cookie as CookieConfig;
use Config\Florist;

/**
 * The dashboard's login (`admins`), kept in the session, like Laravel's session
 * guard:
 *  - login() renews the session id (so a planted session id is useless) and can set a "remember me" cookie;
 *  - "remember me" is "<id>|<remember_token>|<signature>". A new remember_token (password change, logout, a staff
 *    account switched off) ends every remembered login, and the signature covers the password hash too;
 *  - with $trackPassword (the dashboard), a session ends as soon as the password is changed somewhere else
 *    (Laravel's auth.session).
 * Get the guard with service('adminAuth').
 */
class Guard
{
    private ?array $user = null;

    private bool $resolved = false;

    private bool $viaRemember = false;

    public function __construct(
        private readonly Model $model,
        private readonly string $name,
        private readonly string $cookieName,
        private readonly bool $trackPassword,
    ) {
    }

    /** Forget what was looked up for the previous request (see Config\Events). */
    public function flush(): void
    {
        $this->user        = null;
        $this->resolved    = false;
        $this->viaRemember = false;
    }

    /** The logged-in account (its database row), or null. The session must be started (the `session` filter). */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $id = session($this->sessionKey());
        if ($id !== null) {
            $this->user = $this->model->find((int) $id) ?: null;

            return $this->user;
        }

        $user = $this->userFromRememberCookie();
        if ($user !== null) {
            session()->set($this->sessionKey(), (int) $user['id']);
            session()->regenerate(true);
            $this->user        = $user;
            $this->viaRemember = true;
        }

        return $this->user;
    }

    public function id(): ?int
    {
        $user = $this->user();

        return $user === null ? null : (int) $user['id'];
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** Logged in by the "remember me" cookie on this request. */
    public function viaRemember(): bool
    {
        return $this->viaRemember;
    }

    /** Re-reads the logged-in account from the database (after it was changed). */
    public function refresh(): ?array
    {
        $id = $this->id();
        $this->user = $id === null ? null : ($this->model->find($id) ?: null);

        return $this->user;
    }

    /** Logs the account in on this browser: new session id, and a "remember me" cookie if asked. */
    public function login(array $user, bool $remember = false): void
    {
        $session = session();
        $session->set($this->sessionKey(), (int) $user['id']);
        $session->regenerate(true);

        if ($remember) {
            if (empty($user['remember_token'])) {
                $user['remember_token'] = $this->cycleRememberToken((int) $user['id']);
            }
            service('response')->setCookie($this->rememberCookie($user));
        }
        if ($this->trackPassword) {
            $this->storePasswordFingerprint($user);
        }

        $this->user        = $user;
        $this->resolved    = true;
        $this->viaRemember = false;
    }

    /**
     * Logs out of this browser and ends "remember me" everywhere (a new remember_token), like Laravel's logout().
     * The rest of the session is kept; callers clear it when they need to.
     */
    public function logout(): void
    {
        $user = $this->user();
        $this->logoutCurrentDevice();
        if ($user !== null && ! empty($user['remember_token'])) {
            $this->cycleRememberToken((int) $user['id']);
        }
    }

    /** Logs out of this browser only. */
    public function logoutCurrentDevice(): void
    {
        session()->remove([$this->sessionKey(), $this->passwordKey()]);
        if (service('request')->getCookie($this->cookieName) !== null) {
            service('response')->setCookie($this->forgetCookie());
        }
        $this->user        = null;
        $this->resolved    = true;
        $this->viaRemember = false;
    }

    /** A new remember_token: every "remember me" cookie made before stops working. Returns the token. */
    public function cycleRememberToken(int $id): string
    {
        $token = Text::random(60);
        // Straight to the table, so no timestamps change (Laravel does the same).
        $this->model->builder()->where('id', $id)->update(['remember_token' => $token]);

        return $token;
    }

    /**
     * Laravel's auth.session: false when the password was changed (on another device, by a reset link, by the
     * developer) after this session logged in. The first check of a session just remembers the current password.
     */
    public function passwordUnchanged(array $user): bool
    {
        $stored = session($this->passwordKey());
        if (! is_string($stored)) {
            $this->storePasswordFingerprint($user);

            return true;
        }

        return hash_equals($stored, $this->passwordFingerprint($user));
    }

    /** Remember the account's current password for passwordUnchanged() (after login, or after changing it here). */
    public function storePasswordFingerprint(array $user): void
    {
        session()->set($this->passwordKey(), $this->passwordFingerprint($user));
    }

    private function passwordFingerprint(array $user): string
    {
        return hash('sha256', (string) ($user['password_hash'] ?? ''));
    }

    private function sessionKey(): string
    {
        return 'login_' . $this->name;
    }

    private function passwordKey(): string
    {
        return 'password_' . $this->name;
    }

    private function userFromRememberCookie(): ?array
    {
        $raw = service('request')->getCookie($this->cookieName);
        if (! is_string($raw) || substr_count($raw, '|') !== 2) {
            return null;
        }
        [$id, $token, $signature] = explode('|', $raw);
        if (! ctype_digit($id) || $token === '' || $signature === '') {
            return null;
        }
        $user = $this->model->find((int) $id);
        if (! $user || empty($user['remember_token']) || ! hash_equals((string) $user['remember_token'], $token)) {
            return null;
        }

        return hash_equals($this->signature($user, $token), $signature) ? $user : null;
    }

    private function signature(array $user, string $token): string
    {
        return Crypto::sign($this->name . '|' . $user['id'] . '|' . $token . '|' . ($user['password_hash'] ?? ''));
    }

    private function rememberCookie(array $user): Cookie
    {
        $token = (string) $user['remember_token'];

        return $this->cookie($user['id'] . '|' . $token . '|' . $this->signature($user, $token), time() + config(Florist::class)->rememberDays * 86400);
    }

    private function forgetCookie(): Cookie
    {
        return $this->cookie('', time() - 86400 * 365);
    }

    private function cookie(string $value, int $expires): Cookie
    {
        $config = config(CookieConfig::class);

        return new Cookie($this->cookieName, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => $config->domain,
            'secure'   => $config->secure,
            'httponly' => true,
            'samesite' => Cookie::SAMESITE_LAX,
            'raw'      => true,
        ]);
    }
}
