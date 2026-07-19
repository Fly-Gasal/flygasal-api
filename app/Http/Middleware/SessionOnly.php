<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block API key tokens from accessing routes reserved for session (human) auth.
 *
 * Developer management routes (key creation, webhook config) must only be
 * reachable via a browser session — not via an API key — so that a leaked
 * key cannot mint more keys or tamper with webhook endpoints.
 */
class SessionOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && str_starts_with($token->name, 'api-key:')) {
            return response()->json([
                'message' => 'This endpoint requires session authentication and cannot be accessed via an API key.',
            ], 403);
        }

        return $next($request);
    }
}
