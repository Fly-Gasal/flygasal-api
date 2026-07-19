<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Extends Sanctum's token model to support the fgk_ prefix on agency API keys.
 *
 * When the frontend receives a plain token it is prefixed with "fgk_" before
 * being displayed to the user (e.g. "fgk_1|abc123..."). This model strips that
 * prefix transparently before the token is looked up in the database, so the
 * actual stored format remains the standard Sanctum "{id}|{hash}" structure.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public static function findToken($token): static|null
    {
        if (str_starts_with($token, 'fgk_')) {
            $token = substr($token, 4);
        }

        return parent::findToken($token);
    }
}
