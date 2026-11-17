<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Libraries\Images;
use App\Libraries\Installer;
use App\Libraries\Passwords;
use App\Libraries\Settings as ShopSettings;
use App\Libraries\Text;
use App\Models\AdminModel;
use App\Models\CategoryModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Settings: shop details and categories (owner only, see the admin:owner routes), and every login's own details
 * and password.
 */
class Settings extends ApiController
{
    /** GET /api/admin/settings */
    public function show(): ResponseInterface
    {
        return $this->json([
            'account'    => AdminModel::toPublic(service('adminAuth')->user()),
            'settings'   => self::editableSettings(),
            'categories' => self::categories(),
        ]);
    }

    /** PUT /api/admin/settings/shop */
    public function shop(): ResponseInterface
    {
        $values = [];

        foreach (ShopSettings::EDITABLE_KEYS as $key) {
            $value = $this->str($key, 1000);
            if ($key === 'whatsapp_number') {
                $value = Text::digits($value);
                if (strlen($value) === 10) {
                    $value = '91' . $value;
                }
                if ($value === '') {
                    $this->fail('Please enter the WhatsApp number orders are sent to.');
                }
            }
            // People often paste the whole <iframe> from Google Maps; keep only its link.
            if ($key === 'map_embed' && preg_match('/src="([^"]+)"/', $value, $m)) {
                $value = html_entity_decode($m[1]);
            }
            $values[$key] = $value;
        }

        // These become links and an embedded map on the public website, so only real web
        // addresses are allowed (a "javascript:" link would run code in visitors' browsers).
        $links     = ['map_embed' => 'Google Maps embed link', 'map_link' => 'Google Maps link', 'instagram' => 'Instagram link', 'facebook' => 'Facebook link'];
        $httpsOnly = ['map_embed', 'map_link'];

        foreach ($links as $key => $label) {
            if ($values[$key] !== '' && ! self::isWebUrl($values[$key], in_array($key, $httpsOnly, true))) {
                $this->fail("The {$label} must be a web address starting with " . (in_array($key, $httpsOnly, true) ? 'https://' : 'https:// or http://') . '.');
            }
        }
        if ($values['google_review_link'] !== '' && (! self::isWebUrl($values['google_review_link'], true)
            || ! preg_match('/^((www\.|search\.|maps\.)?google\.[a-z]{2,3}(\.[a-z]{2})?|g\.page|maps\.app\.goo\.gl)$/i', (string) parse_url($values['google_review_link'], PHP_URL_HOST)))) {
            $this->fail('The Google review link must be a Google link (Google listing → Ask for reviews → copy link).');
        }
        if ($values['google_rating'] !== '') {
            $r = (float) str_replace(',', '.', $values['google_rating']);
            if ($r < 1 || $r > 5) {
                $this->fail('The Google rating must be between 1 and 5, e.g. 4.7 (or leave it empty).');
            }
            $values['google_rating'] = number_format($r, 1);
        }
        if ($values['google_review_count'] !== '') {
            $values['google_review_count'] = (string) max(0, (int) preg_replace('/\D/', '', $values['google_review_count']));
        }
        // "Get directions" opens this, so it must really be Google Maps (incl. the maps.app.goo.gl share links).
        if ($values['map_link'] !== '' && ! preg_match('/^((www\.|maps\.)?google\.[a-z]{2,3}(\.[a-z]{2})?|maps\.app\.goo\.gl|goo\.gl)$/i', (string) parse_url($values['map_link'], PHP_URL_HOST))) {
            $this->fail('The Google Maps link must come from Google Maps (Share → Copy link).');
        }
        // The map is embedded in a frame on every visitor's screen, so only Google Maps may fill it.
        if ($values['map_embed'] !== '' && ! preg_match('/^(www\.|maps\.)?google\.[a-z]{2,3}(\.[a-z]{2})?$/i', (string) parse_url($values['map_embed'], PHP_URL_HOST))) {
            $this->fail('The map link must be a Google Maps link (google.com/maps…).');
        }
        if ($values['email'] !== '' && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $this->fail('Please enter a valid email address (or leave it empty).');
        }

        foreach ($values as $key => $value) {
            ShopSettings::put($key, $value);
        }

        return $this->json(['message' => 'Shop details saved.', 'settings' => self::editableSettings()]);
    }

    /** POST /api/admin/settings/categories (multipart: cat[id][name|tagline|sort], cover[id]) */
    public function categoriesSave(): ResponseInterface
    {
        foreach ((array) $this->input('cat', []) as $id => $c) {
            // Straight to the table: categories have no timestamps.
            (new CategoryModel())->builder()->where('id', (int) $id)->update([
                'name'    => mb_substr(trim(self::text(self::field($c, 'name'))), 0, 120) ?: 'Category',
                'tagline' => mb_substr(trim(self::text(self::field($c, 'tagline'))), 0, 255),
                'sort'    => (int) (self::field($c, 'sort') ?? 0),
            ]);
        }

        $warnings = [];
        $covers   = $this->request->getFiles()['cover'] ?? [];

        foreach (is_array($covers) ? $covers : [] as $id => $file) {
            // An empty file box (no photo chosen) is not an upload.
            if (! $file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $category = (new CategoryModel())->find((int) $id);
            if (! $category) {
                continue;
            }
            if (! self::uploadOk($file)) {
                $warnings[] = $category['name'] . ' cover: the photo is larger than the server allows (' . ini_get('upload_max_filesize') . 'B).';

                continue;
            }

            try {
                [$path, $thumb] = Images::store($file->getTempName(), 'categories');
                // A category only shows the large photo: drop the new thumbnail and the old cover.
                Images::delete($thumb, $category['cover_image'] ?? null);
                (new CategoryModel())->builder()->where('id', (int) $category['id'])->update(['cover_image' => $path]);
            } catch (Throwable $e) {
                $warnings[] = $category['name'] . ' cover: ' . $e->getMessage();
            }
        }

        return $this->json(['message' => 'Categories saved.', 'warnings' => $warnings, 'categories' => self::categories()]);
    }

    /** PUT /api/admin/settings/account {username, email, phone, current_password}: the login details. */
    public function account(): ResponseInterface
    {
        $admin = service('adminAuth')->user();
        if (! Passwords::check(self::text($this->input('current_password', '')), $admin['password_hash'] ?? null)) {
            $this->fail('Your current password is wrong.');
        }

        $username = trim(self::text($this->input('username', '')));
        $email    = mb_strtolower(trim(self::text($this->input('email', ''))), 'UTF-8');
        $phoneRaw = trim(self::text($this->input('phone', '')));
        $phone    = $phoneRaw === '' ? null : Text::normalizePhone($phoneRaw);

        if (! preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
            $this->fail('The username must be 3–60 letters, numbers, dots, dashes or underscores.');
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Please enter a valid email address (or leave it empty).');
        }
        if ($phoneRaw !== '' && ! $phone) {
            $this->fail('Please enter a valid 10-digit mobile number (or leave it empty).');
        }

        $id = (int) $admin['id'];

        foreach (['username' => $username, 'email' => $email ?: null, 'phone' => $phone] as $col => $value) {
            if ($value !== null && (new AdminModel())->builder()->where($col, $value)->where('id !=', $id)->countAllResults() > 0) {
                $this->fail("That {$col} is already used by another login.");
            }
        }

        // Straight to the table: admins have no updated_at.
        (new AdminModel())->builder()->where('id', $id)->update(['username' => $username, 'email' => $email ?: null, 'phone' => $phone]);

        return $this->json(['message' => 'Account details saved.', 'admin' => AdminModel::toPublic(service('adminAuth')->refresh() ?? $admin)]);
    }

    /** PUT /api/admin/settings/password {current_password, new_password, confirm_password} */
    public function password(): ResponseInterface
    {
        $auth  = service('adminAuth');
        $admin = $auth->user();
        $new   = self::text($this->input('new_password', ''));

        if (! Passwords::check(self::text($this->input('current_password', '')), $admin['password_hash'] ?? null)) {
            $this->fail('Current password is wrong.');
        }
        if (strlen($new) < 8) {
            $this->fail('New password must be at least 8 characters.');
        }
        if ($new !== self::text($this->input('confirm_password', ''))) {
            $this->fail('The two new passwords do not match.');
        }
        if (Installer::isStartingPassword($new) || Passwords::check($new, $admin['password_hash'] ?? null)) {
            $this->fail('Please choose a new password, not the starting one or the one you have now.');
        }

        // A new remember token ends every "remember me" login. Every other open session ends on its next request:
        // the admin filter sees the password changed since it logged in (Laravel's auth.session).
        (new AdminModel())->builder()->where('id', (int) $admin['id'])->update([
            'password_hash'  => Passwords::hash($new),
            'remember_token' => Text::random(60),
        ]);
        session()->remove('must_change_password');

        // This browser stays logged in: remember the new password for it (auth.session did that after the request).
        $fresh = $auth->refresh();
        if ($fresh !== null) {
            $auth->storePasswordFingerprint($fresh);
        }

        return $this->json(['message' => 'Password changed. Any other device that was logged in has been signed out.']);
    }

    // ------------------------------------------------------------------------------------------------------

    /** A real web address: http(s) with a host (https only when $httpsOnly). */
    private static function isWebUrl(string $url, bool $httpsOnly = false): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = parse_url($url, PHP_URL_HOST);

        return filter_var($url, FILTER_VALIDATE_URL) !== false && $host
            && ($scheme === 'https' || (! $httpsOnly && $scheme === 'http'));
    }

    /** The settings the owner edits, in the order Settings::all() keeps them. */
    private static function editableSettings(): array
    {
        return array_intersect_key(ShopSettings::all(), array_flip(ShopSettings::EDITABLE_KEYS));
    }

    private static function categories(): array
    {
        $rows = (new CategoryModel())->orderBy('sort')->orderBy('id')->findAll();

        return array_map(static fn (array $c): array => [
            'id'      => (int) $c['id'],
            'name'    => $c['name'],
            'slug'    => $c['slug'],
            'tagline' => $c['tagline'] ?? '',
            'sort'    => (int) $c['sort'],
            'cover'   => $c['cover_image'] ? '/' . $c['cover_image'] : null,
        ], $rows);
    }

    /** One field of a cat[id] row, like Laravel's $c['name'] ?? null (a row that isn't a list of fields has none). */
    private static function field(mixed $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : null;
    }

    /** A request field read as text, like Laravel's (string) $request->input(...); arrays count as empty. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
