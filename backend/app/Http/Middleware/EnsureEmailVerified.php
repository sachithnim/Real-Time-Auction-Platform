<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bidding and paying need a confirmed email, so every bid comes from a real,
 * reachable person. Browsing and watching do not.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->hasVerifiedEmail()) {
            throw new ApiException(ErrorCode::EmailNotVerified, 'Confirm your email address before bidding. Check your inbox for the link.');
        }

        return $next($request);
    }
}
