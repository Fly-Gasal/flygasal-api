<?php

use App\Http\Controllers\Api\Admin\{
    DashboardController,
    AirlineController,
    AirportController,
    RoleController,
    UserController
};
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\{
    BookingController,
    PaymentGatewayController,
    ProfileController,
    TelegramAuthController,
    TransactionController
};
use App\Http\Controllers\Api\v2\FlightController;
use App\Http\Controllers\{
    SettingsController,
    WebhookController
};
use App\Models\Flights\Airport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| All routes defined here are automatically prefixed with "/api".
| They are grouped into:
|   - Public (no authentication)
|   - Webhook callbacks (PKFare, etc.)
|   - Auth (login/register)
|   - Proxy (3rd party APIs like countries/airports)
|   - Authenticated (user profile, bookings, transactions, etc.)
|   - Admin (protected by permissions)
|
*/

/*
|--------------------------------------------------------------------------
| Public Routes (No Auth Required)
|--------------------------------------------------------------------------
*/
Route::get('/status', fn() => response()->json([
    'message' => 'API is up and running!',
    'version' => '1.0.0'
]));

/*
|--------------------------------------------------------------------------
| Webhook Routes (PKFare Callbacks)
|--------------------------------------------------------------------------
| These endpoints receive async notifications from PKFare.
| They should remain publicly accessible but secure via signature validation.
*/
Route::prefix('pkfare')->group(function () {
    Route::post('/ticket-issuance-notify-v2', [WebhookController::class, 'ticketIssuanceNotify']);
    Route::post('/refund-result',             [WebhookController::class, 'refundResultNotify']);
    Route::post('/reimbursed-result',         [WebhookController::class, 'reimbursedResultNotify']);
    Route::post('/schedule-change',           [WebhookController::class, 'scheduleChangeNotify']);
});

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);
Route::post('/auth/telegram', [TelegramAuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Proxy Routes (External APIs)
|--------------------------------------------------------------------------
*/
Route::get('/proxy/countries', fn() => Http::get('https://apicountries.com/countries')->json());

Route::get('/proxy/airports', function (Request $request) {
    return Airport::where('name', 'LIKE', "%{$request->q}%")
        ->orWhere('iata', 'LIKE', "%{$request->q}%")
        ->orWhere('city', 'LIKE', "%{$request->q}%")
        ->limit(10)
        ->get();
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes (Requires Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Current User Profile
    |----------------------------------------------------------------------
    */
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'phone_number'      => $user->phone_number ?? 'N/A',
            'email_verified_at' => optional($user->email_verified_at)->toDateTimeString() ?? 'Not verified',
            'phone_verified_at' => optional($user->phone_verified_at)->toDateTimeString() ?? 'Not verified',
            'phone_country_code'=> $user->phone_country_code ?? 'N/A',
            'is_active'         => $user->is_active,
            'wallet_balance'    => number_format($user->wallet_balance, 2, '.', ''),
            'agency_name'       => $user->agency_name ?? 'N/A',
            'agency_license'    => $user->agency_license ?? 'N/A',
            'agency_country'    => $user->agency_country ?? 'N/A',
            'agency_city'       => $user->agency_city ?? 'N/A',
            'agency_address'    => $user->agency_address ?? 'N/A',
            'agency_logo'       => $user->agency_logo ? asset($user->agency_logo) : null,
            'agency_currency'   => $user->agency_currency ?? 'USD',
            'agency_markup'     => number_format($user->agency_markup, 2, '.', ''),
            'role'              => $user->getRoleNames()->first() ?? 'No role assigned',
            'booking_count'     => $user->bookings()->count() ?? 0,
        ]);
    });

    /*
    |----------------------------------------------------------------------
    | Profile Management
    |----------------------------------------------------------------------
    */
    Route::apiResource('profile', ProfileController::class)->except(['index', 'store']);

    /*
    |----------------------------------------------------------------------
    | Auth Session Management
    |----------------------------------------------------------------------
    */
    Route::post('/logout', [AuthController::class, 'logout']);

    /*
    |----------------------------------------------------------------------
    | Flights
    |----------------------------------------------------------------------
    */
    Route::post('/flights/search',          [FlightController::class, 'search']);
    Route::post('/flights/precise-pricing', [FlightController::class, 'precisePricing']);
    Route::post('/flights/ancillary-pricing', [FlightController::class, 'ancillaryPricing']);

    /*
    |----------------------------------------------------------------------
    | Bookings
    |----------------------------------------------------------------------
    */
    Route::get('/bookings',                     [BookingController::class, 'index']);
    Route::post('/flights/bookings',            [BookingController::class, 'store']);
    Route::get('/bookings/{booking}',           [BookingController::class, 'orderDetails']);
    Route::post('/bookings/{booking}/cancel',   [BookingController::class, 'cancel']);
    Route::post('/bookings/ticketing',          [BookingController::class, 'ticketOrder']);

    /*
    |----------------------------------------------------------------------
    | Transactions & Payments
    |----------------------------------------------------------------------
    */
    Route::get('transactions',               [TransactionController::class, 'index']);
    Route::post('transactions/add',          [TransactionController::class, 'store']);
    Route::post('transactions/pay',          [TransactionController::class, 'walletPay']);
    Route::post('transactions/approve',      [TransactionController::class, 'approveOrReject']);
    Route::post('payment_gateways',          [PaymentGatewayController::class, 'index']);

    /*
    |----------------------------------------------------------------------
    | Admin Panel (Requires proper permissions)
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')->group(function () {

        // Dashboard
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/sales',   [DashboardController::class, 'sales']);

        // Airport & Airline Management
        Route::apiResource('airports', AirportController::class);
        Route::apiResource('airlines', AirlineController::class);

        // User Management
        Route::apiResource('users', UserController::class);
        Route::post('/users/{id}/approve', [UserController::class, 'approve']);
        Route::post('/users/{id}/deposit', [UserController::class, 'deposit']);
        Route::post('/users/{id}/debit', [UserController::class, 'debit']);

        // System Settings
        Route::get('/settings',         [SettingsController::class, 'getGeneralSettings']);
        Route::post('/settings',        [SettingsController::class, 'updateGeneralSettings']);
        Route::get('/email-settings',   [SettingsController::class, 'getEmailSettings']);
        Route::post('/email-settings',  [SettingsController::class, 'updateEmailSettings']);
        Route::get('/pkfare-settings',  [SettingsController::class, 'getPkfareSettings']);
        Route::post('/pkfare-settings', [SettingsController::class, 'updatePkfareSettings']);

        // Roles & Permissions
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions',       [RoleController::class, 'permissions']);
    });
});
