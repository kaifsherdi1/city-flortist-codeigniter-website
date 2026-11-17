<?php

namespace App\Database\Migrations;

use App\Database\FloristMigration;
use App\Libraries\Settings;
use App\Libraries\Text;
use Config\Florist;

/**
 * The owner's email and mobile: added to the first admin's login details (only where still empty) and as the
 * shop email shown on the website (only if none was saved). Values the owner already set are kept.
 */
class OwnerEmailAndPhone extends FloristMigration
{
    public function up(): void
    {
        $config = config(Florist::class);
        $email  = strtolower(trim($config->adminEmail));
        $phone  = Text::normalizePhone($config->adminPhone);

        if ($this->hasTable('admins') && $this->hasColumn('admins', 'email')) {
            $admin = $this->db->table('admins')->orderBy('id')->limit(1)->get()->getRowArray();
            if ($admin) {
                $set = [];
                if (empty($admin['email']) && $email !== '' && $this->db->table('admins')->where('email', $email)->countAllResults() === 0) {
                    $set['email'] = $email;
                }
                if (empty($admin['phone']) && $phone && $this->db->table('admins')->where('phone', $phone)->countAllResults() === 0) {
                    $set['phone'] = $phone;
                }
                if ($set !== []) {
                    $this->db->table('admins')->where('id', $admin['id'])->update($set);
                }
            }
        }

        if ($this->hasTable('settings') && $email !== '') {
            $current = $this->setting('email');
            if ($current === null || $current === '') {
                $this->putSetting('email', $email);
            }
            Settings::flush();
        }
    }

    public function down(): void
    {
        // Contact details the owner keeps; nothing to undo.
    }
}
