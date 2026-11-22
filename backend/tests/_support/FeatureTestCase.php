<?php

namespace Tests\Support;

use App\Libraries\Installer;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Florist;
use Config\Services;
use PHPUnit\Framework\Attributes\After;

/**
 * Base for the API tests. Requests behave like a browser using the React apps:
 *  - the session and cookies carry over from one request to the next (log in, then call the dashboard);
 *  - POST/PUT/PATCH/DELETE send the session's CSRF token in the X-XSRF-TOKEN header (withoutXsrfToken() to test 419);
 *  - the database is emptied before every test (all tables truncated; migrations run once per run);
 *  - photos are written to a temporary web root, never backend/public/uploads;
 *  - emails go to CodeIgniter's MockEmail and are collected in $this->emails().
 *
 * The test database is cityflorist_ci_test (database.tests.* in .env); TEST_DB=<name> picks another one.
 */
abstract class FeatureTestCase extends CIUnitTestCase
{
    use FeatureTestTrait;

    private static bool $migrated = false;

    private static bool $listening = false;

    /** @var list<array<string, mixed>> */
    private static array $sentEmails = [];

    /** @var array<string, mixed> */
    protected array $carriedSession = [];

    /** @var array<string, string> */
    protected array $cookieJar = [];

    /** The visitor's IP address for the next requests (REMOTE_ADDR). */
    protected string $ip = '127.0.0.1';

    /** @var array<string, string> Extra headers for every following request. */
    protected array $extraHeaders = [];

    /** The temporary web root for this test (Config\Florist::$publicPath). */
    protected string $publicRoot = '';

    private bool $skipXsrfOnce = false;

    /** Services that keep state or capture config/cache objects: rebuilt for every test (also drops their mocks). */
    private const FRESH_SERVICES = ['rateLimiter', 'googleOAuth', 'mailer', 'adminAuth'];

    /** @var list<string> Temporary files made by photo()/fileWith(), removed after the test. */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The parent just injected a fresh MockCache/MockEmail; shared services built in an earlier test would
        // still hold the old ones (rate-limit counts leaking from test to test) or an old injected mock.
        foreach (self::FRESH_SERVICES as $service) {
            Services::resetSingle($service);
        }

        $this->freshDatabase();

        $this->carriedSession = [];
        $this->cookieJar      = [];
        $this->ip             = '127.0.0.1';
        $this->extraHeaders   = [];
        $this->skipXsrfOnce   = false;
        self::$sentEmails     = [];

        $this->publicRoot = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cityflorist-test-' . bin2hex(random_bytes(5)) . DIRECTORY_SEPARATOR;
        mkdir($this->publicRoot, 0777, true);

        $florist             = config(Florist::class);
        $florist->publicPath = $this->publicRoot;
        $florist->mailer     = 'smtp'; // "sent" through CodeIgniter's MockEmail during tests
        $florist->production = false;

        if (! self::$listening) {
            Events::on('email', static function (array $archive): void {
                self::$sentEmails[] = $archive;
            });
            self::$listening = true;
        }
    }

    #[After]
    protected function removePublicRoot(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        foreach (self::FRESH_SERVICES as $service) {
            Services::resetSingle($service);
        }
        if ($this->publicRoot !== '' && is_dir($this->publicRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->publicRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->publicRoot);
        }
    }

    // ------------------------------------------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------------------------------------------

    /** Empties every table (ids start at 1 again). The first test of a run drops everything and migrates. */
    private function freshDatabase(): void
    {
        $db = db_connect();
        $db->disableForeignKeyChecks();
        if (! self::$migrated) {
            foreach ($db->listTables() as $table) {
                $db->query('DROP TABLE IF EXISTS `' . $table . '`');
            }
            $db->resetDataCache();
            Installer::migrate();
            self::$migrated = true;
        } else {
            foreach ($db->listTables() as $table) {
                if ($table !== config('Migrations')->table) {
                    $db->query('TRUNCATE TABLE `' . $table . '`');
                }
            }
        }
        $db->enableForeignKeyChecks();
        $db->resetDataCache();
    }

    /** Runs the installer without the starter photos (what most tests start from). */
    protected function install(): void
    {
        Installer::run(false);
    }

    // ------------------------------------------------------------------------------------------------------
    // Requests
    // ------------------------------------------------------------------------------------------------------

    protected function getJson(string $path): TestResponse
    {
        return $this->send('GET', $path);
    }

    protected function postJson(string $path, array $data = []): TestResponse
    {
        return $this->send('POST', $path, $data);
    }

    protected function putJson(string $path, array $data = []): TestResponse
    {
        return $this->send('PUT', $path, $data);
    }

    protected function patchJson(string $path, array $data = []): TestResponse
    {
        return $this->send('PATCH', $path, $data);
    }

    protected function deleteJson(string $path, array $data = []): TestResponse
    {
        return $this->send('DELETE', $path, $data);
    }

    /**
     * A multipart POST, as the dashboard sends forms with photos.
     *
     * @param array<string, mixed>                         $fields Form fields (nested arrays allowed, e.g. ['cat' => [3 => ['name' => 'x']]])
     * @param array<string, string|list<string>|array<int|string, string>> $files  Field name → path of a file on disk, or a list of paths (images[]), or [key => path] (cover[3])
     */
    protected function postMultipart(string $path, array $fields = [], array $files = []): TestResponse
    {
        return $this->send('POST', $path, $fields, $files);
    }

    /** The next request goes without the X-XSRF-TOKEN header (to check the 419 answer). */
    protected function withoutXsrfToken(): static
    {
        $this->skipXsrfOnce = true;

        return $this;
    }

    /** The visitor's IP for the following requests. */
    protected function fromIp(string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    /** An extra header for the following requests, e.g. withHeader('X-Forwarded-For', '203.0.113.9'). */
    protected function withHeader(string $name, string $value): static
    {
        $this->extraHeaders[$name] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>      $files
     */
    protected function send(string $method, string $path, ?array $data = null, array $files = []): TestResponse
    {
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] + $this->extraHeaders;
        if (! in_array($method, ['GET', 'HEAD'], true)) {
            // Like a browser that has loaded the page: the session already holds a CSRF token.
            $this->carriedSession['_token'] ??= bin2hex(random_bytes(20));
            if (! $this->skipXsrfOnce) {
                $headers['X-XSRF-TOKEN'] = $this->carriedSession['_token'];
            }
        }
        $this->skipXsrfOnce = false;

        $superglobals = service('superglobals');
        $superglobals->setServer('REMOTE_ADDR', $this->ip);
        $superglobals->setCookieArray($this->cookieJar);
        $superglobals->setFilesArray($this->filesArray($files));
        if ($files !== [] || $method === 'GET') {
            $superglobals->unsetServer('CONTENT_TYPE');
        }

        // Each request gets its own response object (CodeIgniter shares one by default).
        Services::resetSingle('response');

        $this->withHeaders($headers)->withSession($this->carriedSession);
        if ($method !== 'GET' && $files === []) {
            $this->withBodyFormat('json');
            $response = $this->call($method, $path, $data ?? []);
        } else {
            $this->bodyFormat  = '';
            $this->requestBody = '';
            $response          = $this->call($method, $path, $method === 'GET' ? null : ($data ?? []));
        }

        $this->carriedSession = $_SESSION ?? [];
        $this->collectCookies($response);
        $superglobals->setFilesArray([]);

        return $response;
    }

    /** Keeps the cookies the response set (or deleted), like a browser's cookie jar. */
    private function collectCookies(TestResponse $response): void
    {
        foreach ($response->response()->getCookies() as $cookie) {
            /** @var Cookie $cookie */
            if ($cookie->isExpired() || $cookie->getValue() === '') {
                unset($this->cookieJar[$cookie->getName()]);
            } else {
                $this->cookieJar[$cookie->getName()] = $cookie->getValue();
            }
        }
    }

    /** $_FILES as PHP builds it for multipart forms. */
    private function filesArray(array $files): array
    {
        $out = [];
        foreach ($files as $field => $value) {
            if (is_string($value) || (is_array($value) && isset($value['error']) && ! is_array($value['error']))) {
                $out[$field] = $this->fileEntry($value);

                continue;
            }
            $entry = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
            foreach ($value as $key => $path) {
                foreach ($this->fileEntry($path) as $prop => $v) {
                    $entry[$prop][$key] = $v;
                }
            }
            $out[$field] = $entry;
        }

        return $out;
    }

    /**
     * @param array{name?: string, error: int}|string $path A file on disk, or ['name' => 'big.jpg', 'error' => UPLOAD_ERR_INI_SIZE]
     *                                                     for an upload PHP refused.
     */
    private function fileEntry(array|string $path): array
    {
        if (is_array($path)) {
            return ['name' => $path['name'] ?? 'photo.jpg', 'type' => '', 'tmp_name' => '', 'error' => $path['error'], 'size' => 0];
        }

        return [
            'name'     => basename($path),
            'type'     => is_file($path) ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream',
            'tmp_name' => $path,
            'error'    => is_file($path) ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE,
            'size'     => is_file($path) ? filesize($path) : 0,
        ];
    }

    // ------------------------------------------------------------------------------------------------------
    // Logins
    // ------------------------------------------------------------------------------------------------------

    /** The following requests are made by this dashboard account (Laravel's actingAs). */
    protected function actingAsAdmin(array|int $admin): static
    {
        unset($this->carriedSession['password_admin']);
        $this->carriedSession['login_admin'] = is_array($admin) ? (int) $admin['id'] : $admin;

        return $this;
    }

    /** A brand-new browser: no session, no cookies. */
    protected function newBrowser(): static
    {
        $this->carriedSession = [];
        $this->cookieJar      = [];

        return $this;
    }

    // ------------------------------------------------------------------------------------------------------
    // Assertions
    // ------------------------------------------------------------------------------------------------------

    /** The decoded JSON body, or one value from it by dot path ("product.images.0.src"). */
    protected function json(TestResponse $response, ?string $path = null): mixed
    {
        $data = json_decode((string) $response->response()->getBody(), true);

        return $path === null ? $data : self::dig($data, $path)[1];
    }

    /** Laravel's assertJsonPath: strict comparison, or a closure that must return true. */
    protected function assertJsonPath(TestResponse $response, string $path, mixed $expected): void
    {
        [$found, $actual] = self::dig(json_decode((string) $response->response()->getBody(), true), $path);
        $this->assertTrue($found, "The JSON has no \"{$path}\". Body: " . $response->response()->getBody());
        if ($expected instanceof \Closure) {
            $this->assertTrue((bool) $expected($actual), "JSON \"{$path}\" failed the check. Got: " . var_export($actual, true));

            return;
        }
        $this->assertSame($expected, $actual, "JSON \"{$path}\"");
    }

    protected function assertJsonMissingPath(TestResponse $response, string $path): void
    {
        [$found] = self::dig(json_decode((string) $response->response()->getBody(), true), $path);
        $this->assertFalse($found, "The JSON should not have \"{$path}\".");
    }

    /** Number of items in the JSON array at $path (or in the whole body). */
    protected function assertJsonCount(TestResponse $response, int $count, ?string $path = null): void
    {
        $value = $path === null ? $this->json($response) : $this->json($response, $path);
        $this->assertIsArray($value, "JSON \"{$path}\" is not a list.");
        $this->assertCount($count, $value, "JSON \"{$path}\"");
    }

    /**
     * Where a redirect goes, as path + query ("/admin/login?error=…"). The Location header itself is absolute
     * ("http://<host>/admin/login?…"), like Laravel's; this is what Laravel's assertRedirect() compared.
     */
    protected function location(TestResponse $response): string
    {
        $url = $response->response()->getHeaderLine('Location');
        if ($url === '' || ! preg_match('#^https?://[^/]+(/.*)?$#i', $url, $m)) {
            return $url;
        }

        return ($m[1] ?? '') === '' ? '/' : $m[1];
    }

    protected function assertStatusCode(TestResponse $response, int $status): void
    {
        $this->assertSame($status, $response->response()->getStatusCode(), 'Status code. Body: ' . $response->response()->getBody());
    }

    /** @return array{0: bool, 1: mixed} whether the path exists, and its value */
    private static function dig(mixed $data, string $path): array
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return [false, null];
            }
            $data = $data[$segment];
        }

        return [true, $data];
    }

    // ------------------------------------------------------------------------------------------------------
    // Emails and files
    // ------------------------------------------------------------------------------------------------------

    /** Emails sent during this test (CodeIgniter MockEmail archives: recipients, subject, body, altMessage …). */
    protected function emails(): array
    {
        return self::$sentEmails;
    }

    /** A real PNG photo in a temporary file. */
    protected function photo(int $width = 400, int $height = 300): string
    {
        $file = $this->tempPath('photo', '.png');
        $img  = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 230, 80, 150));
        imagepng($img, $file);

        return $file;
    }

    /** A temporary file named $name (e.g. "shell.php.png") holding $content, removed after the test. */
    protected function fileWith(string $name, string $content): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cityflorist-upload-' . bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        $file = $dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($file, $content);
        $this->tempFiles[] = $file;
        register_shutdown_function(static fn () => @rmdir($dir));

        return $file;
    }

    private function tempPath(string $prefix, string $suffix): string
    {
        $file              = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6)) . $suffix;
        $this->tempFiles[] = $file;

        return $file;
    }

    /** The file on disk for a public path like "/uploads/products/x.webp". */
    protected function publicFile(string $publicPath): string
    {
        return $this->publicRoot . ltrim($publicPath, '/');
    }
}
