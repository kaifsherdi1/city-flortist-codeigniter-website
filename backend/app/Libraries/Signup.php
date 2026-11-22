<?php

namespace App\Libraries;

use App\Exceptions\ApiException;
use CodeIgniter\Model;

/**
 * Checks for the dashboard's staff sign-up form: username, email, mobile,
 * password + confirmation. Returns clean values or stops with {"error": "..."} the forms show as-is.
 */
class Signup
{
    /**
     * @param array{username?: mixed, email?: mixed, phone?: mixed, password?: mixed, password_confirmation?: mixed} $fields
     * @param Model $model The table the account goes in (AdminModel), for the "already taken" checks
     *
     * @return array{username: string, email: string, phone: string, password: string}
     */
    public static function validate(array $fields, Model $model): array
    {
        $text     = static fn (string $key): string => is_scalar($fields[$key] ?? null) ? (string) $fields[$key] : '';
        $username = trim($text('username'));
        $email    = strtolower(trim($text('email')));
        $phone    = Text::normalizePhone(trim($text('phone')));
        $password = $text('password');

        if (! preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            self::fail('Choose a username of 3–40 letters, numbers, dots, dashes or underscores (no spaces).');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            self::fail('Please enter a valid email address.');
        }
        if (! $phone) {
            self::fail('Please enter a valid 10-digit mobile number.');
        }
        if (strlen($password) < 8 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            self::fail('Your password needs at least 8 characters, with letters and at least one number.');
        }
        if (strlen($password) > 200) {
            self::fail('That password is too long.');
        }
        if ($password !== $text('password_confirmation')) {
            self::fail('The two passwords don’t match.');
        }
        if (in_array(strtolower($password), [strtolower($username), $email, 'password1', '12345678a', 'changeme@123'], true)) {
            self::fail('Please choose a less guessable password.');
        }
        foreach (['username' => $username, 'email' => $email, 'phone' => $phone] as $column => $value) {
            if ($model->builder()->where($column, $value)->countAllResults() > 0) {
                self::fail(match ($column) {
                    'username' => 'That username is taken. Please choose another one.',
                    'email'    => 'An account with this email already exists. Try logging in instead.',
                    'phone'    => 'An account with this mobile number already exists. Try logging in instead.',
                });
            }
        }

        return ['username' => $username, 'email' => $email, 'phone' => $phone, 'password' => $password];
    }

    /**
     * At most $max accounts per device per hour, so nobody can create accounts in bulk. Only accounts actually
     * created count (call record() after creating one): typos in the form never lock a real person out.
     */
    public static function limit(string $ip, string $key, int $max = 5): void
    {
        if (service('rateLimiter')->tooManyAttempts("signup:{$key}:{$ip}", $max)) {
            self::fail('Too many sign-ups from this device. Please try again in an hour.', 429);
        }
    }

    public static function record(string $ip, string $key): void
    {
        service('rateLimiter')->hit("signup:{$key}:{$ip}", 3600);
    }

    public static function fail(string $message, int $status = 422): never
    {
        throw new ApiException($message, $status);
    }
}
