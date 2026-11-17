<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Libraries\Http;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use ReflectionMethod;
use Throwable;

/**
 * Base for every API controller. Gives Laravel-style request helpers and makes every error a JSON
 * {"error": "..."} response (the shape both React apps show to people), with the right status code.
 *
 * @property IncomingRequest $request
 */
abstract class ApiController extends Controller
{
    /** Request fields kept exactly as typed: never trimmed (Laravel's TrimStrings leaves these alone too). */
    private const UNTRIMMED = ['current_password', 'password', 'password_confirmation'];

    private ?array $inputCache = null;

    /**
     * Runs the route's method. Anything it throws becomes the JSON error response here, inside the controller,
     * so the route's after-filters still run (unlike an exception that reaches CodeIgniter's error handler).
     */
    public function _remap(string $method, ...$params)
    {
        if (! method_exists($this, $method) || ! (new ReflectionMethod($this, $method))->isPublic() || str_starts_with($method, '_')) {
            throw PageNotFoundException::forMethodNotFound($method);
        }

        try {
            return $this->{$method}(...$params);
        } catch (ApiException $e) {
            return $e->getResponse();
        } catch (Throwable $e) {
            [$status, $message] = Http::describe($e);
            if ($status >= 500) {
                self::logError($e);
            }

            return Http::error($message, $status);
        }
    }

    /**
     * Writes an error to writable/logs (Laravel's report()). The placeholders aren't {file}/{line}: CodeIgniter's
     * logger fills those with the place log_message() was called from.
     */
    public static function logError(Throwable $e): void
    {
        log_message('error', '{class}: {msg} in {where}' . "\n" . '{trace}', [
            'class' => $e::class,
            'msg'   => $e->getMessage(),
            'where' => clean_path($e->getFile()) . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    // ------------------------------------------------------------------------------------------------------
    // Responses
    // ------------------------------------------------------------------------------------------------------

    /** A JSON response (₹ and slashes unescaped, never cached). */
    protected function json(mixed $data, int $status = 200): ResponseInterface
    {
        return Http::json($data, $status, $this->response);
    }

    /** Stops the request with {"error": "..."}, the shape the React apps show to people. */
    protected function fail(string $message, int $status = 422, array $extra = []): never
    {
        throw new ApiException($message, $status, $extra);
    }

    /**
     * The product, offer, order, review or account named in the URL doesn't exist: 404 {"error": "Not found."}, as
     * Laravel's route-model binding answered before the controller ran (hence its "no-cache" header).
     */
    protected function missing(): never
    {
        throw new ApiException('Not found.', 404, [], Http::NO_CACHE);
    }

    /** Redirects the browser (to "/admin/login?…" or to Google), keeping the cookies set during this request. */
    protected function redirectTo(string $location): ResponseInterface
    {
        return Http::redirect($location);
    }

    // ------------------------------------------------------------------------------------------------------
    // Input, like Laravel's $request->input(): the JSON body or form fields, then the query string. Strings are
    // trimmed (except passwords) and empty strings become null, as Laravel's TrimStrings and
    // ConvertEmptyStringsToNull middleware do.
    // ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function inputAll(): array
    {
        if ($this->inputCache === null) {
            $request = $this->request;
            $body    = [];
            if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json')) {
                try {
                    $json = $request->getJSON(true);
                } catch (Throwable) {
                    $json = null;
                }
                $body = is_array($json) ? $json : [];
            } elseif ($request->getMethod() === 'POST') {
                $body = (array) $request->getPost();
            } elseif (in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)) {
                $body = $request->getRawInput();
            }
            $this->inputCache = $this->clean($body + (array) $request->getGet());
        }

        return $this->inputCache;
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        $all = $this->inputAll();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** A query-string value ($request->query()). */
    protected function query(string $key, mixed $default = null): mixed
    {
        $value = $this->request->getGet($key);

        return $value === null ? $default : $this->clean([$key => $value])[$key] ?? $default;
    }

    /**
     * Laravel's $request->filled(): true unless the field is missing or a blank string. Arrays and booleans
     * (even [] and false) count as filled, so a bot sending {"website": []} still trips the honeypot.
     */
    protected function filled(string $key): bool
    {
        $value = $this->input($key);

        return is_bool($value) || is_array($value) || trim((string) $value) !== '';
    }

    /** A trimmed text field, cut to $max characters. */
    protected function str(string $key, int $max = 255): string
    {
        return mb_substr(trim((string) $this->scalar($key, '')), 0, $max);
    }

    /** A money field; empty → null. */
    protected function money(string $key): ?float
    {
        $v = trim((string) $this->scalar($key, ''));

        return $v === '' ? null : max(0, round((float) str_replace(',', '', $v), 2));
    }

    /** Checkbox-style flags arrive as "1"/"0", "true"/"false" or real booleans. */
    protected function flag(string $key): bool
    {
        return filter_var($this->input($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** A field read as text: arrays (sent by mistake or on purpose) count as empty. */
    protected function scalar(string $key, string $default = ''): string|int|float|bool
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? $value : $default;
    }

    /** The visitor's IP address (proxies are only trusted when listed in florist.trustedProxies; see ClientIp). */
    protected function ip(): string
    {
        return \App\Libraries\ClientIp::of($this->request);
    }

    // ------------------------------------------------------------------------------------------------------
    // Uploads
    // ------------------------------------------------------------------------------------------------------

    /**
     * Uploaded files for a field that may hold one file or several, skipping the ones PHP refused.
     *
     * @return list<UploadedFile>
     */
    protected function files(string $key): array
    {
        return array_values(array_filter($this->allFiles($key), static fn (UploadedFile $f): bool => self::uploadOk($f)));
    }

    /** One message per file PHP refused to accept (too large, partly sent …), so none fail silently. */
    protected function uploadWarnings(string $key): array
    {
        $warnings = [];
        foreach ($this->allFiles($key) as $file) {
            if (! self::uploadOk($file)) {
                $warnings[] = $file->getClientName() . ': ' . self::uploadError($file);
            }
        }

        return $warnings;
    }

    /** @return list<UploadedFile> */
    protected function allFiles(string $key): array
    {
        // Like Laravel's $request->file(): one level deep, and an empty file input (no file chosen) is not a file.
        $files = $this->request->getFiles()[$key] ?? null;
        $files = $files instanceof UploadedFile ? [$files] : (is_array($files) ? $files : []);

        return array_values(array_filter(
            $files,
            static fn ($file): bool => $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE,
        ));
    }

    /** PHP accepted the file and it is on disk (PHP's own upload, unless the test suite placed it there). */
    public static function uploadOk(UploadedFile $file): bool
    {
        $path = (string) $file->getTempName();

        return $file->getError() === UPLOAD_ERR_OK && $path !== '' && is_file($path)
            && (ENVIRONMENT === 'testing' || is_uploaded_file($path));
    }

    public static function uploadError(UploadedFile $file): string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'the photo is larger than the server allows (' . ini_get('upload_max_filesize') . 'B).',
            UPLOAD_ERR_PARTIAL => 'the upload was interrupted. Please try again.',
            default            => 'the photo could not be uploaded.',
        };
    }

    // ------------------------------------------------------------------------------------------------------

    /** Trims strings (except passwords) and turns empty strings into null, at every depth. */
    private function clean(array $data, bool $top = true): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->clean($value, false);
            } elseif (is_string($value)) {
                if (! ($top && in_array($key, self::UNTRIMMED, true))) {
                    $value = preg_replace('~^[\s\x{FEFF}\x{200B}\x{200E}]+|[\s\x{FEFF}\x{200B}\x{200E}]+$~u', '', $value) ?? $value;
                }
                $data[$key] = $value === '' ? null : $value;
            }
        }

        return $data;
    }
}
