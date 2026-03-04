<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Session-based auth middleware.
 * Checks for a JWT token in the session (set during login via AuthController).
 * Redirects to /login if not authenticated.
 */
class SessionAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!Session::has('jwt_token')) {
            return redirect()->route('login')
                ->with('info', 'Please log in to access the dashboard.');
        }

        return $next($request);
    }
}
