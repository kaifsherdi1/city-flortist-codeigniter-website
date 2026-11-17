<?php

namespace App\Libraries;

use CodeIgniter\Cache\CacheInterface;

/**
 * Counts attempts per key (e.g. "login:<ip>") in a time window, like Laravel's RateLimiter: the window starts at
 * the first hit and lasts $decaySeconds; once $max hits are reached, tooManyAttempts() is true until it ends.
 * Stored in the cache (writable/cache on the live site).
 */
class RateLimiter
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function tooManyAttempts(string $key, int $max): bool
    {
        return $this->attempts($key) >= $max;
    }

    /** Counts one attempt. Returns the number of attempts in the current window. */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        $now   = time();
        $entry = $this->entry($key) ?? ['count' => 0, 'until' => $now + $decaySeconds];
        $entry['count']++;
        $this->cache->save($this->cacheKey($key), $entry, max(1, $entry['until'] - $now));

        return $entry['count'];
    }

    public function attempts(string $key): int
    {
        return $this->entry($key)['count'] ?? 0;
    }

    public function remaining(string $key, int $max): int
    {
        return max(0, $max - $this->attempts($key));
    }

    /** Seconds until the window ends and attempts are allowed again. */
    public function availableIn(string $key): int
    {
        $entry = $this->entry($key);

        return $entry === null ? 0 : max(0, $entry['until'] - time());
    }

    public function clear(string $key): void
    {
        $this->cache->delete($this->cacheKey($key));
    }

    /** @return array{count: int, until: int}|null */
    private function entry(string $key): ?array
    {
        $entry = $this->cache->get($this->cacheKey($key));

        return is_array($entry) && isset($entry['count'], $entry['until']) && $entry['until'] > time() ? $entry : null;
    }

    /**
     * Cache keys can't contain ":" or "/" (IPv6 addresses, emails), so the key is hashed. First it is cleaned the
     * way Laravel's RateLimiter cleans keys ("josé" and "jose" share a counter).
     */
    private function cacheKey(string $key): string
    {
        return 'ratelimit_' . sha1((string) preg_replace('/&([a-z])[a-z]+;/i', '$1', htmlentities($key)));
    }
}
