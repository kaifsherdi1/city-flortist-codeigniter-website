<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Libraries\Crypto;
use App\Libraries\Passwords;
use App\Libraries\Totp;
use App\Models\AdminModel;
use CodeIgniter\HTTP\ResponseInterface;

/** Settings → Two-step login: turn it on (scan a QR code), off, or get new backup codes. */
class TwoFactor extends ApiController
{
    /** POST /api/admin/settings/two-factor/setup {current_password}: a new secret to scan. Saved only once confirmed. */
    public function setup(): ResponseInterface
    {
        $admin  = $this->checkPassword();
        $secret = Totp::newSecret();
        session()->set('two_factor_setup', $secret);

        return $this->json([
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri'    => Totp::uri($secret, (string) (($admin['email'] ?? null) ?: $admin['username'])),
        ]);
    }

    /** POST /api/admin/settings/two-factor/confirm {code}: proves the app is set up, turns it on, returns backup codes. */
    public function confirm(): ResponseInterface
    {
        $secret = self::text(session('two_factor_setup') ?? '');
        if ($secret === '') {
            $this->fail('Start again: press “Turn on two-step login”.');
        }
        $this->limit();
        $step = Totp::verify($secret, self::text($this->input('code', '')));
        if ($step === null) {
            $this->fail('That code is not right. Check the phone’s time is set automatically, then type the newest code.');
        }

        $id     = (int) service('adminAuth')->id();
        $admins = new AdminModel();
        // Straight to the table: admins have no updated_at. The secret is stored encrypted (Laravel's "encrypted" cast).
        $admins->builder()->where('id', $id)->update([
            'two_factor_secret'       => Crypto::encryptString($secret),
            'two_factor_confirmed_at' => date('Y-m-d H:i:s'),
            'two_factor_last_step'    => $step,
        ]);
        session()->remove('two_factor_setup');
        // Made before the account is read again, so backup_codes_left counts them.
        $codes = $admins->newRecoveryCodes($id);

        return $this->json([
            'message'        => 'Two-step login is on. Keep the backup codes somewhere safe.',
            'recovery_codes' => $codes,
            'admin'          => AdminModel::toPublic($admins->find($id)),
        ]);
    }

    /** POST /api/admin/settings/two-factor/disable {current_password, code} */
    public function disable(): ResponseInterface
    {
        $admin = $this->checkPassword();
        $this->limit();
        $admins = new AdminModel();
        if (AdminModel::hasTwoFactor($admin) && ! $admins->verifyTwoFactor($admin, self::text($this->input('code', '')))) {
            $this->fail('That code is not right. Use a code from your authenticator app or a backup code.');
        }
        $admins->builder()->where('id', (int) $admin['id'])->update([
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null,
        ]);

        return $this->json(['message' => 'Two-step login is off.', 'admin' => AdminModel::toPublic($admins->find((int) $admin['id']))]);
    }

    /** POST /api/admin/settings/two-factor/recovery-codes {current_password}: replaces all backup codes. */
    public function recoveryCodes(): ResponseInterface
    {
        $admin = $this->checkPassword();
        if (! AdminModel::hasTwoFactor($admin)) {
            $this->fail('Two-step login is not on.');
        }
        $admins = new AdminModel();
        // Made before the account is read again, so backup_codes_left counts them.
        $codes = $admins->newRecoveryCodes((int) $admin['id']);

        return $this->json([
            'message'        => 'New backup codes made. The old ones no longer work.',
            'recovery_codes' => $codes,
            'admin'          => AdminModel::toPublic($admins->find((int) $admin['id'])),
        ]);
    }

    // ------------------------------------------------------------------------------------------------------

    /** Counts an attempt, then checks the password the logged-in admin typed. Returns their account. */
    private function checkPassword(): array
    {
        $this->limit();
        $admin = service('adminAuth')->user();
        if (! Passwords::check(self::text($this->input('current_password', '')), $admin['password_hash'] ?? null)) {
            $this->fail('Your current password is wrong.');
        }

        return $admin;
    }

    /** Someone at an unlocked computer still can't guess their way through: 10 tries per 15 minutes. */
    private function limit(): void
    {
        $limiter = service('rateLimiter');
        $key     = 'two-factor-settings:' . service('adminAuth')->id();
        if ($limiter->tooManyAttempts($key, 10)) {
            $this->fail('Too many attempts. Please wait a few minutes.', 429);
        }
        $limiter->hit($key, 15 * 60);
    }

    /** A request field read as text, like Laravel's (string) $request->input(...); arrays count as empty. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
