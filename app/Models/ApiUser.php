<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUser extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'api_key',
        'plan',
        'status',
        'use_case',
        'requests_today',
        'requests_limit',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_used_at' => 'datetime',
    ];

    /** Requests-per-day limits by plan. */
    public const PLAN_LIMITS = [
        'free'       => 1_000,
        'basic'      => 10_000,
        'pro'        => 100_000,
        'enterprise' => 1_000_000,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Generate a fresh API key with the fgk_ prefix. */
    public static function generateKey(): string
    {
        return 'fgk_' . bin2hex(random_bytes(32));
    }

    /** Check whether this user has remaining quota for today. */
    public function hasQuota(): bool
    {
        return $this->requests_today < $this->requests_limit;
    }

    /** Increment today's request counter and touch last_used_at. */
    public function recordRequest(): void
    {
        $this->increment('requests_today');
        $this->update(['last_used_at' => now()]);
    }
}
