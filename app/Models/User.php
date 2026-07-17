<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Flights\Booking;
use App\Models\Flights\Transaction;
use Arden28\Guardian\Traits\GuardianUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, GuardianUser;

    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'phone_country_code',
        'wallet_balance',
        'is_active',
        'agency_name',
        'agency_license',
        'agency_country',
        'agency_city',
        'agency_address',
        'agency_logo',
        'agency_currency',
        'agency_markup',
        'password', // Added to allow mass-assignment
        'telegram_id',
        'telegram_username',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
    ];

    protected $appends = ['role'];

    public function getRoleAttribute(): string
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->first()?->name ?? 'user';
        }
        return $this->getRoleNames()->first() ?? 'user';
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'user_id', 'id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
