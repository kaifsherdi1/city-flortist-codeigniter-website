<?php

use CodeIgniter\Router\RouteCollection;

/**
 * Every URL the backend answers. Same paths, methods and JSON as the Laravel version, so both React apps
 * work unchanged.
 *
 * @var RouteCollection $routes
 */

// ---------------------------------------------------------------------------------------------------------
// Public website API (frontend-user). Stateless: no session cookie. 240 requests a minute per device.
// ---------------------------------------------------------------------------------------------------------
$routes->group('api', ['filter' => ['postsize', 'apithrottle']], static function (RouteCollection $routes): void {
    $routes->get('site', 'Api\Catalog::site');
    $routes->get('products', 'Api\Catalog::products');
    $routes->post('orders', 'Api\Orders::store');
    $routes->post('reviews', 'Api\Reviews::store');
});

// ---------------------------------------------------------------------------------------------------------
// Dashboard API (frontend-dashboard): /api/admin/*. Session cookie login + CSRF (X-XSRF-TOKEN header).
// ---------------------------------------------------------------------------------------------------------
$routes->group('api/admin', ['filter' => ['postsize', 'session']], static function (RouteCollection $routes): void {
    $routes->get('status', 'Admin\Auth::status');
    $routes->post('install', 'Admin\Auth::install');
    $routes->post('login', 'Admin\Auth::login');
    $routes->post('login/two-factor', 'Admin\Auth::twoFactor');
    $routes->post('logout', 'Admin\Auth::logout');
    // Staff sign-up: creates a locked account the owner must approve (Settings → Team). Logs nobody in.
    $routes->post('register', 'Admin\Auth::register');
    $routes->post('password/forgot', 'Admin\Auth::forgot');
    $routes->post('password/reset', 'Admin\Auth::reset');
    $routes->get('oauth/google', 'Admin\Auth::googleRedirect');
    $routes->get('oauth/google/callback', 'Admin\Auth::googleCallback');
});

// Changing the password is the one thing allowed while still on the starting password. It also signs out
// every other browser and "remember me" cookie.
$routes->put('api/admin/settings/password', 'Admin\Settings::password', ['filter' => ['postsize', 'session', 'admin:starting-ok']]);

$routes->group('api/admin', ['filter' => ['postsize', 'session', 'admin']], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Admin\Dashboard::index');
    $routes->put('dashboard/target', 'Admin\Dashboard::target');

    $routes->get('categories', 'Admin\Products::categories');
    $routes->get('products', 'Admin\Products::index');
    $routes->post('products', 'Admin\Products::store');
    $routes->post('products/bulk', 'Admin\Products::bulk');
    $routes->get('products/(:segment)', 'Admin\Products::show/$1');
    // POST, not PUT: PHP only parses uploaded files on POST.
    $routes->post('products/(:segment)', 'Admin\Products::update/$1');
    $routes->delete('products/(:segment)', 'Admin\Products::destroy/$1');
    $routes->post('products/(:segment)/images/(:segment)/main', 'Admin\Products::mainImage/$1/$2');
    $routes->delete('products/(:segment)/images/(:segment)', 'Admin\Products::deleteImage/$1/$2');

    $routes->get('offers', 'Admin\Offers::index');
    $routes->post('offers', 'Admin\Offers::store');
    $routes->post('offers/(:segment)', 'Admin\Offers::update/$1');
    $routes->post('offers/(:segment)/toggle', 'Admin\Offers::toggle/$1');
    $routes->delete('offers/(:segment)', 'Admin\Offers::destroy/$1');

    $routes->get('orders', 'Admin\Orders::index');
    $routes->get('orders/export', 'Admin\Orders::export');
    $routes->patch('orders/(:segment)', 'Admin\Orders::update/$1');
    $routes->delete('orders/(:segment)', 'Admin\Orders::destroy/$1');

    $routes->get('reviews', 'Admin\Reviews::index');
    $routes->patch('reviews/(:segment)', 'Admin\Reviews::update/$1');
    $routes->delete('reviews/(:segment)', 'Admin\Reviews::destroy/$1');

    $routes->get('settings', 'Admin\Settings::show');
    $routes->put('settings/account', 'Admin\Settings::account');
    $routes->post('settings/two-factor/setup', 'Admin\TwoFactor::setup');
    $routes->post('settings/two-factor/confirm', 'Admin\TwoFactor::confirm');
    $routes->post('settings/two-factor/disable', 'Admin\TwoFactor::disable');
    $routes->post('settings/two-factor/recovery-codes', 'Admin\TwoFactor::recoveryCodes');
});

// Owner only: shop details, categories and the team. Staff manage products, offers, orders and reviews.
$routes->group('api/admin', ['filter' => ['postsize', 'session', 'admin:owner']], static function (RouteCollection $routes): void {
    $routes->put('settings/shop', 'Admin\Settings::shop');
    $routes->post('settings/categories', 'Admin\Settings::categoriesSave');
    $routes->get('team', 'Admin\Team::index');
    $routes->patch('team/(:segment)', 'Admin\Team::update/$1');
    $routes->delete('team/(:segment)', 'Admin\Team::destroy/$1');
});

// ---------------------------------------------------------------------------------------------------------
// The React apps, built into public/ (index.html + assets/, admin.html + admin-assets/). On Hostinger,
// public/.htaccess serves these files directly; these routes cover `php spark serve` and other servers.
// Anything else that isn't a file is a React route: see Web::notFound (Config\Routing::$override404).
// ---------------------------------------------------------------------------------------------------------
$routes->get('up', 'Web::health');
$routes->get('sitemap.xml', 'Web::sitemap');
// Product links in WhatsApp order messages: the chat previews the product's photo (App\Controllers\Share).
$routes->get('p/(:segment)/photo.jpg', 'Share::photo/$1');
$routes->get('p/(:segment)', 'Share::product/$1');
$routes->get('admin', 'Web::admin');
$routes->get('admin/(:any)', 'Web::admin');
$routes->get('/', 'Web::index');
