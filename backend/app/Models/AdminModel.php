<?php

namespace App\Models;

use App\Libraries\Crypto;
use App\Libraries\Passwords;
use App\Libraries\Text;
use App\Libraries\Totp;
use CodeIgniter\Model;
use Throwable;

/**
 * Dashboard logins: the shop owner and staff. Sign in with username, email or phone number.
 * The password column kept its original name (password_hash) from the site before Laravel. Two-step login
 * secrets are stored encrypted with encryption.key (see App\Libraries\Crypto).
 */
class AdminModel extends Model
{
    public const ROLES = ['owner' => 'Owner', 'staff' => 'Staff'];

    public const STATUSES = ['active' => 'Active', 'pending' => 'Waiting for approval', 'disabled' => 'Disabled'];

    protected $table         = 'admins';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'username', 'email', 'phone', 'password_hash', 'role', 'status', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step',
        'last_login_at', 'last_login_ip',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected array $casts   = ['id' => 'int', 'two_factor_last_step' => '?int'];

    /** Finds the admin for whatever was typed in the login box: username, email or mobile number. */
    public function findForLogin(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        if (str_contains($login, '@')) {
            return $this->where('email', strtolower($login))->first();
        }
        if ($phone = Text::normalizePhone($login)) {
            $byPhone = $this->where('phone', $phone)->first();
            if ($byPhone) {
                return $byPhone;
            }
        }

        return $this->where('username', $login)->first();
    }

    public static function isOwner(array $admin): bool
    {
        return ($admin['role'] ?? 'owner') === 'owner';
    }

    /** Only active accounts may log in: a staff sign-up stays "pending" until the owner approves it. */
    public static function canLogIn(array $admin): bool
    {
        return ($admin['status'] ?? 'active') === 'active';
    }

    public static function hasTwoFactor(array $admin): bool
    {
        return ($admin['two_factor_confirmed_at'] ?? null) !== null && ! empty($admin['two_factor_secret']);
    }

    /** The authenticator secret (base32), decrypted. */
    public static function twoFactorSecret(array $admin): ?string
    {
        return empty($admin['two_factor_secret']) ? null : Crypto::decryptString((string) $admin['two_factor_secret']);
    }

    /** Hashes of the unused backup codes. */
    public static function recoveryCodes(array $admin): array
    {
        if (empty($admin['two_factor_recovery_codes'])) {
            return [];
        }
        $codes = json_decode(Crypto::decryptString((string) $admin['two_factor_recovery_codes']), true);

        return is_array($codes) ? array_values($codes) : [];
    }

    /** The encrypted column value for a list of backup-code hashes (Laravel's encrypted:array cast). */
    public static function encryptCodes(array $hashes): string
    {
        return Crypto::encryptString(json_encode(array_values($hashes)));
    }

    /**
     * Checks an authenticator code or a one-time backup code. A used backup code is crossed off, and an
     * authenticator code can't be replayed (each 30-second step is accepted once).
     */
    public function verifyTwoFactor(array $admin, string $code): bool
    {
        if (! self::hasTwoFactor($admin)) {
            return false;
        }
        $step = Totp::verify((string) self::twoFactorSecret($admin), $code, $admin['two_factor_last_step'] ?? null);
        if ($step !== null) {
            $this->builder()->where('id', $admin['id'])->update(['two_factor_last_step' => $step]);

            return true;
        }
        $backup = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $codes  = self::recoveryCodes($admin);
        foreach ($codes as $i => $hash) {
            if (strlen($backup) === 10 && Passwords::check($backup, $hash)) {
                unset($codes[$i]);
                $this->builder()->where('id', $admin['id'])->update(['two_factor_recovery_codes' => self::encryptCodes($codes)]);

                return true;
            }
        }

        return false;
    }

    /** Ten fresh backup codes. Only their hashes are kept; the plain codes are shown to the owner once. */
    public function newRecoveryCodes(int $id): array
    {
        $plain = [];
        for ($i = 0; $i < 10; $i++) {
            $plain[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        }
        $this->builder()->where('id', $id)->update([
            'two_factor_recovery_codes' => self::encryptCodes(array_map(static fn ($c) => Passwords::hash($c), $plain)),
        ]);

        return array_map(static fn ($c) => substr($c, 0, 5) . '-' . substr($c, 5), $plain);
    }

    /** What the dashboard is told about a login (never the password or two-step secrets). */
    public static function toPublic(array $admin): array
    {
        $twoFactor = self::hasTwoFactor($admin);
        $left      = null;
        if ($twoFactor) {
            try {
                $left = count(self::recoveryCodes($admin));
            } catch (Throwable) {
                $left = 0;
            }
        }

        return [
            'id'                => (int) $admin['id'],
            'username'          => $admin['username'],
            'email'             => $admin['email'] ?? null,
            'phone'             => $admin['phone'] ?? null,
            'role'              => $admin['role'] ?? 'owner',
            'status'            => $admin['status'] ?? 'active',
            'two_factor'        => $twoFactor,
            'backup_codes_left' => $left,
        ];
    }
}
