<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuth
{
    /**
     * Routes an admin may still reach while the first-run password change is
     * outstanding: the profile page itself and the form that resolves it.
     */
    private const ALLOWED_WHILE_STALE = ['admin.profile', 'admin.password', 'admin.logout'];

    public function handle(Request $request, Closure $next)
    {
        if (! Auth::check()) {
            return redirect()->route('admin.login');
        }

        // The seeded password is known to anybody who has read the repository,
        // so an account still carrying it can do nothing else until it is
        // replaced.
        if (Auth::user()->mustChangePassword()
            && ! in_array($request->route()?->getName(), self::ALLOWED_WHILE_STALE, true)) {
            return redirect()->route('admin.profile')
                ->with('status', 'Choose your own password before you use the admin panel.');
        }

        return $next($request);
    }
}
