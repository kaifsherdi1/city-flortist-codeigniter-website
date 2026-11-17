<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Libraries\Auth\SessionState;
use App\Libraries\Catalog;
use App\Libraries\Installer;
use App\Libraries\PasswordReset;
use App\Libraries\Passwords;
use App\Libraries\Signup;
use App\Libraries\Text;
use App\Models\AdminModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Florist;
use Throwable;

/**
 * The dashboard's way in: install, log in (password, then the authenticator code when two-step login is on),
 * log out, "Forgot password?", staff sign-up and "Continue with Google". Every route here is public (only the
 * `session` filter: session cookie + CSRF), so nothing here may assume someone is logged in.
 */
class Auth extends ApiController
{
    /** GET /api/admin/status: is the site installed, who is logged in, which login options exist. Also hands out the CSRF cookie. */
    public function status(): ResponseInterface
    {
        $installed = Installer::installed();
        if ($installed) {
            Installer::upgrade();
        }
        $admin = $installed ? service('adminAuth')->user() : null;
        $db    = db_connect();

        return $this->json([
            'installed'  => $installed,
            'admin'      => $admin !== null ? AdminModel::toPublic($admin) : null,
            'new_orders' => $admin !== null ? $db->table('orders')->where('status', 'new')->countAllResults() : 0,
            // Header badges.
            'counts' => $admin !== null ? [
                'featured'  => $db->table('products')->where('is_featured', 1)->where('is_active', 1)->countAllResults(),
                'contacted' => $db->table('orders')->where('status', 'contacted')->countAllResults(),
                'offers'    => count(Catalog::activeOffers()),
                'reviews'   => $db->table('reviews')->where('status', 'pending')->countAllResults(),
            ] : null,
            // Sidebar progress bar: how many products have a price.
            'pricing' => $admin !== null ? [
                'priced' => $db->table('products')->where('price >', 0)->countAllResults(),
                'total'  => $db->table('products')->countAllResults(),
            ] : null,
            'login_options' => [
                'google' => service('googleOAuth')->configured(),
                // Password reset needs an email on the account and a real mail server (not florist.mailer = log).
                'reset_by_email' => service('mailer')->canSend(),
            ],
            'must_change_password' => $admin !== null ? (bool) session('must_change_password') : false,
            // Owner only: staff sign-ups waiting for approval (badge on Settings → Team).
            'pending_staff' => $admin !== null && AdminModel::isOwner($admin)
                ? $db->table('admins')->where('status', 'pending')->countAllResults()
                : 0,
            // Password accepted, waiting for the authenticator code.
            'two_factor_required' => $admin === null && $this->pendingTwoFactor() !== null,
            // So the owner can spot a login that wasn't them.
            'previous_login' => $admin !== null ? session('previous_login') : null,
            // Only needed by the Install screen and the logged-in dashboard; strangers don't need to know.
            // (Laravel's driver name for the MySQL/MariaDB connection.)
            'database'         => ($admin !== null || ! $installed) ? 'mysql' : null,
            'default_username' => $installed ? null : config(Florist::class)->adminUsername,
        ]);
    }

    /** POST /api/admin/install {seed}: one-time setup. Locked once an admin exists. */
    public function install(): ResponseInterface
    {
        if (Installer::installed()) {
            $this->fail('The site is already installed.', 403);
        }

        try {
            Installer::assertSafeToInstall();
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), 403);
        }

        try {
            $log = Installer::run($this->flag('seed'));
        } catch (Throwable $e) {
            $this->report($e);
            $this->fail('Install failed: ' . $e->getMessage(), 500);
        }

        return $this->json(['log' => $log, 'username' => config(Florist::class)->adminUsername]);
    }

    /** POST /api/admin/login {login, password, remember}. `login` is a username, email or mobile number. */
    public function login(): ResponseInterface
    {
        $login   = trim(self::text($this->input('login', $this->input('username', ''))));
        $limiter = service('rateLimiter');
        $admin   = (new AdminModel())->findForLogin($login);
        // Two limits: per device, and per account (so spreading guesses over many IPs doesn't help). The account
        // limit follows the account itself, so retyping the same login another way doesn't reset it.
        $keys = ['login:' . $this->ip(), 'login-account:' . Text::loginKey($admin, $login)];

        foreach ($keys as $key) {
            if ($limiter->tooManyAttempts($key, 8)) {
                $minutes = max(1, (int) ceil($limiter->availableIn($key) / 60));
                $this->fail("Too many attempts. Please wait {$minutes} minute" . ($minutes > 1 ? 's' : '') . ' and try again.', 429);
            }
        }

        foreach ($keys as $key) {
            $limiter->hit($key, 15 * 60);
        }

        $password = self::text($this->input('password', ''));
        // Same message whether the account exists or not, so the form can't be used to discover accounts.
        if ($admin === null || ! Passwords::check($password, $admin['password_hash'] ?? null)) {
            $this->fail('Wrong login details. Check your username, email or phone number and password.', 422);
        }

        foreach ($keys as $key) {
            $limiter->clear($key);
        }
        // Staff sign-ups stay locked until the owner approves them (password checked first, so this says nothing
        // to someone who doesn't know it).
        if (! AdminModel::canLogIn($admin)) {
            $this->fail(($admin['status'] ?? null) === 'pending'
                ? 'Your staff account is waiting for the shop owner’s approval. You can log in once it’s approved.'
                : 'This account has been switched off by the shop owner.', 403);
        }
        // Still on the public default (or the plain-text one from .env)? Everything stays locked until it's changed.
        $starting = Installer::isStartingPassword($password);
        $remember = $this->flag('remember');

        if (AdminModel::hasTwoFactor($admin)) {
            // Password was right, but nothing opens until the code from the authenticator app is entered too.
            $this->regenerateSession();
            session()->set('two_factor_pending', ['id' => (int) $admin['id'], 'remember' => $remember, 'starting' => $starting, 'until' => time() + 300]);

            return $this->status();
        }

        $this->completeLogin($admin, $remember, $starting);

        return $this->status();
    }

    /** POST /api/admin/login/two-factor {code}: second step, a 6-digit authenticator code or a backup code. */
    public function twoFactor(): ResponseInterface
    {
        $pending = $this->pendingTwoFactor();
        if ($pending === null) {
            $this->fail('Your login timed out. Please enter your password again.', 419);
        }
        $limiter = service('rateLimiter');
        $key     = 'two-factor:' . $pending['id'];
        if ($limiter->tooManyAttempts($key, 6)) {
            $this->fail('Too many wrong codes. Please wait ' . max(1, (int) ceil($limiter->availableIn($key) / 60)) . ' minutes and try again.', 429);
        }
        $limiter->hit($key, 15 * 60);

        $admins = new AdminModel();
        $admin  = $admins->find((int) $pending['id']);
        if (! $admin || ! $admins->verifyTwoFactor($admin, self::text($this->input('code', '')))) {
            $this->fail('That code is not right. Use the newest 6-digit code from your authenticator app, or a backup code.', 422);
        }
        $limiter->clear($key);
        session()->remove('two_factor_pending');
        // Read again: a backup code that was just used is crossed off (status() shows how many are left).
        $admin = (new AdminModel())->find((int) $pending['id']) ?? $admin;
        $this->completeLogin($admin, (bool) ($pending['remember'] ?? false), (bool) ($pending['starting'] ?? false));

        return $this->status();
    }

    /** POST /api/admin/logout */
    public function logout(): ResponseInterface
    {
        service('adminAuth')->logout();
        SessionState::invalidate();

        return $this->json(['ok' => true]);
    }

    /** POST /api/admin/password/forgot {email}: emails a reset link if that email belongs to an admin. */
    public function forgot(): ResponseInterface
    {
        $limiter = service('rateLimiter');
        $key     = 'forgot:' . $this->ip();
        if ($limiter->tooManyAttempts($key, 5)) {
            $this->fail('Too many requests. Please wait a few minutes and try again.', 429);
        }
        $limiter->hit($key, 15 * 60);

        $email = mb_strtolower(trim(self::text($this->input('email', ''))), 'UTF-8');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Please enter the email address on your account.');
        }

        try {
            PasswordReset::sendLink($email);
        } catch (Throwable $e) {
            $this->report($e);
            $this->fail('The reset email could not be sent. Please check the mail settings on the server.', 500);
        }

        // Same answer whether or not the email exists.
        return $this->json(['message' => 'If that email belongs to the shop account, a reset link is on its way. It works for 60 minutes.']);
    }

    /** POST /api/admin/password/reset {token, email, password, password_confirmation} */
    public function reset(): ResponseInterface
    {
        // Kept exactly as typed (never trimmed).
        $password = self::text($this->input('password', ''));
        if (strlen($password) < 8) {
            $this->fail('The new password must be at least 8 characters.');
        }
        if ($password !== self::text($this->input('password_confirmation', ''))) {
            $this->fail('The two passwords do not match.');
        }

        $changed = PasswordReset::reset(
            mb_strtolower(trim(self::text($this->input('email', ''))), 'UTF-8'),
            self::text($this->input('token', '')),
            $password,
        );
        if (! $changed) {
            $this->fail('This reset link is invalid or has expired. Please ask for a new one.');
        }

        return $this->json(['message' => 'Password changed. You can log in with it now.']);
    }

    /**
     * POST /api/admin/register {username, email, phone, password, password_confirmation}
     * Creates a STAFF account that stays locked ("pending") until the owner approves it in Settings → Team.
     * Nobody is logged in here, so a stranger who signs up gets no access at all.
     */
    public function register(): ResponseInterface
    {
        if (! Installer::installed()) {
            $this->fail('The dashboard is not installed yet.', 403);
        }
        if ($this->filled('website')) { // honeypot
            $this->fail('Invalid request', 400);
        }
        Signup::limit($this->ip(), 'staff', 3);

        $admins = new AdminModel();
        $v      = Signup::validate([
            'username'              => $this->input('username'),
            'email'                 => $this->input('email'),
            'phone'                 => $this->input('phone'),
            'password'              => $this->input('password'),
            'password_confirmation' => $this->input('password_confirmation'),
        ], $admins);

        $admins->insert([
            'username'      => $v['username'],
            'email'         => $v['email'],
            'phone'         => $v['phone'],
            'password_hash' => Passwords::hash($v['password']),
            'role'          => 'staff',
            'status'        => 'pending',
        ]);
        Signup::record($this->ip(), 'staff');

        return $this->json(['message' => 'Account created. The shop owner needs to approve it before you can log in.'], 201);
    }

    /** GET /api/admin/oauth/google[?intent=register]: Google sign-in for the dashboard (or a staff sign-up). */
    public function googleRedirect(): ResponseInterface
    {
        $google   = service('googleOAuth');
        $register = $this->query('intent') === 'register';
        if (! $google->configured()) {
            $page = $register ? '/admin/register' : '/admin/login';

            return $this->redirectTo($page . '?error=' . rawurlencode('Google sign-in is not set up on this server yet.'));
        }
        session()->set('admin_oauth_intent', $register ? 'register' : 'login');

        return $this->redirectTo($google->redirectUrl($google->adminCallbackUrl()));
    }

    /** GET /api/admin/oauth/google/callback: logs in only if the Google email matches an admin's email. */
    public function googleCallback(): ResponseInterface
    {
        $google = service('googleOAuth');
        if (! $google->configured()) {
            return $this->redirectTo('/admin/login');
        }

        try {
            $profile = $google->user($google->adminCallbackUrl(), $this->request);
        } catch (Throwable $e) {
            $this->report($e);

            return $this->redirectTo('/admin/login?error=' . rawurlencode('Google sign-in was cancelled or failed. Please try again.'));
        }

        $verified = (bool) ($profile['email_verified'] ?? false);
        $email    = mb_strtolower(self::text($profile['email'] ?? ''), 'UTF-8');
        $admin    = $verified && $email !== '' ? (new AdminModel())->where('email', $email)->first() : null;
        // Laravel's session()->pull('admin_oauth_intent', 'login').
        $intent = session()->has('admin_oauth_intent') ? session('admin_oauth_intent') : 'login';
        session()->remove('admin_oauth_intent');

        // Staff sign-up with Google: a locked account the owner has to approve, same as the form.
        if (! $admin && $intent === 'register' && $verified && $email !== '' && Installer::installed()) {
            (new AdminModel())->insert([
                'username'      => self::freeAdminUsername($email),
                'email'         => $email,
                'password_hash' => Passwords::hash(Text::random(40)), // Google-only until they set one via "Forgot password?"
                'role'          => 'staff',
                'status'        => 'pending',
            ]);

            return $this->redirectTo('/admin/login?notice=' . rawurlencode('Account created. The shop owner needs to approve it before you can log in with Google.'));
        }
        if (! $admin) {
            return $this->redirectTo('/admin/login?error=' . rawurlencode('That Google account has no dashboard access. Use “Create a staff account”, or the email saved in Settings → Login details.'));
        }
        if (! AdminModel::canLogIn($admin)) {
            return $this->redirectTo('/admin/login?error=' . rawurlencode(($admin['status'] ?? null) === 'pending'
                ? 'Your staff account is waiting for the shop owner’s approval.'
                : 'This account has been switched off by the shop owner.'));
        }

        if (AdminModel::hasTwoFactor($admin)) {
            $this->regenerateSession();
            session()->set('two_factor_pending', ['id' => (int) $admin['id'], 'remember' => true, 'starting' => false, 'until' => time() + 300]);

            return $this->redirectTo('/admin/login');
        }
        $this->completeLogin($admin, true, false);

        return $this->redirectTo('/admin');
    }

    // ------------------------------------------------------------------------------------------------------

    /** The password step was passed less than 5 minutes ago and the authenticator code is still due. */
    private function pendingTwoFactor(): ?array
    {
        $pending = session('two_factor_pending');

        return is_array($pending) && ($pending['until'] ?? 0) > time() ? $pending : null;
    }

    /** Logs the admin in: fresh session id, the starting-password lock, and the "last login" record shown in Settings. */
    private function completeLogin(array $admin, bool $remember, bool $starting): void
    {
        // Guard::login() renews the session id (Laravel's Auth::login + session()->regenerate()); Laravel's
        // regenerate() also gives the session a new CSRF token.
        service('adminAuth')->login($admin, $remember);
        SessionState::regenerateToken();
        session()->set('must_change_password', $starting);
        session()->set('previous_login', ! empty($admin['last_login_at'])
            ? ['at' => Text::isoDate((string) $admin['last_login_at']), 'ip' => $admin['last_login_ip'] ?? null]
            : null);
        // Straight to the table: admins have no updated_at, and nothing else on the row changes.
        (new AdminModel())->builder()->where('id', (int) $admin['id'])->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $this->ip(),
        ]);
    }

    /** Laravel's $request->session()->regenerate(): a new session id (data kept) and a new CSRF token. */
    private function regenerateSession(): void
    {
        session()->regenerate(true);
        SessionState::regenerateToken();
    }

    /** A free username for a Google sign-up, from the part of the email before the "@". */
    private static function freeAdminUsername(string $email): string
    {
        $base   = substr(strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '', (string) strtok($email, '@'))) ?: 'staff', 0, 36);
        $name   = $base;
        $admins = new AdminModel();
        for ($i = 2; $admins->builder()->where('username', $name)->countAllResults() > 0; $i++) {
            $name = $base . $i;
        }

        return $name;
    }

    /** A request field read as text, like Laravel's (string) $request->input(...); arrays count as empty. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Laravel's report($e): the error and its stack trace go to the log, the visitor gets a friendly message.
     * (Not "{file}"/"{line}": CodeIgniter's logger fills those with the place log_message() was called from.)
     */
    private function report(Throwable $e): void
    {
        log_message('error', "{class}: {message} in {where}\n{trace}", [
            'class'   => $e::class,
            'message' => $e->getMessage(),
            'where'   => clean_path($e->getFile()) . ':' . $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ]);
    }
}
