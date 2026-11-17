<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;

/**
 * Lets the owner log in with an email or phone number as well as the username, stay logged in
 * ("Remember me"), and reset a forgotten password by email.
 */
class AddLoginOptionsToAdmins extends FloristMigration
{
    public function up(): void
    {
        $columns = [];
        if (! $this->hasColumn('admins', 'email')) {
            $columns['email'] = self::string(190, true);
        }
        if (! $this->hasColumn('admins', 'phone')) {
            $columns['phone'] = self::string(20, true);
        }
        if (! $this->hasColumn('admins', 'remember_token')) {
            $columns['remember_token'] = self::string(100, true);
        }
        if ($columns !== []) {
            $this->forge->addColumn('admins', $columns);
        }

        if (! $this->hasIndex('admins', 'admins_email_unique')) {
            $this->db->query('ALTER TABLE `admins` ADD UNIQUE `admins_email_unique` (`email`)');
        }
        if (! $this->hasIndex('admins', 'admins_phone_unique')) {
            $this->db->query('ALTER TABLE `admins` ADD UNIQUE `admins_phone_unique` (`phone`)');
        }

        if (! $this->hasTable('password_reset_tokens')) {
            $this->forge->addField([
                'email'      => self::string(),
                'token'      => self::string(),
                'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('email');
            $this->forge->createTable('password_reset_tokens', true, self::TABLE);
        }
    }

    public function down(): void
    {
        foreach (['admins_email_unique', 'admins_phone_unique'] as $index) {
            if ($this->hasIndex('admins', $index)) {
                $this->db->query("ALTER TABLE `admins` DROP INDEX `{$index}`");
            }
        }
        foreach (['email', 'phone', 'remember_token'] as $column) {
            if ($this->hasColumn('admins', $column)) {
                $this->forge->dropColumn('admins', $column);
            }
        }
        $this->forge->dropTable('password_reset_tokens', true);
    }
}
