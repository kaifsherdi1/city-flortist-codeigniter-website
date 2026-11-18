<?php

namespace Config;

use App\Filters\AdminAuth;
use App\Filters\ApiCors;
use App\Filters\ApiThrottle;
use App\Filters\CustomerAuth;
use App\Filters\PostSize;
use App\Filters\SecurityHeaders;
use App\Filters\SessionCsrf;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

/**
 * Which filters run where. The route groups in Config\Routes pick the City Florist filters:
 *   postsize    413 when an upload is bigger than PHP's post_max_size (PHP would silently drop it)
 *   apithrottle public website API: 240 requests a minute per device
 *   session     dashboard and customer accounts: session cookie + CSRF check (X-XSRF-TOKEN header)
 *   admin       dashboard login required (admin:owner = owner only, admin:starting-ok = before the first password change)
 *   customer    customer login required
 */
class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     */
    public array $aliases = [
        'csrf'            => CSRF::class,
        'toolbar'         => DebugToolbar::class,
        'honeypot'        => Honeypot::class,
        'invalidchars'    => InvalidChars::class,
        'secureheaders'   => SecureHeaders::class,
        'cors'            => Cors::class,
        'forcehttps'      => ForceHTTPS::class,
        'pagecache'       => PageCache::class,
        'performance'     => PerformanceMetrics::class,
        'securityheaders' => SecurityHeaders::class,
        'postsize'        => PostSize::class,
        'apithrottle'     => ApiThrottle::class,
        'session'         => SessionCsrf::class,
        'admin'           => AdminAuth::class,
        'customer'        => CustomerAuth::class,
        'apicors'         => ApiCors::class,
    ];

    /**
     * Special required filters. They run on every web request, also for 404s and for responses a filter
     * returned early, so the browser security headers are always there.
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'forcehttps', // Force Global Secure Requests
            'pagecache',  // Web Page Caching
            'apicors',    // answers CORS preflights when the sites have their own domains (App\Filters\ApiCors)
        ],
        'after' => [
            'pagecache',       // Web Page Caching
            'securityheaders', // Content-Security-Policy, nosniff, X-Frame-Options … (App\Filters\SecurityHeaders)
        ],
    ];

    /**
     * @var array{before: array<string, array{except: list<string>|string}>|list<string>, after: array<string, array{except: list<string>|string}>|list<string>}
     */
    public array $globals = [
        'before' => [],
        'after'  => [],
    ];

    /**
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [];
}
