<?php

namespace Config;

use App\Exceptions\ApiExceptionHandler;
use App\Libraries\Auth\SessionState;
use App\Libraries\Catalog;
use App\Libraries\Settings;
use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files.
 *
 * Events::on('create', [$myInstance, 'myMethod']);
 */

Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'testing') {
        $value = ini_get('zlib.output_compression');

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN) || (int) $value > 0) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn ($buffer) => $buffer);
    }

    ApiExceptionHandler::responseSent(false);

    // Settings, running offers and the logged-in accounts are looked up once per request. Start each request
    // fresh, for processes that serve several (the test suite).
    Settings::flush();
    Catalog::flush();
    SessionState::reset();
    service('adminAuth')->flush();
    service('customerAuth')->flush();
});

// CodeIgniter's last event: the answer is about to go out. From now on an error must not print a second body
// (see App\Exceptions\ApiExceptionHandler).
Events::on('post_system', static function (): void {
    ApiExceptionHandler::responseSent(true);
});
