<?php

namespace App\Libraries;

use App\Models\AdminModel;

/**
 * "Forgot password?" for the dashboard, as Laravel's password broker does it (same `password_reset_tokens` table):
 * a 64-character token is emailed, only its bcrypt hash is stored, it works once and for 60 minutes, and a new link
 * can't be requested for the same account within 60 seconds.
 */
class PasswordReset
{
    public const EXPIRE_MINUTES = 60;

    public const THROTTLE_SECONDS = 60;

    /** Emails a reset link if the email belongs to an admin. Does nothing otherwise (the caller says the same either way). */
    public static function sendLink(string $email): void
    {
        $admin = (new AdminModel())->where('email', $email)->first();
        if (! $admin) {
            return;
        }
        $db       = db_connect();
        $existing = $db->table('password_reset_tokens')->where('email', $admin['email'])->get()->getRowArray();
        if ($existing && $existing['created_at'] && strtotime((string) $existing['created_at']) + self::THROTTLE_SECONDS > time()) {
            return; // asked a moment ago: the first email is on its way
        }

        $token = hash_hmac('sha256', Text::random(40), Crypto::key());
        $db->table('password_reset_tokens')->where('email', $admin['email'])->delete();
        $db->table('password_reset_tokens')->insert([
            'email'      => $admin['email'],
            'token'      => Passwords::hash($token),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $url = self::url($token, (string) $admin['email']);
        service('mailer')->send(
            (string) $admin['email'],
            'Reset Password Notification',
            view('emails/reset_password', ['url' => $url, 'minutes' => self::EXPIRE_MINUTES]),
            "Hello!\n\nYou are receiving this email because we received a password reset request for your account.\n\n"
            . "Reset Password: {$url}\n\nThis password reset link will expire in " . self::EXPIRE_MINUTES . " minutes.\n\n"
            . "If you did not request a password reset, no further action is required.\n\nRegards,\nCity Florist\n",
        );
    }

    /** The dashboard's own reset page. */
    public static function url(string $token, string $email): string
    {
        return Text::siteUrl('admin/reset-password') . '?' . http_build_query(['token' => $token, 'email' => $email]);
    }

    /**
     * Sets a new password if the token is right for this email and still fresh. The token is used up, and every
     * "remember me" login ends. Returns false for a wrong, used or expired link.
     */
    public static function reset(string $email, string $token, string $password): bool
    {
        $admins = new AdminModel();
        $admin  = $admins->where('email', $email)->first();
        if (! $admin || $token === '') {
            return false;
        }
        $db  = db_connect();
        $row = $db->table('password_reset_tokens')->where('email', $admin['email'])->get()->getRowArray();
        if (! $row || ! $row['created_at'] || strtotime((string) $row['created_at']) + self::EXPIRE_MINUTES * 60 < time()
            || ! Passwords::check($token, (string) $row['token'])) {
            return false;
        }

        $admins->builder()->where('id', $admin['id'])->update([
            'password_hash'  => Passwords::hash($password),
            'remember_token' => Text::random(60),
        ]);
        $db->table('password_reset_tokens')->where('email', $admin['email'])->delete();

        return true;
    }
}
