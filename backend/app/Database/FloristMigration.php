<?php

namespace App\Database;

use CodeIgniter\Database\Migration;

/**
 * Helpers for City Florist's migrations. Every migration checks before it changes anything (table missing? column
 * missing?), so it can run on a database made by the Laravel version of this site, or by the PHP site before it,
 * and simply adopts what is already there.
 */
abstract class FloristMigration extends Migration
{
    /** Table options, as Laravel created them (InnoDB, utf8mb4_unicode_ci from the connection). */
    protected const TABLE = ['ENGINE' => 'InnoDB'];

    /** Laravel's $table->id(): BIGINT UNSIGNED AUTO_INCREMENT. */
    protected static function id(): array
    {
        return ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true];
    }

    /** Laravel's foreignId(): BIGINT UNSIGNED. */
    protected static function foreignId(bool $nullable = false): array
    {
        return ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => $nullable];
    }

    protected static function string(int $length = 255, bool $nullable = false, ?string $default = null): array
    {
        $field = ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $nullable];
        if ($default !== null) {
            $field['default'] = $default;
        }

        return $field;
    }

    protected function hasTable(string $table): bool
    {
        return $this->db->tableExists($table, false);
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && in_array($column, $this->db->getFieldNames($table), true);
    }

    protected function hasIndex(string $table, string $name): bool
    {
        return $this->hasTable($table) && array_key_exists($name, $this->db->getIndexData($table));
    }

    protected function hasForeignKey(string $table, string $name): bool
    {
        return $this->hasTable($table) && array_key_exists($name, $this->db->getForeignKeyData($table));
    }

    /** A setting's saved value, or null when it was never saved. */
    protected function setting(string $name): ?string
    {
        $row = $this->db->table('settings')->select('value')->where('name', $name)->get()->getRowArray();

        return $row === null ? null : $row['value'];
    }

    /** Saves a setting (Laravel's updateOrInsert). */
    protected function putSetting(string $name, ?string $value): void
    {
        $this->db->query(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, $value],
        );
    }
}
