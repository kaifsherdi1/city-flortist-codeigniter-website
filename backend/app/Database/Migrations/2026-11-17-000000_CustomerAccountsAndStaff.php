<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;

/**
 * Customer accounts on the website (separate from the dashboard login), orders linked to the customer who
 * placed them, and staff accounts for the dashboard that stay locked until the owner approves them.
 */
class CustomerAccountsAndStaff extends FloristMigration
{
    public function up(): void
    {
        if (! $this->hasTable('customers')) {
            $this->forge->addField([
                'id'             => self::id(),
                'username'       => self::string(40),
                'email'          => self::string(190),
                'phone'          => self::string(20, true),
                'password_hash'  => self::string(255, true), // empty for accounts made with Google only
                'google_id'      => self::string(64, true),
                'remember_token' => self::string(100, true),
                'last_login_at'  => ['type' => 'DATETIME', 'null' => true],
                'created_at'     => ['type' => 'TIMESTAMP', 'null' => true],
                'updated_at'     => ['type' => 'TIMESTAMP', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('username', 'customers_username_unique');
            $this->forge->addUniqueKey('email', 'customers_email_unique');
            $this->forge->addUniqueKey('phone', 'customers_phone_unique');
            $this->forge->addUniqueKey('google_id', 'customers_google_id_unique');
            $this->forge->createTable('customers', true, self::TABLE);
        }

        if ($this->hasTable('orders') && ! $this->hasColumn('orders', 'customer_id')) {
            $this->forge->addColumn('orders', ['customer_id' => self::foreignId(true) + ['after' => 'id']]);
            $this->db->query(
                'ALTER TABLE `orders` ADD CONSTRAINT `orders_customer_id_foreign` FOREIGN KEY (`customer_id`) '
                . 'REFERENCES `customers` (`id`) ON DELETE SET NULL',
            );
        }

        if ($this->hasTable('admins') && ! $this->hasColumn('admins', 'role')) {
            // Accounts that already exist are the shop owner's.
            $this->forge->addColumn('admins', [
                'role'   => self::string(10, false, 'owner'),    // owner | staff
                'status' => self::string(10, false, 'active'),   // active | pending | disabled
            ]);
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey('orders', 'orders_customer_id_foreign')) {
            $this->db->query('ALTER TABLE `orders` DROP FOREIGN KEY `orders_customer_id_foreign`');
        }
        if ($this->hasColumn('orders', 'customer_id')) {
            $this->forge->dropColumn('orders', 'customer_id');
        }
        $this->forge->dropTable('customers', true);
        foreach (['role', 'status'] as $column) {
            if ($this->hasColumn('admins', $column)) {
                $this->forge->dropColumn('admins', $column);
            }
        }
    }
}
