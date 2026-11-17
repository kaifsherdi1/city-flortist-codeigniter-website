<?php

namespace App\Libraries\Auth;

use App\Libraries\Crypto;
use CodeIgniter\Session\Handlers\FileHandler;
use Throwable;

/**
 * Session files in writable/session, encrypted with encryption.key (the Laravel version ran with SESSION_ENCRYPT on the
 * live site). A copied session folder then reveals nothing: not who is logged in, nor the CSRF token or a pending
 * two-step login. A file that can't be decrypted (another key, or written before this handler) counts as an empty
 * session, so the person simply logs in again.
 */
class EncryptedSessionHandler extends FileHandler
{
    /** The file content as last read or written, and a hash of what it decrypts to. */
    private string $stored = '';

    private ?string $plainHash = null;

    public function read($id): false|string
    {
        $raw = parent::read($id);
        if ($raw === false) {
            return false;
        }
        $this->stored = $raw;

        $plain = '';
        if ($raw !== '') {
            try {
                $plain = Crypto::decryptString($raw);
            } catch (Throwable) {
                $plain = '';
            }
        }
        $this->plainHash = md5($plain);

        return $plain;
    }

    public function write($id, $data): bool
    {
        // Nothing changed: hand the parent exactly what it read, so it only refreshes the file's time.
        if ($this->plainHash !== null && $this->plainHash === md5($data)) {
            return parent::write($id, $this->stored);
        }

        $encrypted = $data === '' ? '' : Crypto::encryptString($data);
        $written   = parent::write($id, $encrypted);

        $this->stored    = $encrypted;
        $this->plainHash = md5($data);

        return $written;
    }
}
