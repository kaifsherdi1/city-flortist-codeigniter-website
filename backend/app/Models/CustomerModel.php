<?php

namespace App\Models;

use App\Libraries\Text;
use CodeIgniter\Model;

/** A customer account on the website. Logged in with service('customerAuth'), never the dashboard's login. */
class CustomerModel extends Model
{
    protected $table         = 'customers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['username', 'email', 'phone', 'password_hash', 'google_id', 'remember_token', 'last_login_at'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected array $casts   = ['id' => 'int'];

    /** Username, email or mobile number, whichever the customer typed. */
    public function findForLogin(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        if (str_contains($login, '@')) {
            return $this->where('email', strtolower($login))->first();
        }
        if (($phone = Text::normalizePhone($login)) && ($byPhone = $this->where('phone', $phone)->first())) {
            return $byPhone;
        }

        return $this->where('username', $login)->first();
    }

    /** A free username made from a name or email ("priya.patil@…" → "priya.patil", then "priya.patil2"…). */
    public function freeUsername(string $from): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '', (string) strtok($from, '@'))) ?: 'customer';
        $base = substr($base, 0, 32);
        $name = $base;
        for ($i = 2; $this->where('username', $name)->countAllResults() > 0; $i++) {
            $name = $base . $i;
        }

        return $name;
    }

    public static function toPublic(array $customer): array
    {
        return [
            'id'           => (int) $customer['id'],
            'username'     => $customer['username'],
            'email'        => $customer['email'],
            'phone'        => $customer['phone'] ?? null,
            'has_password' => ! empty($customer['password_hash']),
            'google'       => ! empty($customer['google_id']),
        ];
    }
}
