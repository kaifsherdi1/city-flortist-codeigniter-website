<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;

/**
 * Customer reviews left on the website. Nothing shows publicly until the owner approves it.
 * The phone number is private: it's only used to mark a review "Verified order" when it matches an order.
 */
class CreateReviews extends FloristMigration
{
    public function up(): void
    {
        if ($this->hasTable('reviews')) {
            return;
        }
        $this->forge->addField([
            'id'         => self::id(),
            'name'       => self::string(60),
            'rating'     => ['type' => 'TINYINT', 'unsigned' => true],
            'text'       => ['type' => 'TEXT'],
            'phone'      => self::string(20, true),
            'verified'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'status'     => self::string(12, false, 'pending'), // pending | approved | hidden
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('status', false, false, 'reviews_status_index');
        $this->forge->createTable('reviews', true, self::TABLE);
    }

    public function down(): void
    {
        $this->forge->dropTable('reviews', true);
    }
}
