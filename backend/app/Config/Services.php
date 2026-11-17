<?php

namespace Config;

use App\Libraries\Auth\Guard;
use App\Libraries\GoogleOAuth;
use App\Libraries\Mailer;
use App\Libraries\RateLimiter;
use App\Models\AdminModel;
use App\Models\CustomerModel;
use CodeIgniter\Config\BaseService;

/**
 * City Florist services. Tests can swap any of them with Services::injectMock().
 */
class Services extends BaseService
{
    /** Attempt counters for login limits, sign-ups, orders and the public API (Laravel's RateLimiter). */
    public static function rateLimiter(bool $getShared = true): RateLimiter
    {
        if ($getShared) {
            return static::getSharedInstance('rateLimiter');
        }

        return new RateLimiter(service('cache'));
    }

    /** The dashboard login: the owner and approved staff (table `admins`). */
    public static function adminAuth(bool $getShared = true): Guard
    {
        if ($getShared) {
            return static::getSharedInstance('adminAuth');
        }

        return new Guard(new AdminModel(), 'admin', 'remember_admin', true);
    }

    /** Customer accounts on the website (table `customers`). Never opens the dashboard. */
    public static function customerAuth(bool $getShared = true): Guard
    {
        if ($getShared) {
            return static::getSharedInstance('customerAuth');
        }

        return new Guard(new CustomerModel(), 'customer', 'remember_customer', false);
    }

    /** "Continue with Google" (OAuth 2.0). */
    public static function googleOAuth(bool $getShared = true): GoogleOAuth
    {
        if ($getShared) {
            return static::getSharedInstance('googleOAuth');
        }

        return new GoogleOAuth(config(Florist::class));
    }

    /** Password-reset emails. */
    public static function mailer(bool $getShared = true): Mailer
    {
        if ($getShared) {
            return static::getSharedInstance('mailer');
        }

        return new Mailer(config(Florist::class));
    }
}
