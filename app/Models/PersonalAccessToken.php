<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Extends Sanctum's token model to handle two fgk_ token formats:
 *
 *  1. DeveloperController tokens  — fgk_<id>|<hash>
 *     Strip the prefix, delegate to standard Sanctum lookup.
 *
 *  2. ApiUser keys                — fgk_<64 hex chars>
 *     Look up api_users.api_key, validate status + quota, then find or
 *     create a long-lived proxy Sanctum token for the linked user account
 *     so the rest of the auth pipeline (scopes, $request->user()) works
 *     without any changes to controllers or middleware.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public static function findToken($token): static|null
    {
        if (!str_starts_with($token, 'fgk_')) {
            return parent::findToken($token);
        }

        $stripped = substr($token, 4);

        // Format 1: DeveloperController Sanctum token (contains the | separator)
        if (str_contains($stripped, '|')) {
            return parent::findToken($stripped);
        }

        // Format 2: ApiUser key — exactly 64 lowercase hex characters
        if (!preg_match('/^[0-9a-f]{64}$/', $stripped)) {
            return null;
        }

        $apiUser = ApiUser::where('api_key', $token)
            ->where('status', 'active')
            ->where('is_active', true)
            ->first();

        if (!$apiUser || !$apiUser->hasQuota() || !$apiUser->user_id) {
            return null;
        }

        // Find or create a long-lived proxy Sanctum token for the linked user.
        // One proxy token per ApiUser is created on first use and reused thereafter.
        $proxyName = 'api-user-proxy:' . $apiUser->id;
        $proxy     = static::where('name', $proxyName)
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $apiUser->user_id)
            ->first();

        if (!$proxy) {
            $user = User::find($apiUser->user_id);
            if (!$user) {
                return null;
            }
            $proxy = $user->createToken($proxyName, ['*'])->accessToken;
        }

        $apiUser->recordRequest();

        return $proxy;
    }
}
