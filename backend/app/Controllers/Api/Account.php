<?php

namespace App\Controllers\Api;

use App\Controllers\ApiController;
use App\Libraries\Auth\SessionState;
use App\Libraries\Passwords;
use App\Libraries\Signup;
use App\Libraries\Text;
use App\Models\CustomerModel;
use App\Models\OrderModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Customer accounts on the website: /api/account/*. Session + CSRF like the dashboard, but service('customerAuth'),
 * so a customer login never opens the dashboard and the owner's login never shows customer data.
 */
class Account extends ApiController
{
    /** The order columns a customer sees in "My orders", in this order. */
    private const ORDER_COLUMNS = ['id', 'product_id', 'product_name', 'price_at_order', 'delivery_date', 'status', 'created_at'];

    /** GET /api/account/status: who is logged in. Also hands out the CSRF cookie. */
    public function status(): ResponseInterface
    {
        $customer = service('customerAuth')->user();

        return $this->json([
            'customer' => $customer === null ? null : CustomerModel::toPublic($customer),
            'google'   => service('googleOAuth')->configured(),
        ]);
    }

    /** POST /api/account/register {username, email, phone, password, password_confirmation} */
    public function register(): ResponseInterface
    {
        if ($this->filled('website')) { // honeypot
            Signup::fail('Invalid request', 400);
        }
        Signup::limit($this->ip(), 'customer');
        $model = new CustomerModel();
        $v     = Signup::validate($this->inputAll(), $model);

        $id = (int) $model->insert([
            'username'      => $v['username'],
            'email'         => $v['email'],
            'phone'         => $v['phone'],
            'password_hash' => Passwords::hash($v['password']),
        ]);
        $customer = $model->find($id);
        Signup::record($this->ip(), 'customer');
        $this->signIn($customer, $this->flag('remember'));

        return $this->json(['message' => 'Welcome to City Florist, ' . $customer['username'] . '!', 'customer' => CustomerModel::toPublic($customer)], 201);
    }

    /** POST /api/account/login {login, password, remember}: login is a username, email or mobile number. */
    public function login(): ResponseInterface
    {
        $login    = trim((string) $this->input('login', ''));
        $limiter  = service('rateLimiter');
        $customer = (new CustomerModel())->findForLogin($login);
        // Per device and per account; the account limit follows the account itself, so retyping the same login
        // another way ("PRIYA_P", "+91 98765 43210") doesn't reset it.
        $keys = ['customer-login:' . $this->ip(), 'customer-login-account:' . Text::loginKey($customer, $login)];
        foreach ($keys as $key) {
            if ($limiter->tooManyAttempts($key, 8)) {
                Signup::fail('Too many attempts. Please wait ' . max(1, (int) ceil($limiter->availableIn($key) / 60)) . ' minutes and try again.', 429);
            }
        }
        foreach ($keys as $key) {
            $limiter->hit($key, 15 * 60);
        }

        if ($customer && empty($customer['password_hash']) && ! empty($customer['google_id'])) {
            Signup::fail('This account was made with Google. Please use “Continue with Google”.');
        }
        if (! $customer || ! Passwords::check((string) $this->input('password', ''), (string) $customer['password_hash'])) {
            Signup::fail('Wrong login details. Check your username, email or mobile number and password.');
        }
        foreach ($keys as $key) {
            $limiter->clear($key);
        }
        $this->signIn($customer, $this->flag('remember'));

        return $this->json(['customer' => CustomerModel::toPublic($customer)]);
    }

    /** POST /api/account/logout: ends the customer login only (the dashboard login, if any, stays). */
    public function logout(): ResponseInterface
    {
        service('customerAuth')->logout();
        SessionState::regenerateToken();

        return $this->json(['ok' => true]);
    }

    /** GET /api/account/orders: the customer's own orders, newest first. */
    public function orders(): ResponseInterface
    {
        $rows = (new OrderModel())->builder()
            ->select(self::ORDER_COLUMNS)
            ->where('customer_id', service('customerAuth')->id())
            ->orderBy('id', 'DESC')
            ->limit(100)
            ->get()->getResultArray();

        return $this->json(['orders' => array_map(static fn (array $row): array => OrderModel::serialize($row, self::ORDER_COLUMNS), $rows)]);
    }

    /** POST /api/account/orders: same as POST /api/orders, but the order is saved to the customer's account. */
    public function order(): ResponseInterface
    {
        $orders = new Orders();
        $orders->initController($this->request, $this->response, service('logger'));

        return $orders->place(service('customerAuth')->user());
    }

    /** GET /api/account/oauth/google: "Continue with Google" for customers (sign-up and log-in in one). */
    public function googleRedirect(): ResponseInterface
    {
        $google = service('googleOAuth');
        if (! $google->configured()) {
            return $this->redirectTo('/login?error=' . rawurlencode('Google sign-in is not set up on this website yet.'));
        }

        return $this->redirectTo($google->redirectUrl($google->customerCallbackUrl()));
    }

    /** GET /api/account/oauth/google/callback: finds or creates the customer, then logs them in. */
    public function googleCallback(): ResponseInterface
    {
        $oauth = service('googleOAuth');
        if (! $oauth->configured()) {
            return $this->redirectTo('/login');
        }

        try {
            $google = $oauth->user($oauth->customerCallbackUrl(), $this->request);
        } catch (Throwable $e) {
            // Laravel's report($e). CodeIgniter's logger writes an `exception` as "message file:line" (where it was
            // thrown); {file} and {line} would be replaced by this line instead.
            log_message('error', '{class}: {exception}', ['class' => $e::class, 'exception' => $e]);

            return $this->redirectTo('/login?error=' . rawurlencode('Google sign-in was cancelled or failed. Please try again.'));
        }
        $verified = (bool) ($google['email_verified'] ?? false);
        $email    = mb_strtolower((string) ($google['email'] ?? ''), 'UTF-8');
        if (! $verified || $email === '') {
            return $this->redirectTo('/login?error=' . rawurlencode('Your Google account has no verified email address.'));
        }

        $model    = new CustomerModel();
        $googleId = (string) $google['id'];
        $customer = $model->where('google_id', $googleId)->first() ?? $model->where('email', $email)->first();
        if ($customer) {
            if (empty($customer['google_id'])) {
                // Same verified email: link it (updated_at changes, like Eloquent's save()). Nobody checked that
                // email when the account was made with a password, so that password may be a stranger's who
                // registered it first: it stops working and "remember me" logins end. The Google account's owner,
                // who just proved the email is theirs, signs in with Google from now on.
                $changes = ['google_id' => $googleId];
                if (! empty($customer['password_hash'])) {
                    $changes['password_hash']  = null;
                    $changes['remember_token'] = Text::random(60);
                }
                $model->update($customer['id'], $changes);
                $customer = array_merge($customer, $changes);
            }
        } else {
            $id = (int) $model->insert([
                'username'  => $model->freeUsername($email),
                'email'     => $email,
                'google_id' => $googleId,
            ]);
            $customer = $model->find($id);
        }
        $this->signIn($customer, true);

        return $this->redirectTo('/account');
    }

    /**
     * Logs the customer in on this browser. Guard::login() renews the session id; the CSRF token is renewed too, as
     * Laravel's $request->session()->regenerate() did. Then the "last login" time is saved.
     *
     * @param array<string, mixed> $customer
     */
    private function signIn(array $customer, bool $remember): void
    {
        service('customerAuth')->login($customer, $remember);
        SessionState::regenerateToken();
        (new CustomerModel())->update($customer['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
    }
}
