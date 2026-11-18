<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * City Florist settings. Each one can be set in .env as florist.<name>, e.g. florist.siteUrl = https://example.com
 */
class Florist extends BaseConfig
{
    /** Public URL of the website, no trailing slash. Used for links in emails, the sitemap and WhatsApp orders. */
    public string $siteUrl = 'http://localhost:5173';

    /**
     * Public URL of the owner dashboard when it has its own subdomain (e.g. https://admin.example.com), no trailing
     * slash. Empty: the dashboard is <siteUrl>/admin, on the same domain as the website.
     */
    public string $adminUrl = '';

    /**
     * true when the website, the dashboard and this backend each have their own (sub)domain, e.g. example.com,
     * admin.example.com and api.example.com. Then the two sites may call this API from the browser (CORS), photo
     * URLs in API answers are absolute (<app.baseURL>uploads/…), and Google sign-in sends people back to siteUrl /
     * adminUrl. false: everything is served from one domain.
     * The login cookies must then be shared by all three: set cookie.domain = .example.com in .env.
     */
    public bool $separateDomains = false;

    /**
     * Extra browser origins allowed to call the API when separateDomains is on, comma-separated
     * (e.g. http://localhost:5173 while testing). siteUrl, its www. twin and adminUrl are always allowed.
     */
    public string $corsOrigins = '';

    /** First admin login, created by the installer (/admin → Install). Change the password in Settings after first login. */
    public string $adminUsername = 'admin';

    public string $adminPassword = 'ChangeMe@123';

    /** The owner can also log in with these; the email receives password-reset links and is the Google account allowed in. */
    public string $adminEmail = 'cityfloristh@gmail.com';

    public string $adminPhone = '9900094843';

    /** Default owner WhatsApp number (country code + number, digits only). Editable later in Admin → Settings. */
    public string $whatsappNumber = '919900094843';

    /** Where `php spark florist:export-static` writes the catalogue snapshot used by static hosts (Vercel). */
    public string $frontendPath = ROOTPATH . '../frontend-user';

    /**
     * Only if a CDN or proxy you control sits in front of the site: its IPs or ranges, comma-separated (read by
     * Config\App). Leave empty on plain Hostinger, or visitors could fake their IP and dodge the login limit.
     */
    public string $trustedProxies = '';

    /**
     * How emails (password-reset links) are sent: "log" only writes them to writable/logs (fine for local
     * testing); "smtp", "mail" or "sendmail" really send them using the email.* settings.
     */
    public string $mailer = 'log';

    /** "Continue with Google" (optional): an OAuth client from console.cloud.google.com → Credentials. */
    public string $googleClientId = '';

    public string $googleClientSecret = '';

    /** The dashboard's callback URL registered with Google. Empty: <app.baseURL>/api/admin/oauth/google/callback. */
    public string $googleRedirect = '';

    /** The web root: photos are stored under <publicPath>/uploads/. */
    public string $publicPath = FCPATH;

    /** Starter photos and their manifest.json, imported by the installer and florist:demo-data. */
    public string $seedPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR . 'Seeds' . DIRECTORY_SEPARATOR . 'starter' . DIRECTORY_SEPARATOR;

    /** How long "Remember me" keeps someone logged in. */
    public int $rememberDays = 400;

    /** Live-site rules (refuse the example admin password at install). null: when CI_ENVIRONMENT is production. */
    public ?bool $production = null;

    /** Show real error messages in API errors. null: follows CI_DEBUG (on in development, off in production). */
    public ?bool $debug = null;

    public function isProduction(): bool
    {
        return $this->production ?? (ENVIRONMENT === 'production');
    }

    public function isDebug(): bool
    {
        return $this->debug ?? (defined('CI_DEBUG') && CI_DEBUG);
    }

    /** The dashboard's address, no trailing slash: adminUrl, or <siteUrl>/admin. Used in emailed links. */
    public function adminBase(): string
    {
        return $this->adminUrl !== '' ? rtrim($this->adminUrl, '/') : rtrim($this->siteUrl, '/') . '/admin';
    }

    /**
     * A dashboard page to send the browser to ("login?error=…"): "/admin/login?error=…" on this same domain, or
     * "https://admin.example.com/login?error=…" when the sites have their own domains.
     */
    public function adminPage(string $path = ''): string
    {
        $path = ltrim($path, '/');

        return $this->separateDomains
            ? $this->adminBase() . '/' . $path
            : '/admin' . ($path === '' ? '' : '/' . $path);
    }

    /** A page of the public website to send the browser to ("/account"): on this domain, or on siteUrl. */
    public function websitePage(string $path): string
    {
        return $this->separateDomains ? rtrim($this->siteUrl, '/') . '/' . ltrim($path, '/') : $path;
    }

    /**
     * Browser origins ("https://example.com", no path) allowed to call the API with cookies. Empty unless
     * separateDomains is on.
     *
     * @return list<string>
     */
    public function allowedOrigins(): array
    {
        if (! $this->separateDomains) {
            return [];
        }
        $origin = static function (string $url): ?string {
            $parts = parse_url(trim($url));

            return isset($parts['scheme'], $parts['host'])
                ? strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : null;
        };
        $origins = [];
        if (($site = $origin($this->siteUrl)) !== null) {
            // example.com and www.example.com are both the website.
            $origins[] = $site;
            $origins[] = str_contains($site, '://www.') ? str_replace('://www.', '://', $site) : str_replace('://', '://www.', $site);
        }
        foreach (array_merge([$this->adminBase()], explode(',', $this->corsOrigins)) as $url) {
            if (($o = $origin($url)) !== null) {
                $origins[] = $o;
            }
        }

        return array_values(array_unique($origins));
    }

    public function googleConfigured(): bool
    {
        return $this->googleClientId !== '' && $this->googleClientSecret !== '';
    }
}
