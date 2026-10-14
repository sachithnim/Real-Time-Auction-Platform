<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:ADMIN') or ->middleware('role:BIDDER').
 * Runs after auth:sanctum, so the user is always present.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if ($request->user()?->role !== UserRole::from($role)) {
            throw new ApiException(ErrorCode::Forbidden, 'Your account role cannot perform this action.');
        }

        return $next($request);
    }
}
