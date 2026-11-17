<?php

namespace App\Commands;

use App\Libraries\Passwords;
use App\Libraries\Text;
use App\Models\AdminModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Random\Randomizer;

/**
 * php spark florist:admin-recover [username] [--two-factor-off] [--reset-password]
 *
 * For the developer, over SSH, when the owner is locked out (lost phone and backup codes, or forgot the password
 * with no email).
 */
class AdminRecover extends BaseCommand
{
    protected $group       = 'City Florist';
    protected $name        = 'florist:admin-recover';
    protected $description = 'Help a locked-out owner: turn off two-step login and/or set a temporary password';
    protected $usage       = 'florist:admin-recover [username] [options]';
    protected $arguments   = [
        'username' => 'The admin to help [default: "admin"]',
    ];
    protected $options = [
        '--two-factor-off' => 'Turn off two-step login',
        '--reset-password' => 'Set a new random password, shown once (the owner must change it when logging in)',
    ];

    public function run(array $params): int
    {
        $words = self::arguments($params);
        if (count($words) > 1) {
            CLI::error('Too many arguments to "florist:admin-recover" command, expected arguments "username".');

            return EXIT_ERROR;
        }

        $admins = new AdminModel();
        $admin  = $admins->where('username', $words[0] ?? 'admin')->first();
        if ($admin === null) {
            $usernames = array_column(db_connect()->table('admins')->select('username')->get()->getResultArray(), 'username');
            CLI::error('No admin with that username. Admins: ' . implode(', ', $usernames));

            return EXIT_ERROR;
        }
        $twoFactorOff  = self::option($params, 'two-factor-off');
        $resetPassword = self::option($params, 'reset-password');
        if (! $twoFactorOff && ! $resetPassword) {
            CLI::write('Choose --two-factor-off and/or --reset-password.', 'yellow');

            return EXIT_USER_INPUT;
        }

        if ($twoFactorOff) {
            $admins->builder()->where('id', $admin['id'])->update([
                'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null,
            ]);
            CLI::write('Two-step login is off for ' . $admin['username'] . '. Ask the owner to turn it on again in Settings.', 'green');
        }
        if ($resetPassword) {
            $temp = self::temporaryPassword();
            // A new remember token signs out every device that was still logged in.
            $admins->builder()->where('id', $admin['id'])->update(['password_hash' => Passwords::hash($temp), 'remember_token' => Text::random(60)]);
            CLI::write("Temporary password for {$admin['username']}: {$temp}", 'green');
            CLI::write('Give it to the owner privately; they should change it in Settings → Change password straight away.');
        }

        return EXIT_SUCCESS;
    }

    /**
     * The words typed as arguments. Both options are plain flags, but CodeIgniter takes the word after an option as
     * its value ("--reset-password staff1"). Laravel reads that word as the username, so it counts as one here too:
     * the account changed is the one typed, whatever the order.
     *
     * @return list<string>
     */
    private static function arguments(array $params): array
    {
        $words = [];
        foreach ($params as $key => $value) {
            if (is_int($key) || is_string($value)) {
                $words[] = (string) $value;
            }
        }

        return $words;
    }

    /** True when the flag was given (`php spark` passes options in $params; CLI::getOption covers other callers). */
    private static function option(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || CLI::getOption($name) !== null;
    }

    /** 14 random letters and digits, at least one of each: Laravel's Str::password(14, symbols: false). */
    private static function temporaryPassword(int $length = 14): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits  = '0123456789';
        $pool    = $letters . $digits;
        $chars   = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
        while (count($chars) < $length) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        // Random\Randomizer uses the secure engine by default, like Laravel's Arr::shuffle.
        return implode('', (new Randomizer())->shuffleArray($chars));
    }
}
