<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce Sanctum token scopes on API key requests.
 *
 * Usage: ->middleware('scope:flights:search')
 *
 * Session-authenticated requests (no token) pass through unconditionally
 * because they represent logged-in SPA users who have full access.
 * Sanctum tokens created without abilities default to ['*'], so they
 * also pass every scope check — only explicitly scoped API keys are
 * restricted to the abilities listed on the token.
 */
class RequireTokenScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->user()?->currentAccessToken();

        // No token means cookie/session auth → skip scope enforcement
        if ($token && !$request->user()->tokenCan($scope)) {
            return response()->json([
                'message' => "Insufficient scope. This endpoint requires the '{$scope}' permission.",
            ], 403);
        }

        return $next($request);
    }
}
