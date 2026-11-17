<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;

/** Optional two-step login with an authenticator app, plus one-time backup codes. Secrets are stored encrypted. */
class AddTwoFactorToAdmins extends FloristMigration
{
    public function up(): void
    {
        $columns = [];
        if (! $this->hasColumn('admins', 'two_factor_secret')) {
            $columns['two_factor_secret']         = ['type' => 'TEXT', 'null' => true];
            $columns['two_factor_recovery_codes'] = ['type' => 'TEXT', 'null' => true];
            $columns['two_factor_confirmed_at']   = ['type' => 'DATETIME', 'null' => true];
            $columns['two_factor_last_step']      = ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true];
        }
        if (! $this->hasColumn('admins', 'last_login_at')) {
            $columns['last_login_at'] = ['type' => 'DATETIME', 'null' => true];
            $columns['last_login_ip'] = self::string(45, true);
        }
        if ($columns !== []) {
            $this->forge->addColumn('admins', $columns);
        }
    }

    public function down(): void
    {
        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step', 'last_login_at', 'last_login_ip'] as $column) {
            if ($this->hasColumn('admins', $column)) {
                $this->forge->dropColumn('admins', $column);
            }
        }
    }
}
