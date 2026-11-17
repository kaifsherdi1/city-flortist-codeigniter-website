<?php

namespace App\Filters;

use App\Libraries\Auth\SessionState;
use App\Libraries\Http;
use App\Models\AdminModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Dashboard API access (Laravel's auth + auth.session + AdminAccess + PasswordChangeRequired middleware):
 *  - not logged in → 401;
 *  - the password was changed since this session logged in → logged out, 401;
 *  - an account the owner switched off (or never approved) is logged out on its next request → 401;
 *  - still on the starting password → 403 until it is changed (except with the argument "starting-ok");
 *  - with the argument "owner" (admin:owner), only the shop owner gets through, not staff → 403.
 * Runs after the `session` filter.
 */
class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $arguments = (array) $arguments;
        $auth      = service('adminAuth');
        $admin     = $auth->user();

        // Laravel's auth and auth.session middleware ran before its ApiJson middleware ("no-cache" answers);
        // AdminAccess and PasswordChangeRequired ran after it ("no-store").
        if ($admin === null) {
            return Http::earlyError(Http::messageFor(401), 401);
        }
        if (! $auth->passwordUnchanged($admin)) {
            $auth->logoutCurrentDevice();
            SessionState::flush();
            SessionState::regenerateToken();

            return Http::earlyError(Http::messageFor(401), 401);
        }
        if (! AdminModel::canLogIn($admin)) {
            $auth->logout();
            SessionState::invalidate();

            return Http::error('This account has been switched off by the shop owner.', 401);
        }
        if (! in_array('starting-ok', $arguments, true) && session('must_change_password')) {
            return Http::error('Please choose a new password first. The starting password is not safe to keep.', 403, ['must_change_password' => true]);
        }
        if (in_array('owner', $arguments, true) && ! AdminModel::isOwner($admin)) {
            return Http::error('Only the shop owner can do this.', 403);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
