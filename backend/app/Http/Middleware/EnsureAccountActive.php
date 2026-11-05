<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A bidder disabled by an admin is signed out on their next request, whatever
 * it is: their session may still exist if sessions are not stored in the database.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('sanctum')?->isDisabled()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw new ApiException(ErrorCode::AccountDisabled, 'This account has been disabled. Contact LiveBid support.');
        }

        return $next($request);
    }
}
