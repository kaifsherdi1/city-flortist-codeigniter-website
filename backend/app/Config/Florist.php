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

    public function googleConfigured(): bool
    {
        return $this->googleClientId !== '' && $this->googleClientSecret !== '';
    }
}
