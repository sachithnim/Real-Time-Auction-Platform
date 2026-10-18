<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login and registration create a session cookie. Sanctum only starts a
 * session for requests that come from the web app (matching Origin/Referer),
 * so any other client gets a clear error instead of a server crash.
 */
class EnsureSessionStarted
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            $origin = config('cors.allowed_origins')[0] ?? 'http://localhost:5173';

            throw new ApiException(
                ErrorCode::StatefulRequestRequired,
                "This endpoint uses session cookies. Send it from the web app, or add the header Origin: {$origin}.",
                ['required_origin' => $origin],
            );
        }

        return $next($request);
    }
}
