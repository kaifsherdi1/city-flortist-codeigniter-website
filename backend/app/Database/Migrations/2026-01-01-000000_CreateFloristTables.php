<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;

/**
 * The shop's tables. Each one is only created if it's missing, so a database from the Laravel version or the
 * PHP site before it (same tables and columns) is adopted as it is, with all its data.
 */
class CreateFloristTables extends FloristMigration
{
    public function up(): void
    {
        if (! $this->hasTable('admins')) {
            $this->forge->addField([
                'id'            => self::id(),
                'username'      => self::string(60),
                'password_hash' => self::string(),
                'created_at'    => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('username', 'admins_username_unique');
            $this->forge->createTable('admins', true, self::TABLE);
        }

        if (! $this->hasTable('categories')) {
            $this->forge->addField([
                'id'          => self::id(),
                'slug'        => self::string(80),
                'name'        => self::string(120),
                'tagline'     => self::string(255, true),
                'cover_image' => self::string(255, true),
                'sort'        => ['type' => 'INT', 'default' => 0],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('slug', 'categories_slug_unique');
            $this->forge->createTable('categories', true, self::TABLE);
        }

        if (! $this->hasTable('products')) {
            $this->forge->addField([
                'id'          => self::id(),
                'category_id' => self::foreignId(),
                'name'        => self::string(160),
                'slug'        => self::string(180),
                'description' => ['type' => 'TEXT', 'null' => true],
                'price'       => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
                'sale_price'  => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
                'is_featured' => ['type' => 'TINYINT', 'default' => 0],
                'is_active'   => ['type' => 'TINYINT', 'default' => 1],
                'sort'        => ['type' => 'INT', 'default' => 0],
                'created_at'  => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('slug', 'products_slug_unique');
            $this->forge->addForeignKey('category_id', 'categories', 'id', '', 'CASCADE', 'products_category_id_foreign');
            $this->forge->createTable('products', true, self::TABLE);
        }

        if (! $this->hasTable('product_images')) {
            $this->forge->addField([
                'id'         => self::id(),
                'product_id' => self::foreignId(),
                'path'       => self::string(),
                'thumb_path' => self::string(),
                'sort'       => ['type' => 'INT', 'default' => 0],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addForeignKey('product_id', 'products', 'id', '', 'CASCADE', 'product_images_product_id_foreign');
            $this->forge->createTable('product_images', true, self::TABLE);
        }

        if (! $this->hasTable('offers')) {
            $this->forge->addField([
                'id'           => self::id(),
                'title'        => self::string(160),
                'subtitle'     => self::string(255, true),
                'discount_pct' => ['type' => 'INT', 'default' => 0],
                'product_id'   => self::foreignId(true),
                'category_id'  => self::foreignId(true),
                'image'        => self::string(255, true),
                'starts_at'    => ['type' => 'DATETIME'],
                'ends_at'      => ['type' => 'DATETIME'],
                'is_active'    => ['type' => 'TINYINT', 'default' => 1],
                'created_at'   => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addForeignKey('product_id', 'products', 'id', '', 'SET NULL', 'offers_product_id_foreign');
            $this->forge->addForeignKey('category_id', 'categories', 'id', '', 'SET NULL', 'offers_category_id_foreign');
            $this->forge->createTable('offers', true, self::TABLE);
        }

        if (! $this->hasTable('orders')) {
            $this->forge->addField([
                'id'             => self::id(),
                'product_id'     => self::foreignId(true),
                'product_name'   => self::string(160),
                'price_at_order' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
                'customer_name'  => self::string(120),
                'customer_phone' => self::string(20),
                'note'           => ['type' => 'TEXT', 'null' => true],
                'delivery_date'  => self::string(20, true),
                'status'         => self::string(20, false, 'new'),
                'created_at'     => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addForeignKey('product_id', 'products', 'id', '', 'SET NULL', 'orders_product_id_foreign');
            $this->forge->createTable('orders', true, self::TABLE);
        }

        if (! $this->hasTable('settings')) {
            $this->forge->addField([
                'name'  => self::string(80),
                'value' => ['type' => 'TEXT', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('name');
            $this->forge->createTable('settings', true, self::TABLE);
        }
    }

    public function down(): void
    {
        $this->db->disableForeignKeyChecks();
        foreach (['settings', 'orders', 'offers', 'product_images', 'products', 'categories', 'admins'] as $table) {
            $this->forge->dropTable($table, true);
        }
        $this->db->enableForeignKeyChecks();
    }
}
