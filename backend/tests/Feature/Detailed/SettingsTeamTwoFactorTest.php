<?php

namespace Tests\Feature\Detailed;

use App\Libraries\Crypto;
use App\Libraries\Installer;
use App\Libraries\Passwords;
use App\Libraries\Settings;
use App\Libraries\Text;
use App\Libraries\Totp;
use App\Models\AdminModel;
use App\Models\CategoryModel;
use CodeIgniter\Test\TestResponse;
use Config\Florist;
use Config\Services;
use Tests\Support\FeatureTestCase;

/**
 * Admin\Settings (show, shop, categories, account, password), Admin\Team and Admin\TwoFactor, checked against the
 * Laravel version's behaviour: status codes, messages, JSON keys (in order) and types, validation order, limits.
 *
 * @internal
 */
final class SettingsTeamTwoFactorTest extends FeatureTestCase
{
    private const PASSWORD = 'Petals#Hubballi9';

    private const NEW_PASSWORD = 'Roses&Lilies2026';

    /** Settings::EDITABLE_KEYS in the order Settings::all() (the defaults) keeps them, as Laravel answers. */
    private const SETTINGS_KEYS = [
        'whatsapp_number', 'shop_phone', 'email', 'address', 'hours', 'map_embed', 'map_link', 'google_review_link',
        'google_rating', 'google_review_count', 'instagram', 'facebook', 'hero_title', 'hero_subtitle',
    ];

    private const ADMIN_KEYS = ['id', 'username', 'email', 'phone', 'role', 'status', 'two_factor', 'backup_codes_left'];

    private const OWNER_ONLY = 'Only the shop owner can do this.';

    private const OWNER_LOCKED = 'The owner account can’t be changed here.';

    private const WRONG_PASSWORD = 'Your current password is wrong.';

    private const SAME_PASSWORD = 'Please choose a new password, not the starting one or the one you have now.';

    private const MAP_EMBED_HOST = 'The map link must be a Google Maps link (google.com/maps…).';

    private const MAP_LINK_HOST = 'The Google Maps link must come from Google Maps (Share → Copy link).';

    private const REVIEW_LINK = 'The Google review link must be a Google link (Google listing → Ask for reviews → copy link).';

    private const RATING = 'The Google rating must be between 1 and 5, e.g. 4.7 (or leave it empty).';

    private const BAD_EMAIL = 'Please enter a valid email address (or leave it empty).';

    private const WRONG_SETUP_CODE = 'That code is not right. Check the phone’s time is set automatically, then type the newest code.';

    private const WRONG_DISABLE_CODE = 'That code is not right. Use a code from your authenticator app or a backup code.';

    private const START_AGAIN = 'Start again: press “Turn on two-step login”.';

    private const TOO_MANY = 'Too many attempts. Please wait a few minutes.';

    protected function setUp(): void
    {
        parent::setUp();
        // The shared rate limiter would keep the previous test's MockCache (and its attempt counts).
        Services::resetSingle('rateLimiter');
        $this->install();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('rateLimiter');
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------------

    private function row(int $id): ?array
    {
        return (new AdminModel())->find($id);
    }

    /** The installed owner, still on the starting password. */
    private function owner(): array
    {
        return (new AdminModel())->where('role', 'owner')->orderBy('id')->first();
    }

    /** The owner with their own password (self::PASSWORD). */
    private function ownerWithPassword(): array
    {
        $owner = $this->owner();
        (new AdminModel())->builder()->where('id', $owner['id'])->update(['password_hash' => Passwords::hash(self::PASSWORD)]);

        return $this->row((int) $owner['id']);
    }

    private function staff(string $username, string $status, ?string $email = '', ?string $phone = null, string $role = 'staff'): array
    {
        $id = (new AdminModel())->insert([
            'username' => $username, 'email' => $email === '' ? "{$username}@example.com" : $email, 'phone' => $phone,
            'password_hash' => Passwords::hash(self::PASSWORD), 'role' => $role, 'status' => $status,
        ]);

        return $this->row((int) $id);
    }

    private function assertError(TestResponse $res, int $status, string $message): void
    {
        $this->assertStatusCode($res, $status);
        $this->assertSame(['error' => $message], $this->json($res));
    }

    private function shop(array $fields): TestResponse
    {
        return $this->putJson('/api/admin/settings/shop', $fields);
    }

    private function changePassword(string $current, string $new, ?string $confirm = null): TestResponse
    {
        return $this->putJson('/api/admin/settings/password', ['current_password' => $current, 'new_password' => $new, 'confirm_password' => $confirm ?? $new]);
    }

    /** @return list<array<string, mixed>> */
    private function categoryRows(): array
    {
        return (new CategoryModel())->orderBy('id')->findAll();
    }

    private function category(int $id): array
    {
        return (new CategoryModel())->find($id);
    }

    /** A multipart POST with $_FILES written by hand (FeatureTestCase can't fake a photo PHP refused). */
    private function postWithRawFiles(string $path, array $fields, array $files): TestResponse
    {
        $this->carriedSession['_token'] ??= bin2hex(random_bytes(20));
        $superglobals = service('superglobals');
        $superglobals->setServer('REMOTE_ADDR', $this->ip);
        $superglobals->setCookieArray($this->cookieJar);
        $superglobals->setFilesArray($files);
        $superglobals->unsetServer('CONTENT_TYPE');
        Services::resetSingle('response');
        $this->withHeaders([
            'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'X-XSRF-TOKEN' => $this->carriedSession['_token'],
        ])->withSession($this->carriedSession);
        $this->bodyFormat  = '';
        $this->requestBody = '';
        $response          = $this->call('POST', $path, $fields);
        $this->carriedSession = $_SESSION ?? [];
        $superglobals->setFilesArray([]);

        return $response;
    }

    /** A 6-digit code that is not valid for this secret around now. */
    private function wrongCode(string $secret): string
    {
        $now   = intdiv(time(), Totp::PERIOD);
        $valid = array_map(static fn (int $s): string => Totp::code($secret, $s), range($now - 2, $now + 3));
        for ($i = 0; ; $i++) {
            $code = sprintf('%06d', $i);
            if (! in_array($code, $valid, true)) {
                return $code;
            }
        }
    }

    /** Turns two-step login on through the API, the way the Settings screen does. Returns [secret, backup codes, response]. */
    private function enable(array $admin, string $password = self::PASSWORD): array
    {
        $this->actingAsAdmin($admin);
        $setup = $this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => $password]);
        $this->assertStatusCode($setup, 200);
        $secret = str_replace(' ', '', $this->json($setup, 'secret'));
        $res    = $this->postJson('/api/admin/settings/two-factor/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))]);
        $this->assertStatusCode($res, 200);

        return [$secret, $this->json($res, 'recovery_codes'), $res];
    }

    private function attempts(int $adminId): int
    {
        return service('rateLimiter')->attempts('two-factor-settings:' . $adminId);
    }

    // ------------------------------------------------------------------------------------------------------
    // GET /api/admin/settings
    // ------------------------------------------------------------------------------------------------------

    public function test_settings_show_the_account_the_shop_details_and_the_categories(): void
    {
        $owner = $this->owner();
        db_connect()->table('categories')->where('slug', 'cakes')->update(['tagline' => null]);
        $res = $this->actingAsAdmin($owner)->getJson('/api/admin/settings');
        $this->assertStatusCode($res, 200);
        $this->assertSame(['account', 'settings', 'categories'], array_keys($this->json($res)));

        $this->assertSame(self::ADMIN_KEYS, array_keys($this->json($res, 'account')));
        $this->assertSame(AdminModel::toPublic($owner), $this->json($res, 'account'));
        $this->assertJsonPath($res, 'account.id', (int) $owner['id']);
        $this->assertJsonPath($res, 'account.username', 'admin');
        $this->assertJsonPath($res, 'account.role', 'owner');
        $this->assertJsonPath($res, 'account.two_factor', false);
        $this->assertJsonPath($res, 'account.backup_codes_left', null);

        $this->assertSame(self::SETTINGS_KEYS, array_keys($this->json($res, 'settings')));
        $this->assertJsonPath($res, 'settings.whatsapp_number', '919900094843');
        $this->assertJsonPath($res, 'settings.google_rating', '4.7');
        $this->assertJsonPath($res, 'settings.facebook', '');
        $this->assertJsonMissingPath($res, 'settings.shop_name');
        $this->assertJsonMissingPath($res, 'settings.catalog_rev');

        $cats = $this->json($res, 'categories');
        $this->assertCount(8, $cats);
        $rows = $this->categoryRows();
        usort($rows, static fn ($a, $b) => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]);
        $this->assertSame(array_map(static fn ($r) => (int) $r['id'], $rows), array_column($cats, 'id'));
        foreach ($cats as $c) {
            $this->assertSame(['id', 'name', 'slug', 'tagline', 'sort', 'cover'], array_keys($c));
            $this->assertIsInt($c['id']);
            $this->assertIsString($c['name']);
            $this->assertIsString($c['slug']);
            $this->assertIsString($c['tagline']);
            $this->assertIsInt($c['sort']);
            $this->assertNull($c['cover']);
        }
        $cakes = array_values(array_filter($cats, static fn ($c) => $c['slug'] === 'cakes'))[0];
        $this->assertSame('', $cakes['tagline'], 'a missing tagline is an empty string');
    }

    public function test_staff_see_their_own_login_in_settings(): void
    {
        $staff = $this->staff('ravi', 'active', null, '919811111111');
        $res   = $this->actingAsAdmin($staff)->getJson('/api/admin/settings');
        $this->assertStatusCode($res, 200);
        $this->assertSame([
            'id' => (int) $staff['id'], 'username' => 'ravi', 'email' => null, 'phone' => '919811111111',
            'role' => 'staff', 'status' => 'active', 'two_factor' => false, 'backup_codes_left' => null,
        ], $this->json($res, 'account'));
    }

    public function test_settings_need_a_login_and_a_changed_starting_password(): void
    {
        $this->assertError($this->getJson('/api/admin/settings'), 401, 'Please log in again.');

        $this->actingAsAdmin($this->owner());
        $this->carriedSession['must_change_password'] = true;
        $res = $this->getJson('/api/admin/settings');
        $this->assertStatusCode($res, 403);
        $this->assertSame(['error' => 'Please choose a new password first. The starting password is not safe to keep.', 'must_change_password' => true], $this->json($res));
    }

    // ------------------------------------------------------------------------------------------------------
    // PUT /api/admin/settings/shop
    // ------------------------------------------------------------------------------------------------------

    public function test_shop_details_are_cleaned_and_saved(): void
    {
        $this->actingAsAdmin($this->owner());
        $res = $this->shop([
            'whatsapp_number'     => '98765 43210',
            'shop_phone'          => '  +91 98765 43210  ',
            'email'               => 'shop@example.com',
            'address'             => 'MG Road, Hubballi',
            'hours'               => 'Open daily',
            'map_embed'           => '<iframe src="https://www.google.com/maps/embed?pb=!1m18&amp;hl=en" width="600" height="450"></iframe>',
            'map_link'            => 'https://maps.app.goo.gl/AbC123',
            'google_review_link'  => 'https://g.page/r/abc/review',
            'google_rating'       => '4,7',
            'google_review_count' => '40 reviews',
            'instagram'           => 'https://instagram.com/cityflorist',
            'facebook'            => 'http://facebook.com/cityflorist',
            'hero_title'          => 'Fresh flowers',
            'hero_subtitle'       => str_repeat('é', 1200),
        ]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message', 'settings'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'message', 'Shop details saved.');
        $this->assertSame(self::SETTINGS_KEYS, array_keys($this->json($res, 'settings')));
        $expected = [
            'whatsapp_number'     => '919876543210',
            'shop_phone'          => '+91 98765 43210',
            'email'               => 'shop@example.com',
            'address'             => 'MG Road, Hubballi',
            'hours'               => 'Open daily',
            'map_embed'           => 'https://www.google.com/maps/embed?pb=!1m18&hl=en',
            'map_link'            => 'https://maps.app.goo.gl/AbC123',
            'google_review_link'  => 'https://g.page/r/abc/review',
            'google_rating'       => '4.7',
            'google_review_count' => '40',
            'instagram'           => 'https://instagram.com/cityflorist',
            'facebook'            => 'http://facebook.com/cityflorist',
            'hero_title'          => 'Fresh flowers',
            'hero_subtitle'       => str_repeat('é', 1000),
        ];
        $this->assertSame($expected, $this->json($res, 'settings'));

        Settings::flush();
        foreach ($expected as $key => $value) {
            $this->assertSame($value, Settings::get($key), $key);
        }
        $this->assertSame('City Florist', Settings::get('shop_name'), 'the shop name is not editable here');

        $site = $this->getJson('/api/site');
        $this->assertJsonPath($site, 'settings.whatsapp_number', '919876543210');
        $this->assertJsonPath($site, 'settings.map_embed', 'https://www.google.com/maps/embed?pb=!1m18&hl=en');
        $this->assertJsonPath($site, 'settings.google_rating', '4.7');
        $this->assertJsonPath($site, 'settings.google_review_count', '40');
    }

    public function test_fields_left_out_are_cleared(): void
    {
        $this->actingAsAdmin($this->owner());
        $res = $this->shop(['whatsapp_number' => '919876543210', 'facebook' => null, 'hours' => '   ']);
        $this->assertStatusCode($res, 200);
        foreach (self::SETTINGS_KEYS as $key) {
            $this->assertSame($key === 'whatsapp_number' ? '919876543210' : '', $this->json($res, "settings.{$key}"), $key);
        }
    }

    public function test_whatsapp_number_is_required_and_gets_the_country_code(): void
    {
        $this->actingAsAdmin($this->owner());
        foreach (['', '   ', 'call me', null] as $bad) {
            $this->assertError($this->shop(['whatsapp_number' => $bad]), 422, 'Please enter the WhatsApp number orders are sent to.');
        }
        $this->assertError($this->shop([]), 422, 'Please enter the WhatsApp number orders are sent to.');

        foreach (['9876543210' => '919876543210', '+91 98765-43210' => '919876543210', '12345' => '12345', '0091 98765 43210' => '00919876543210'] as $typed => $saved) {
            $res = $this->shop(['whatsapp_number' => $typed]);
            $this->assertStatusCode($res, 200);
            $this->assertJsonPath($res, 'settings.whatsapp_number', $saved);
        }
        // A JSON number works too.
        $this->assertJsonPath($this->shop(['whatsapp_number' => 9876543210]), 'settings.whatsapp_number', '919876543210');
    }

    public function test_map_must_be_a_google_maps_link(): void
    {
        $this->actingAsAdmin($this->owner());
        $base = ['whatsapp_number' => '919876543210'];
        $this->assertError($this->shop($base + ['map_embed' => 'https://evil.example.com/phish']), 422, self::MAP_EMBED_HOST);
        $this->assertError($this->shop($base + ['map_embed' => 'https://www.google.com.evil.io/maps']), 422, self::MAP_EMBED_HOST);
        $this->assertError($this->shop($base + ['map_embed' => '<iframe src="https://evil.example/maps"></iframe>']), 422, self::MAP_EMBED_HOST);
        $res = $this->shop($base + ['map_embed' => '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'settings.map_embed', 'https://www.google.com/maps/embed?pb=1');
        $this->assertStatusCode($this->shop($base + ['map_embed' => 'https://maps.google.co.in/maps?q=x&output=embed']), 200);

        // "Get directions" link: Google Maps only (share links included).
        $this->assertError($this->shop($base + ['map_link' => 'https://evil.example.com/maps']), 422, self::MAP_LINK_HOST);
        $this->assertError($this->shop($base + ['map_link' => 'javascript:alert(1)']), 422, 'The Google Maps link must be a web address starting with https://.');
        $this->assertError($this->shop($base + ['map_link' => 'http://maps.google.com/x']), 422, 'The Google Maps link must be a web address starting with https://.');
        $this->assertStatusCode($this->shop($base + ['map_link' => 'https://goo.gl/maps/xyz']), 200);
        $this->assertStatusCode($this->shop($base + ['map_link' => 'https://maps.app.goo.gl/AbC123']), 200);
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.map_link', 'https://maps.app.goo.gl/AbC123');
    }

    public function test_unsafe_links_are_refused(): void
    {
        $this->actingAsAdmin($this->owner());
        foreach ([
            [['instagram' => 'javascript:alert(1)'], 'The Instagram link must be a web address starting with https:// or http://.'],
            [['facebook' => 'data:text/html,<script>alert(1)</script>'], 'The Facebook link must be a web address starting with https:// or http://.'],
            [['facebook' => 'facebook.com/cityflorist'], 'The Facebook link must be a web address starting with https:// or http://.'],
            [['map_embed' => 'javascript:alert(1)'], 'The Google Maps embed link must be a web address starting with https://.'],
            [['map_embed' => 'http://maps.example/e'], 'The Google Maps embed link must be a web address starting with https://.'],
            [['email' => 'not-an-email'], self::BAD_EMAIL],
        ] as [$bad, $message]) {
            $this->assertError($this->shop(['whatsapp_number' => '919876543210'] + $bad), 422, $message);
        }
        $this->assertStatusCode($this->shop(['whatsapp_number' => '919876543210', 'instagram' => 'https://instagram.com/cityflorist', 'email' => 'shop@example.com']), 200);
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.instagram', 'https://instagram.com/cityflorist');
    }

    public function test_google_review_settings(): void
    {
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.google_review_link', static fn ($l) => str_starts_with($l, 'https://search.google.com/local/writereview?placeid='));

        $this->actingAsAdmin($this->owner());
        $base = ['whatsapp_number' => '919900094843'];
        $this->assertError($this->shop($base + ['google_review_link' => 'https://evil.example/review']), 422, self::REVIEW_LINK);
        $this->assertError($this->shop($base + ['google_review_link' => 'http://g.page/r/abc/review']), 422, self::REVIEW_LINK);
        $this->assertError($this->shop($base + ['google_review_link' => 'g.page/r/abc/review']), 422, self::REVIEW_LINK);
        $this->assertError($this->shop($base + ['google_rating' => '7']), 422, self::RATING);
        $this->assertError($this->shop($base + ['google_rating' => '0.5']), 422, self::RATING);
        $this->assertError($this->shop($base + ['google_rating' => 'great']), 422, self::RATING);
        $res = $this->shop($base + ['google_review_link' => 'https://g.page/r/abc/review', 'google_rating' => '4,7', 'google_review_count' => '40 reviews']);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.google_rating', '4.7');
        $this->assertJsonPath($this->getJson('/api/site'), 'settings.google_review_count', '40');

        foreach (['4' => '4.0', '5' => '5.0', '1' => '1.0', '4.75' => '4.8', '4.94' => '4.9'] as $typed => $saved) {
            $this->assertJsonPath($this->shop($base + ['google_rating' => $typed]), 'settings.google_rating', $saved);
        }
        $this->assertJsonPath($this->shop($base + ['google_rating' => 4.2]), 'settings.google_rating', '4.2');
        foreach (['-5' => '5', 'none' => '0', '1,234' => '1234', '12' => '12'] as $typed => $saved) {
            $this->assertJsonPath($this->shop($base + ['google_review_count' => $typed]), 'settings.google_review_count', $saved);
        }
        foreach (['https://search.google.com/local/writereview?placeid=x', 'https://www.google.co.in/maps/place/x', 'https://maps.app.goo.gl/x'] as $ok) {
            $this->assertStatusCode($this->shop($base + ['google_review_link' => $ok]), 200);
        }
    }

    public function test_shop_checks_run_in_the_laravel_order_and_save_nothing_on_failure(): void
    {
        $this->actingAsAdmin($this->owner());
        $badInstagram = ['instagram' => 'javascript:alert(1)'];
        $this->assertError($this->shop(['whatsapp_number' => ''] + $badInstagram), 422, 'Please enter the WhatsApp number orders are sent to.');

        $base = ['whatsapp_number' => '919811111111'];
        $this->assertError($this->shop($base + $badInstagram + ['email' => 'bad']), 422, 'The Instagram link must be a web address starting with https:// or http://.');
        $this->assertError($this->shop($base + ['map_embed' => 'http://evil.example/x', 'map_link' => 'javascript:x']), 422, 'The Google Maps embed link must be a web address starting with https://.');
        $this->assertError($this->shop($base + ['google_review_link' => 'https://evil.example', 'google_rating' => '9']), 422, self::REVIEW_LINK);
        $this->assertError($this->shop($base + ['google_rating' => '9', 'map_link' => 'https://evil.example/x']), 422, self::RATING);
        $this->assertError($this->shop($base + ['map_link' => 'https://evil.example/x', 'map_embed' => 'https://evil.example/y']), 422, self::MAP_LINK_HOST);
        $this->assertError($this->shop($base + ['map_embed' => 'https://evil.example/y', 'email' => 'bad']), 422, self::MAP_EMBED_HOST);

        Settings::flush();
        $this->assertSame('919900094843', Settings::get('whatsapp_number'), 'nothing is saved when a check fails');
        $this->assertSame('4.7', Settings::get('google_rating'));
    }

    // ------------------------------------------------------------------------------------------------------
    // POST /api/admin/settings/categories
    // ------------------------------------------------------------------------------------------------------

    public function test_categories_are_renamed_and_reordered(): void
    {
        $this->actingAsAdmin($this->owner());
        [$a, $b, $c, $d] = $this->categoryRows();
        $res = $this->postMultipart('/api/admin/settings/categories', ['cat' => [
            $a['id'] => ['name' => '  Roses  ', 'tagline' => ' Fresh every day ', 'sort' => '9'],
            $b['id'] => ['name' => '', 'tagline' => '', 'sort' => ''],
            $c['id'] => ['name' => str_repeat('é', 130), 'tagline' => str_repeat('t', 300), 'sort' => '-2'],
            $d['id'] => ['name' => '0', 'sort' => '2.9'],
            9999     => ['name' => 'Ghost', 'tagline' => 'x', 'sort' => '1'],
        ]]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message', 'warnings', 'categories'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'message', 'Categories saved.');
        $this->assertJsonPath($res, 'warnings', []);

        $this->assertSame(['Roses', 'Fresh every day', 9], [$this->category($a['id'])['name'], $this->category($a['id'])['tagline'], $this->category($a['id'])['sort']]);
        $this->assertSame(['Category', '', 0], [$this->category($b['id'])['name'], $this->category($b['id'])['tagline'], $this->category($b['id'])['sort']]);
        $this->assertSame([str_repeat('é', 120), str_repeat('t', 255), -2], [$this->category($c['id'])['name'], $this->category($c['id'])['tagline'], $this->category($c['id'])['sort']]);
        $this->assertSame(['Category', '', 2], [$this->category($d['id'])['name'], $this->category($d['id'])['tagline'], $this->category($d['id'])['sort']]);
        $this->assertSame(8, db_connect()->table('categories')->countAllResults(), 'no category is created');

        $cats = $this->json($res, 'categories');
        $rows = $this->categoryRows();
        usort($rows, static fn ($x, $y) => [$x['sort'], $x['id']] <=> [$y['sort'], $y['id']]);
        $this->assertSame(array_map(static fn ($r) => (int) $r['id'], $rows), array_column($cats, 'id'));
        $this->assertSame((int) $c['id'], $cats[0]['id'], 'lowest sort first');
        $this->assertSame((int) $a['id'], $cats[7]['id']);
        $this->assertSame(['id' => (int) $b['id'], 'name' => 'Category', 'slug' => $b['slug'], 'tagline' => '', 'sort' => 0, 'cover' => null], array_values(array_filter($cats, static fn ($x) => $x['id'] === (int) $b['id']))[0]);
    }

    public function test_categories_can_be_sent_as_json_too(): void
    {
        $this->actingAsAdmin($this->owner());
        $first = $this->categoryRows()[0];
        $res   = $this->postJson('/api/admin/settings/categories', ['cat' => [$first['id'] => ['name' => 'Bouquets', 'tagline' => 'Hand-tied', 'sort' => 3]]]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['Bouquets', 'Hand-tied', 3], [$this->category($first['id'])['name'], $this->category($first['id'])['tagline'], $this->category($first['id'])['sort']]);

        // Nothing sent: nothing changes.
        $res = $this->postJson('/api/admin/settings/categories', []);
        $this->assertStatusCode($res, 200);
        $this->assertJsonCount($res, 8, 'categories');
        $this->assertJsonPath($res, 'warnings', []);
    }

    public function test_a_category_cover_photo_replaces_the_old_one(): void
    {
        $this->actingAsAdmin($this->owner());
        $cat = $this->categoryRows()[2];
        $res = $this->postMultipart('/api/admin/settings/categories', [], ['cover' => [$cat['id'] => $this->photo(800, 600)]]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'warnings', []);

        $cover = $this->category($cat['id'])['cover_image'];
        $this->assertMatchesRegularExpression('#^uploads/categories/\d{8}-[0-9a-f]{10}\.webp$#', $cover);
        $this->assertFileExists($this->publicFile('/' . $cover));
        $this->assertFileDoesNotExist($this->publicFile('/' . str_replace('.webp', '-thumb.webp', $cover)), 'the thumbnail is not kept');
        $this->assertSame(800, getimagesize($this->publicFile('/' . $cover))[0]);
        $item = array_values(array_filter($this->json($res, 'categories'), static fn ($x) => $x['id'] === (int) $cat['id']))[0];
        $this->assertSame('/' . $cover, $item['cover']);

        // A new photo replaces it, and the old file goes.
        $res = $this->postMultipart('/api/admin/settings/categories', ['cat' => [$cat['id'] => ['name' => 'Cars', 'tagline' => 'Decorated', 'sort' => '1']]], ['cover' => [$cat['id'] => $this->photo(300, 200)]]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'warnings', []);
        $newCover = $this->category($cat['id'])['cover_image'];
        $this->assertNotSame($cover, $newCover);
        $this->assertFileDoesNotExist($this->publicFile('/' . $cover));
        $this->assertFileExists($this->publicFile('/' . $newCover));
        $this->assertSame('Cars', $this->category($cat['id'])['name']);
        $this->assertSame(['/' . $newCover], array_values(array_map(static fn ($x) => $x['cover'], array_filter($this->json($res, 'categories'), static fn ($x) => $x['cover'] !== null))));
    }

    public function test_a_cover_that_is_not_a_photo_gives_a_warning(): void
    {
        $this->actingAsAdmin($this->owner());
        $cat  = $this->categoryRows()[0];
        $text = tempnam(sys_get_temp_dir(), 'txt') . '.jpg';
        file_put_contents($text, 'not a photo');
        $res = $this->postMultipart('/api/admin/settings/categories', ['cat' => [$cat['id'] => ['name' => 'Bouquets', 'tagline' => '', 'sort' => '0']]], ['cover' => [$cat['id'] => $text]]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'warnings', ['Bouquets cover: Not a valid image file.']);
        $this->assertNull($this->category($cat['id'])['cover_image']);
        $this->assertSame('Bouquets', $this->category($cat['id'])['name'], 'the text fields are still saved');
        @unlink($text);
    }

    public function test_a_photo_php_refused_gives_a_warning_under_the_new_name(): void
    {
        $this->actingAsAdmin($this->owner());
        [$cat, $other] = $this->categoryRows();
        $id    = (int) $cat['id'];
        $files = ['cover' => [
            'name'     => [$id => 'huge.jpg', (int) $other['id'] => 'cut.jpg'],
            'type'     => [$id => 'image/jpeg', (int) $other['id'] => 'image/jpeg'],
            'tmp_name' => [$id => '', (int) $other['id'] => ''],
            'error'    => [$id => UPLOAD_ERR_INI_SIZE, (int) $other['id'] => UPLOAD_ERR_PARTIAL],
            'size'     => [$id => 0, (int) $other['id'] => 0],
        ]];
        $res = $this->postWithRawFiles('/api/admin/settings/categories', ['cat' => [$id => ['name' => 'Wedding Cars', 'tagline' => 'x', 'sort' => '1']]], $files);
        $this->assertStatusCode($res, 200);
        $max = ini_get('upload_max_filesize');
        $this->assertJsonPath($res, 'warnings', [
            "Wedding Cars cover: the photo is larger than the server allows ({$max}B).",
            // Laravel says the same for every upload PHP refused.
            "{$other['name']} cover: the photo is larger than the server allows ({$max}B).",
        ]);
        $this->assertNull($this->category($id)['cover_image']);
    }

    public function test_covers_for_unknown_categories_and_empty_file_boxes_are_ignored(): void
    {
        $this->actingAsAdmin($this->owner());
        $cat = $this->categoryRows()[0];
        $res = $this->postMultipart('/api/admin/settings/categories', [], ['cover' => [9999 => $this->photo(), $cat['id'] => '/no/such/file.jpg']]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'warnings', []);
        $this->assertDirectoryDoesNotExist($this->publicRoot . 'uploads/categories', 'nothing was stored');
        $this->assertNull($this->category($cat['id'])['cover_image']);
    }

    public function test_staff_cannot_change_shop_settings_categories_or_the_team(): void
    {
        $staff = $this->staff('ravi', 'active');
        $owner = $this->owner();
        $this->actingAsAdmin($staff);
        $this->assertError($this->shop(['whatsapp_number' => '919999999999']), 403, self::OWNER_ONLY);
        $this->assertError($this->postJson('/api/admin/settings/categories', []), 403, self::OWNER_ONLY);
        $this->assertError($this->postMultipart('/api/admin/settings/categories', ['cat' => [1 => ['name' => 'x']]], ['cover' => [1 => $this->photo()]]), 403, self::OWNER_ONLY);
        $this->assertError($this->getJson('/api/admin/team'), 403, self::OWNER_ONLY);
        $this->assertError($this->patchJson("/api/admin/team/{$owner['id']}", ['status' => 'disabled']), 403, self::OWNER_ONLY);
        $this->assertError($this->deleteJson("/api/admin/team/{$owner['id']}"), 403, self::OWNER_ONLY);
        $this->assertSame('active', $this->row((int) $owner['id'])['status']);
        $this->assertNotSame('x', $this->category(1)['name']);
        $this->assertNull($this->category(1)['cover_image']);
        $this->assertSame('919900094843', Settings::get('whatsapp_number'));
        // Their own settings still open.
        $this->assertStatusCode($this->getJson('/api/admin/settings'), 200);
    }

    // ------------------------------------------------------------------------------------------------------
    // PUT /api/admin/settings/account
    // ------------------------------------------------------------------------------------------------------

    public function test_account_details_need_the_current_password(): void
    {
        $owner = $this->ownerWithPassword();
        (new AdminModel())->builder()->where('id', $owner['id'])->update(['email' => 'owner@example.com', 'phone' => '919876543210']);
        $this->actingAsAdmin($owner);
        $url = '/api/admin/settings/account';

        $this->assertError($this->putJson($url, ['username' => 'owner', 'email' => 'new@example.com', 'phone' => '9123456789', 'current_password' => 'wrong']), 422, self::WRONG_PASSWORD);
        $this->assertError($this->putJson($url, ['username' => 'a b', 'current_password' => self::PASSWORD]), 422, 'The username must be 3–60 letters, numbers, dots, dashes or underscores.');
        $this->assertError($this->putJson($url, ['username' => 'ab', 'current_password' => self::PASSWORD]), 422, 'The username must be 3–60 letters, numbers, dots, dashes or underscores.');
        $this->assertError($this->putJson($url, ['username' => str_repeat('a', 61), 'current_password' => self::PASSWORD]), 422, 'The username must be 3–60 letters, numbers, dots, dashes or underscores.');
        $this->assertError($this->putJson($url, ['username' => 'owner', 'email' => 'bad', 'current_password' => self::PASSWORD]), 422, self::BAD_EMAIL);
        $this->assertError($this->putJson($url, ['username' => 'owner', 'phone' => '12345', 'current_password' => self::PASSWORD]), 422, 'Please enter a valid 10-digit mobile number (or leave it empty).');
        // The password is checked before anything else, and it is not trimmed.
        $this->assertError($this->putJson($url, ['username' => 'a b', 'email' => 'bad', 'current_password' => ' ' . self::PASSWORD]), 422, self::WRONG_PASSWORD);
        $this->assertSame('admin', $this->row((int) $owner['id'])['username'], 'nothing saved yet');

        $res = $this->putJson($url, ['username' => ' owner ', 'email' => ' New@Example.com ', 'phone' => '91234 56789', 'current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message', 'admin'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'message', 'Account details saved.');
        $this->assertSame([
            'id' => (int) $owner['id'], 'username' => 'owner', 'email' => 'new@example.com', 'phone' => '919123456789',
            'role' => 'owner', 'status' => 'active', 'two_factor' => false, 'backup_codes_left' => null,
        ], $this->json($res, 'admin'));
        $row = $this->row((int) $owner['id']);
        $this->assertSame(['owner', 'new@example.com', '919123456789'], [$row['username'], $row['email'], $row['phone']]);
        $this->assertSame($owner['created_at'], $row['created_at']);
        // Still logged in, and the settings page shows the new details.
        $this->assertJsonPath($this->getJson('/api/admin/settings'), 'account.username', 'owner');
    }

    public function test_account_email_and_phone_can_be_left_empty(): void
    {
        $owner = $this->ownerWithPassword();
        $res   = $this->actingAsAdmin($owner)->putJson('/api/admin/settings/account', ['username' => 'admin', 'email' => '', 'phone' => '  ', 'current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.email', null);
        $this->assertJsonPath($res, 'admin.phone', null);
        $row = $this->row((int) $owner['id']);
        $this->assertNull($row['email']);
        $this->assertNull($row['phone']);
    }

    public function test_account_details_must_not_belong_to_another_login(): void
    {
        $owner = $this->ownerWithPassword();
        $this->staff('ravi', 'pending', 'ravi@example.com', '919811111111');
        $this->actingAsAdmin($owner);
        $url  = '/api/admin/settings/account';
        $form = ['username' => 'admin', 'current_password' => self::PASSWORD];

        $this->assertError($this->putJson($url, ['username' => 'ravi'] + $form), 422, 'That username is already used by another login.');
        $this->assertError($this->putJson($url, ['username' => 'RAVI'] + $form), 422, 'That username is already used by another login.');
        $this->assertError($this->putJson($url, ['email' => 'Ravi@Example.com'] + $form), 422, 'That email is already used by another login.');
        $this->assertError($this->putJson($url, ['phone' => '98111 11111'] + $form), 422, 'That phone is already used by another login.');
        // Username is checked first.
        $this->assertError($this->putJson($url, ['username' => 'ravi', 'email' => 'ravi@example.com'] + $form), 422, 'That username is already used by another login.');
        // Keeping your own details is fine.
        $this->assertStatusCode($this->putJson($url, ['email' => 'cityfloristh@gmail.com', 'phone' => '9900094843'] + $form), 200);
    }

    public function test_staff_change_their_own_login_details(): void
    {
        $staff = $this->staff('ravi', 'active');
        $res   = $this->actingAsAdmin($staff)->putJson('/api/admin/settings/account', ['username' => 'ravi.k', 'email' => 'ravi.k@example.com', 'current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.username', 'ravi.k');
        $this->assertJsonPath($res, 'admin.role', 'staff');
        $this->assertSame('admin', $this->owner()['username']);
    }

    // ------------------------------------------------------------------------------------------------------
    // PUT /api/admin/settings/password
    // ------------------------------------------------------------------------------------------------------

    public function test_password_change_rules(): void
    {
        $owner = $this->ownerWithPassword();
        $this->actingAsAdmin($owner);
        $this->assertError($this->changePassword('wrong', 'abcdefgh'), 422, 'Current password is wrong.');
        $this->assertError($this->changePassword(self::PASSWORD . ' ', 'abcdefgh'), 422, 'Current password is wrong.');
        $this->assertError($this->changePassword(self::PASSWORD, 'abc1234'), 422, 'New password must be at least 8 characters.');
        $this->assertError($this->changePassword(self::PASSWORD, '   abc1234   '), 422, 'New password must be at least 8 characters.');
        $this->assertError($this->changePassword(self::PASSWORD, 'abcdefgh', 'abcdefgX'), 422, 'The two new passwords do not match.');
        $this->assertError($this->changePassword(self::PASSWORD, Installer::DEFAULT_PASSWORD), 422, self::SAME_PASSWORD);
        $this->assertError($this->changePassword(self::PASSWORD, config(Florist::class)->adminPassword), 422, self::SAME_PASSWORD);
        $this->assertError($this->changePassword(self::PASSWORD, self::PASSWORD), 422, self::SAME_PASSWORD);
        // Checked in this order: the current password first.
        $this->assertError($this->changePassword('wrong', 'short', 'other'), 422, 'Current password is wrong.');
        $this->assertError($this->changePassword(self::PASSWORD, 'short', 'other'), 422, 'New password must be at least 8 characters.');
        $this->assertTrue(Passwords::check(self::PASSWORD, $this->row((int) $owner['id'])['password_hash']));

        $res = $this->changePassword(self::PASSWORD, 'abcdefgh');
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'Password changed. Any other device that was logged in has been signed out.'], $this->json($res));
        $this->assertTrue(Passwords::check('abcdefgh', $this->row((int) $owner['id'])['password_hash']));
    }

    public function test_the_new_password_is_trimmed_like_laravel(): void
    {
        $owner = $this->ownerWithPassword();
        $res   = $this->actingAsAdmin($owner)->changePassword(self::PASSWORD, '  ' . self::NEW_PASSWORD . '  ');
        $this->assertStatusCode($res, 200);
        $this->assertTrue(Passwords::check(self::NEW_PASSWORD, $this->row((int) $owner['id'])['password_hash']));
    }

    public function test_starting_password_locks_the_dashboard_until_changed(): void
    {
        $owner = $this->owner(); // still on the starting password
        $start = config(Florist::class)->adminPassword;
        // What logging in with the starting password does.
        $this->actingAsAdmin($owner);
        $this->carriedSession['must_change_password'] = true;

        foreach ([
            $this->getJson('/api/admin/settings'),
            $this->putJson('/api/admin/settings/account', ['username' => 'x', 'current_password' => $start]),
            $this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => $start]),
            $this->shop(['whatsapp_number' => '919811111111']),
            $this->getJson('/api/admin/team'),
        ] as $res) {
            $this->assertStatusCode($res, 403);
            $this->assertJsonPath($res, 'must_change_password', true);
        }

        // The starting password (or the default one) can't be chosen again.
        $this->assertError($this->changePassword($start, Installer::DEFAULT_PASSWORD), 422, self::SAME_PASSWORD);
        $this->assertError($this->changePassword($start, $start), 422, self::SAME_PASSWORD);
        $this->assertTrue($this->carriedSession['must_change_password']);
        $this->assertStatusCode($this->changePassword($start, self::PASSWORD), 200);
        $this->assertArrayNotHasKey('must_change_password', $this->carriedSession);

        $this->assertStatusCode($this->getJson('/api/admin/settings'), 200);
        $this->assertStatusCode($this->getJson('/api/admin/team'), 200);
    }

    public function test_changing_the_password_ends_remember_me_logins(): void
    {
        $owner = $this->ownerWithPassword();
        (new AdminModel())->builder()->where('id', $owner['id'])->update(['remember_token' => 'old-token']);
        $owner  = $this->row((int) $owner['id']);
        $cookie = $owner['id'] . '|old-token|' . Crypto::sign('admin|' . $owner['id'] . '|old-token|' . $owner['password_hash']);

        // "Remember me" on another device logs in by itself...
        $this->newBrowser();
        $this->cookieJar['remember_admin'] = $cookie;
        $this->assertStatusCode($this->getJson('/api/admin/settings'), 200);

        $this->newBrowser()->actingAsAdmin($owner);
        $this->assertStatusCode($this->changePassword(self::PASSWORD, self::NEW_PASSWORD), 200);
        $token = $this->row((int) $owner['id'])['remember_token'];
        $this->assertNotSame('old-token', $token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{60}$/', $token);

        // ...until the password is changed.
        $this->newBrowser();
        $this->cookieJar['remember_admin'] = $cookie;
        $this->assertError($this->getJson('/api/admin/settings'), 401, 'Please log in again.');
    }

    public function test_password_change_keeps_this_browser_and_signs_out_the_others(): void
    {
        $owner = $this->ownerWithPassword();

        // Another browser where the owner is logged in.
        $this->actingAsAdmin($owner);
        $this->assertStatusCode($this->getJson('/api/admin/settings'), 200);
        $other = $this->carriedSession;

        // This browser changes the password and stays logged in.
        $this->newBrowser()->actingAsAdmin($owner);
        $this->assertStatusCode($this->getJson('/api/admin/settings'), 200);
        $this->assertStatusCode($this->changePassword(self::PASSWORD, self::NEW_PASSWORD), 200);
        $this->assertSame(hash('sha256', $this->row((int) $owner['id'])['password_hash']), $this->carriedSession['password_admin']);
        $this->assertStatusCode($this->getJson('/api/admin/settings'), 200);
        $this->assertStatusCode($this->getJson('/api/admin/team'), 200);

        // The other browser is signed out on its next request.
        $this->carriedSession = $other;
        $this->cookieJar      = [];
        $this->assertError($this->getJson('/api/admin/settings'), 401, 'Please log in again.');
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);
    }

    public function test_staff_change_their_own_password_and_strangers_cannot(): void
    {
        $this->assertError($this->changePassword(self::PASSWORD, self::NEW_PASSWORD), 401, 'Please log in again.');

        $staff = $this->staff('ravi', 'active');
        $this->actingAsAdmin($staff);
        $this->assertStatusCode($this->changePassword(self::PASSWORD, self::NEW_PASSWORD), 200);
        $this->assertTrue(Passwords::check(self::NEW_PASSWORD, $this->row((int) $staff['id'])['password_hash']));
        $this->assertTrue(Passwords::check(config(Florist::class)->adminPassword, $this->owner()['password_hash']), "the owner's password is untouched");
    }

    // ------------------------------------------------------------------------------------------------------
    // /api/admin/team
    // ------------------------------------------------------------------------------------------------------

    public function test_team_lists_pending_sign_ups_first(): void
    {
        $owner = $this->ownerWithPassword();
        $this->staff('dina', 'disabled');
        $this->staff('asha', 'active', null, '919822222222');
        $this->staff('zoe', 'pending');
        $this->staff('ravi', 'pending');
        // Owner with two-step login on: the list says so.
        (new AdminModel())->builder()->where('id', $owner['id'])->update(['two_factor_secret' => Crypto::encryptString(Totp::newSecret()), 'two_factor_confirmed_at' => date('Y-m-d H:i:s')]);
        (new AdminModel())->newRecoveryCodes((int) $owner['id']);

        $res = $this->actingAsAdmin($owner)->getJson('/api/admin/team');
        $this->assertStatusCode($res, 200);
        $this->assertSame(['team', 'statuses'], array_keys($this->json($res)));
        $this->assertSame(['active' => 'Active', 'pending' => 'Waiting for approval', 'disabled' => 'Disabled'], $this->json($res, 'statuses'));
        $this->assertSame(['zoe', 'ravi', 'admin', 'asha', 'dina'], array_column($this->json($res, 'team'), 'username'));

        foreach ($this->json($res, 'team') as $member) {
            $this->assertSame([...self::ADMIN_KEYS, 'created_at', 'me'], array_keys($member));
            $row = $this->row($member['id']);
            $this->assertSame(AdminModel::toPublic($row) + ['created_at' => Text::isoDate($row['created_at']), 'me' => $member['username'] === 'admin'], $member);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+05:30$/', $member['created_at']);
        }
        $this->assertJsonPath($res, 'team.2.me', true);
        $this->assertJsonPath($res, 'team.2.two_factor', true);
        $this->assertJsonPath($res, 'team.2.backup_codes_left', 10);
        $this->assertJsonPath($res, 'team.3.email', null);
        $this->assertJsonPath($res, 'team.3.phone', '919822222222');
        $this->assertJsonPath($res, 'team.0.me', false);
    }

    public function test_owner_approves_a_pending_staff_account(): void
    {
        $staff = $this->staff('ravi', 'pending');
        (new AdminModel())->builder()->where('id', $staff['id'])->update(['remember_token' => 'kept']);
        $res = $this->actingAsAdmin($this->owner())->patchJson("/api/admin/team/{$staff['id']}", ['status' => 'active']);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'ravi can now use the dashboard.', 'pending' => 0], $this->json($res));
        $row = $this->row((int) $staff['id']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('kept', $row['remember_token'], 'switching on does not end sessions');
    }

    public function test_switching_off_a_staff_account_ends_their_access(): void
    {
        $staff = $this->staff('ravi', 'active');
        $this->staff('zoe', 'pending');
        (new AdminModel())->builder()->where('id', $staff['id'])->update(['remember_token' => 'staff-token']);
        $owner = $this->owner();

        // Ravi is logged in on their own browser.
        $this->actingAsAdmin($staff);
        $this->assertStatusCode($this->getJson('/api/admin/settings'), 200);
        $ravi = $this->carriedSession;

        $this->newBrowser()->actingAsAdmin($owner);
        $res = $this->patchJson("/api/admin/team/{$staff['id']}", ['status' => ' disabled ']);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'ravi has been switched off.', 'pending' => 1], $this->json($res));
        $this->assertError($this->patchJson("/api/admin/team/{$owner['id']}", ['status' => 'disabled']), 403, self::OWNER_LOCKED);
        $row = $this->row((int) $staff['id']);
        $this->assertSame('disabled', $row['status']);
        $this->assertNotSame('staff-token', $row['remember_token']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{60}$/', $row['remember_token']);
        $this->assertSame('active', $this->row((int) $owner['id'])['status']);
        $ownerSession = $this->carriedSession;

        // Their next request logs them out.
        $this->carriedSession = $ravi;
        $this->cookieJar      = [];
        $this->assertError($this->getJson('/api/admin/settings'), 401, 'This account has been switched off by the shop owner.');
        $this->assertArrayNotHasKey('login_admin', $this->carriedSession);

        // Switched on again.
        $this->carriedSession = $ownerSession;
        $res                  = $this->patchJson("/api/admin/team/{$staff['id']}", ['status' => 'active']);
        $this->assertSame(['message' => 'ravi can now use the dashboard.', 'pending' => 1], $this->json($res));
    }

    public function test_owner_accounts_cannot_be_changed_here(): void
    {
        $owner  = $this->owner();
        $second = $this->staff('partner', 'active', null, null, 'owner');
        $this->actingAsAdmin($owner);
        $this->assertError($this->patchJson("/api/admin/team/{$owner['id']}", ['status' => 'disabled']), 403, self::OWNER_LOCKED);
        $this->assertError($this->patchJson("/api/admin/team/{$owner['id']}", ['status' => 'bogus']), 403, self::OWNER_LOCKED);
        $this->assertError($this->deleteJson("/api/admin/team/{$owner['id']}"), 403, self::OWNER_LOCKED);
        $this->assertError($this->patchJson("/api/admin/team/{$second['id']}", ['status' => 'disabled']), 403, self::OWNER_LOCKED);
        $this->assertError($this->deleteJson("/api/admin/team/{$second['id']}"), 403, self::OWNER_LOCKED);
        $this->assertSame('active', $this->row((int) $second['id'])['status']);
        $this->assertNotNull($this->row((int) $second['id']));
    }

    public function test_unknown_status_and_missing_accounts(): void
    {
        $staff = $this->staff('ravi', 'active');
        $this->actingAsAdmin($this->owner());
        foreach ([['status' => 'bogus'], [], ['status' => 'pending'], ['status' => 'Active'], ['status' => ['active']]] as $body) {
            $this->assertError($this->patchJson("/api/admin/team/{$staff['id']}", $body), 422, 'Unknown status.');
        }
        $this->assertSame('active', $this->row((int) $staff['id'])['status']);
        $this->assertError($this->patchJson('/api/admin/team/9999', ['status' => 'active']), 404, 'Not found.');
        $this->assertError($this->patchJson('/api/admin/team/9999', ['status' => 'bogus']), 404, 'Not found.');
        $this->assertError($this->deleteJson('/api/admin/team/9999'), 404, 'Not found.');
    }

    public function test_owner_removes_a_staff_account(): void
    {
        $staff = $this->staff('ravi', 'active');
        $zoe   = $this->staff('zoe', 'pending');

        $this->actingAsAdmin($staff);
        $this->assertStatusCode($this->getJson('/api/admin/settings'), 200);
        $ravi = $this->carriedSession;

        $this->newBrowser()->actingAsAdmin($this->owner());
        $res = $this->deleteJson("/api/admin/team/{$staff['id']}");
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'ravi has been removed.', 'pending' => 1], $this->json($res));
        $this->assertNull($this->row((int) $staff['id']));
        // Declining a sign-up is the same.
        $this->assertSame(['message' => 'zoe has been removed.', 'pending' => 0], $this->json($this->deleteJson("/api/admin/team/{$zoe['id']}")));
        $this->assertError($this->deleteJson("/api/admin/team/{$zoe['id']}"), 404, 'Not found.');

        $this->carriedSession = $ravi;
        $this->cookieJar      = [];
        $this->assertError($this->getJson('/api/admin/settings'), 401, 'Please log in again.');
    }

    // ------------------------------------------------------------------------------------------------------
    // /api/admin/settings/two-factor/*
    // ------------------------------------------------------------------------------------------------------

    public function test_setup_needs_the_password_and_a_working_code(): void
    {
        $owner = $this->ownerWithPassword();
        $this->actingAsAdmin($owner);
        $this->assertError($this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => 'wrong']), 422, self::WRONG_PASSWORD);
        $this->assertError($this->postJson('/api/admin/settings/two-factor/setup', []), 422, self::WRONG_PASSWORD);
        $this->assertArrayNotHasKey('two_factor_setup', $this->carriedSession);

        $res = $this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['secret', 'uri'], array_keys($this->json($res)));
        $this->assertMatchesRegularExpression('/^([A-Z2-7]{4} ){7}[A-Z2-7]{4}$/', $this->json($res, 'secret'));
        $secret = str_replace(' ', '', $this->json($res, 'secret'));
        $this->assertSame($secret, $this->carriedSession['two_factor_setup']);
        $this->assertStringStartsWith('otpauth://totp/City%20Florist', $this->json($res, 'uri'));
        $this->assertNotEmpty($owner['email']);
        $this->assertSame(Totp::uri($secret, $owner['email']), $this->json($res, 'uri'));

        $this->assertError($this->postJson('/api/admin/settings/two-factor/confirm', ['code' => $this->wrongCode($secret)]), 422, self::WRONG_SETUP_CODE);
        $this->assertError($this->postJson('/api/admin/settings/two-factor/confirm', []), 422, self::WRONG_SETUP_CODE);
        $this->assertFalse(AdminModel::hasTwoFactor($this->row((int) $owner['id'])));
        $this->assertSame($secret, $this->carriedSession['two_factor_setup'], 'a wrong code keeps the setup going');
    }

    public function test_the_qr_code_names_the_username_when_there_is_no_email(): void
    {
        $staff = $this->staff('ravi', 'active', null);
        $res   = $this->actingAsAdmin($staff)->postJson('/api/admin/settings/two-factor/setup', ['current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertStringStartsWith('otpauth://totp/' . rawurlencode('City Florist:ravi') . '?secret=', $this->json($res, 'uri'));
    }

    public function test_confirm_without_setup_says_start_again_and_is_not_counted(): void
    {
        $owner = $this->ownerWithPassword();
        $this->actingAsAdmin($owner);
        $this->assertError($this->postJson('/api/admin/settings/two-factor/confirm', ['code' => '123456']), 422, self::START_AGAIN);
        $this->assertSame(0, $this->attempts((int) $owner['id']));
    }

    public function test_turning_on_two_step_login(): void
    {
        $owner = $this->ownerWithPassword();
        $this->actingAsAdmin($owner);
        $setup  = $this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => self::PASSWORD]);
        $secret = str_replace(' ', '', $this->json($setup, 'secret'));
        $step   = intdiv(time(), 30);
        $code   = Totp::code($secret, $step);

        $res = $this->postJson('/api/admin/settings/two-factor/confirm', ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message', 'recovery_codes', 'admin'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'message', 'Two-step login is on. Keep the backup codes somewhere safe.');
        $codes = $this->json($res, 'recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{5}-[0-9A-F]{5}$/', $c);
        }
        $this->assertSame(self::ADMIN_KEYS, array_keys($this->json($res, 'admin')));
        $this->assertJsonPath($res, 'admin.two_factor', true);
        $this->assertJsonPath($res, 'admin.backup_codes_left', 10);

        $row = $this->row((int) $owner['id']);
        $this->assertStringNotContainsString($secret, $row['two_factor_secret']);
        $this->assertSame($secret, Crypto::decryptString($row['two_factor_secret']));
        $this->assertSame($secret, AdminModel::twoFactorSecret($row));
        $this->assertNotNull($row['two_factor_confirmed_at']);
        $this->assertLessThanOrEqual(5, abs(strtotime($row['two_factor_confirmed_at']) - time()));
        $this->assertGreaterThanOrEqual($step, $row['two_factor_last_step']);
        $this->assertLessThanOrEqual($step + 1, $row['two_factor_last_step']);
        $this->assertCount(10, AdminModel::recoveryCodes($row));
        $this->assertArrayNotHasKey('two_factor_setup', $this->carriedSession);

        // A backup code from the list works (once).
        $this->assertTrue((new AdminModel())->verifyTwoFactor($row, $codes[3]));

        // Confirming again needs a new setup.
        $this->assertError($this->postJson('/api/admin/settings/two-factor/confirm', ['code' => $code]), 422, self::START_AGAIN);
        $this->assertJsonPath($this->getJson('/api/admin/settings'), 'account.backup_codes_left', 9);
    }

    public function test_new_backup_codes(): void
    {
        $owner = $this->ownerWithPassword();
        $url   = '/api/admin/settings/two-factor/recovery-codes';
        $this->actingAsAdmin($owner);
        $this->assertError($this->postJson($url, ['current_password' => self::PASSWORD]), 422, 'Two-step login is not on.');
        $this->assertError($this->postJson($url, ['current_password' => 'wrong']), 422, self::WRONG_PASSWORD);

        [, $old] = $this->enable($owner);
        $this->assertError($this->postJson($url, ['current_password' => 'wrong']), 422, self::WRONG_PASSWORD);
        $res = $this->postJson($url, ['current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message', 'recovery_codes', 'admin'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'message', 'New backup codes made. The old ones no longer work.');
        $this->assertJsonCount($res, 10, 'recovery_codes');
        $this->assertJsonPath($res, 'admin.two_factor', true);
        $this->assertJsonPath($res, 'admin.backup_codes_left', 10);
        $new = $this->json($res, 'recovery_codes');
        $this->assertSame([], array_intersect($old, $new));

        $model = new AdminModel();
        $this->assertFalse($model->verifyTwoFactor($this->row((int) $owner['id']), $old[0]), 'the old codes no longer work');
        $this->assertTrue($model->verifyTwoFactor($this->row((int) $owner['id']), $new[0]));
    }

    public function test_turning_off_needs_password_and_code(): void
    {
        $owner = $this->ownerWithPassword();
        [$secret, $codes] = $this->enable($owner);
        $url = '/api/admin/settings/two-factor/disable';
        $this->assertError($this->postJson($url, ['current_password' => 'wrong', 'code' => $codes[1]]), 422, self::WRONG_PASSWORD);
        $this->assertError($this->postJson($url, ['current_password' => self::PASSWORD, 'code' => $this->wrongCode($secret)]), 422, self::WRONG_DISABLE_CODE);
        $this->assertError($this->postJson($url, ['current_password' => self::PASSWORD]), 422, self::WRONG_DISABLE_CODE);
        $this->assertTrue(AdminModel::hasTwoFactor($this->row((int) $owner['id'])));

        $res = $this->postJson($url, ['current_password' => self::PASSWORD, 'code' => strtolower($codes[1])]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message', 'admin'], array_keys($this->json($res)));
        $this->assertJsonPath($res, 'message', 'Two-step login is off.');
        $this->assertJsonPath($res, 'admin.two_factor', false);
        $this->assertJsonPath($res, 'admin.backup_codes_left', null);
        $row = $this->row((int) $owner['id']);
        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step'] as $column) {
            $this->assertNull($row[$column], $column);
        }
    }

    public function test_turning_off_with_an_authenticator_code_that_was_not_used_before(): void
    {
        $owner = $this->ownerWithPassword();
        [$secret] = $this->enable($owner);
        $url  = '/api/admin/settings/two-factor/disable';
        $used = (int) $this->row((int) $owner['id'])['two_factor_last_step'];
        // The code that turned it on can't be used again.
        $this->assertError($this->postJson($url, ['current_password' => self::PASSWORD, 'code' => Totp::code($secret, $used)]), 422, self::WRONG_DISABLE_CODE);
        $res = $this->postJson($url, ['current_password' => self::PASSWORD, 'code' => Totp::code($secret, $used + 1)]);
        $this->assertStatusCode($res, 200);
        $this->assertJsonPath($res, 'admin.two_factor', false);
    }

    public function test_turning_off_when_it_is_already_off_needs_no_code(): void
    {
        $owner = $this->ownerWithPassword();
        $res   = $this->actingAsAdmin($owner)->postJson('/api/admin/settings/two-factor/disable', ['current_password' => self::PASSWORD]);
        $this->assertStatusCode($res, 200);
        $this->assertSame(['message' => 'Two-step login is off.', 'admin' => AdminModel::toPublic($this->row((int) $owner['id']))], $this->json($res));
    }

    public function test_two_step_settings_allow_ten_tries_in_fifteen_minutes(): void
    {
        $owner = $this->ownerWithPassword();
        $this->actingAsAdmin($owner);
        for ($i = 0; $i < 10; $i++) {
            $this->assertError($this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => "guess{$i}"]), 422, self::WRONG_PASSWORD);
        }
        $this->assertError($this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => 'guess']), 429, self::TOO_MANY);
        $this->assertError($this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => self::PASSWORD]), 429, self::TOO_MANY);
        $this->assertError($this->postJson('/api/admin/settings/two-factor/recovery-codes', ['current_password' => self::PASSWORD]), 429, self::TOO_MANY);
        $this->assertError($this->postJson('/api/admin/settings/two-factor/disable', ['current_password' => self::PASSWORD]), 429, self::TOO_MANY);
        // Confirm says "start again" before counting.
        $this->assertError($this->postJson('/api/admin/settings/two-factor/confirm', ['code' => '123456']), 422, self::START_AGAIN);
        $this->carriedSession['two_factor_setup'] = Totp::newSecret();
        $this->assertError($this->postJson('/api/admin/settings/two-factor/confirm', ['code' => '123456']), 429, self::TOO_MANY);
        $this->assertSame(10, $this->attempts((int) $owner['id']), 'refused tries are not counted');
        $wait = service('rateLimiter')->availableIn('two-factor-settings:' . $owner['id']);
        $this->assertGreaterThan(890, $wait);
        $this->assertLessThanOrEqual(900, $wait);

        // Per account: someone else is not held up.
        $staff = $this->staff('ravi', 'active');
        $this->newBrowser()->actingAsAdmin($staff);
        $this->assertStatusCode($this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => self::PASSWORD]), 200);
        $this->assertSame(1, $this->attempts((int) $staff['id']));
    }

    public function test_every_try_counts_and_turning_off_counts_twice(): void
    {
        $owner = $this->ownerWithPassword();
        $id    = (int) $owner['id'];
        $this->actingAsAdmin($owner);
        $this->postJson('/api/admin/settings/two-factor/disable', ['current_password' => 'wrong']);
        $this->assertSame(1, $this->attempts($id), 'a wrong password counts once');
        $this->assertStatusCode($this->postJson('/api/admin/settings/two-factor/disable', ['current_password' => self::PASSWORD]), 200);
        $this->assertSame(3, $this->attempts($id), 'the right password counts twice');
        $this->assertStatusCode($this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => self::PASSWORD]), 200);
        $this->assertSame(4, $this->attempts($id), 'successes count too');
        $this->postJson('/api/admin/settings/two-factor/confirm', ['code' => '12']);
        $this->assertSame(5, $this->attempts($id));

        // 4 more wrong passwords → 9.
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/admin/settings/two-factor/setup', ['current_password' => 'wrong']);
        }
        $this->assertSame(9, $this->attempts($id));
        // The 10th try passes the first check, then turning off checks again and stops.
        $this->assertError($this->postJson('/api/admin/settings/two-factor/disable', ['current_password' => self::PASSWORD]), 429, self::TOO_MANY);
        $this->assertSame(10, $this->attempts($id));
    }

    public function test_two_step_settings_need_a_login(): void
    {
        foreach (['setup', 'confirm', 'disable', 'recovery-codes'] as $action) {
            $this->assertError($this->postJson("/api/admin/settings/two-factor/{$action}", ['current_password' => self::PASSWORD]), 401, 'Please log in again.');
        }
    }

    public function test_staff_use_two_step_login_too(): void
    {
        $staff = $this->staff('ravi', 'active');
        [, $codes, $res] = $this->enable($staff);
        $this->assertJsonPath($res, 'admin.username', 'ravi');
        $this->assertJsonPath($res, 'admin.role', 'staff');
        $this->assertCount(10, $codes);
        $this->assertFalse(AdminModel::hasTwoFactor($this->owner()));
    }
}
