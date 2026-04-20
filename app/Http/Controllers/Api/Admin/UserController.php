<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Flights\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role; // Import Role model
use Throwable;

// The UserController (Admin) handles CRUD operations for user accounts
// and also allows assigning/revoking roles to users.
// These operations are strictly for administrative users.
class UserController extends Controller
{
    /**
     * Constructor for UserController.
     * Applies 'manage-users' permission middleware to all methods.
     */
    public function __construct()
    {
        // $this->middleware('permission:manage-users');
    }

    public function index()
    {
        $users = User::with('roles')->latest()->paginate(10);
        return response()->json([
            'message' => 'Users retrieved successfully.',
            'data' => $users,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'phone' => 'nullable|string|max:16|unique:users,phone_number',
                'password' => 'required|string|min:8',
                'type' => 'nullable|string|in:admin,agent,client,user', // Added client
                'walletBalance' => 'nullable|numeric|min:0', 
                'agency_name' => 'nullable|string|max:255',
                'agency_license' => 'nullable|string|max:255',
                'agency_city' => 'nullable|string|max:255',
                'agency_country' => 'nullable|string|max:255',
                'agency_address' => 'nullable|string|max:255',
                'agency_logo' => 'nullable|image|mimes:png,jpg,jpeg,gif,svg|max:2048',
            ]);

            $user = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'phone_number' => $validatedData['phone'] ?? null,
                'password' => Hash::make($validatedData['password']),
                'wallet_balance' => $validatedData['walletBalance'] ?? 0,
                'agency_name' => $validatedData['agency_name'] ?? null,
                'agency_license' => $validatedData['agency_license'] ?? null,
                'agency_city' => $validatedData['agency_city'] ?? null,
                'agency_country' => $validatedData['agency_country'] ?? null,
                'agency_address' => $validatedData['agency_address'] ?? null,
                'agency_logo' => $validatedData['agency_logo'] ?? null,
                'is_active' => true
            ]);

            $roleName = isset($validatedData['type']) ? strtolower($validatedData['type']) : 'client';
            
            // Map "user" to "client" or vice versa if your db is strict
            $role = Role::where('name', $roleName)->first() ?? Role::where('name', 'user')->first();
            
            if ($role) {
                $user->assignRole($role);
            }

            $user->load('roles');

            return response()->json([
                'message' => 'User created successfully.',
                'data' => $user,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(User $user)
    {
        $user->load('roles');
        return response()->json([
            'message' => 'User retrieved successfully.',
            'data' => $user,
        ]);
    }

    public function update(Request $request, $id)
    {
        if (!$id) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        $user = User::find($id);

        try {
            // Include ALL fields in the update validation
            $validatedData = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $user->id,
                'phone' => 'nullable|string|max:16|unique:users,phone_number,' . $user->id,
                'password' => 'nullable|string|min:8', // Keep password nullable
                'type' => 'nullable|string|in:admin,agent,client,user',
                'status' => 'sometimes|string|nullable',
                'walletBalance' => 'nullable|numeric|min:0',
                'agency_name' => 'nullable|string|max:255',
                'agency_license' => 'nullable|string|max:255',
                'agency_country' => 'nullable|string|max:255',
                'agency_city' => 'nullable|string|max:255',
                'agency_address' => 'nullable|string|max:255',
            ]);

            // Update core user details
            $user->name = $validatedData['name'] ?? $user->name;
            $user->email = $validatedData['email'] ?? $user->email;
            $user->phone_number = $validatedData['phone'] ?? $user->phone_number;
            
            // Only update wallet balance if it was provided
            if (isset($validatedData['walletBalance'])) {
                $user->wallet_balance = $validatedData['walletBalance'];
            }

            // Update status mapping to boolean
            if (isset($validatedData['status'])) {
                $user->is_active = (strtolower($validatedData['status']) === 'active');
            }

            // Only update password if a new one was explicitly typed in
            if (!empty($validatedData['password'])) {
                $user->password = Hash::make($validatedData['password']);
            }

            // Update Agency details if role is agent
            if (isset($validatedData['type']) && strtolower($validatedData['type']) === 'agent') {
                $user->agency_name = $validatedData['agency_name'] ?? $user->agency_name;
                $user->agency_license = $validatedData['agency_license'] ?? $user->agency_license;
                $user->agency_country = $validatedData['agency_country'] ?? $user->agency_country;
                $user->agency_city = $validatedData['agency_city'] ?? $user->agency_city;
                $user->agency_address = $validatedData['agency_address'] ?? $user->agency_address;
            }

            $user->save();

            // Sync single role based on 'type' drop-down
            if (!empty($validatedData['type'])) {
                $roleName = strtolower($validatedData['type']);
                $role = Role::where('name', $roleName)->first() ?? Role::where('name', 'user')->first();
                if ($role) {
                    $user->syncRoles([$role->name]);
                }
            }

            $user->load('roles');

            return response()->json([
                'message' => 'User updated successfully.',
                'data' => $user,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('User update failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to update user. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(User $user)
    {
        if (auth()->id() === $user->id) {
            return response()->json(['message' => 'Cannot delete your own user account.'], 403);
        }

        try {
            $user->delete();
            return response()->json(['message' => 'User deleted successfully.'], 204);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function approve($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->is_active = true;
        $user->save();

        return response()->json(['message' => 'User approved successfully.']);
    }
    /**
     * Admin: deposit funds into a user's wallet.
     *
     * POST /api/admin/users/{id}/deposit
     * Body:
     * - amount: numeric|min:0.01
     * - currency: 3-letter code (e.g., KES, USD)
     * - payment_gateway_reference: string|null (unique if provided)
     * - payment_gateway: string|null (defaults to 'admin')
     * - note: string|null
     */
    public function deposit(Request $request, $id)
    {
        // You can also gate: $this->authorize('manage-wallets');

        $validated = $request->validate([
            'amount'    => 'required|numeric|min:0.01',
            'currency'  => 'required|string|size:3',
            'payment_gateway_reference' => 'nullable|string|max:64',
            'payment_gateway' => 'nullable|string|max:64',
            'note'      => 'nullable|string|max:500',
        ]);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Generate a reference if none provided
        $reference = $validated['payment_gateway_reference']
            ?? strtoupper('ADMIN-'.bin2hex(random_bytes(6)));

        // Idempotency: if a completed tx with same reference exists, return it
        $existing = Transaction::where('payment_gateway_reference', $reference)->first();
        if ($existing && $existing->status === 'completed') {
            return response()->json([
                'status'  => true,
                'message' => 'Deposit already processed.',
                'data' => [
                    'trx_id'         => $existing->payment_gateway_reference,
                    'date'           => optional($existing->transaction_date)->toDateString(),
                    'amount'         => (float) $existing->amount,
                    'currency'       => $existing->currency,
                    'payment_gateway'=> $existing->payment_gateway,
                    'status'         => $existing->status,
                    'balance_after'  => (float) $user->wallet_balance,
                ],
            ], 200);
        }

        try {
            $result = DB::transaction(function () use ($user, $validated, $reference) {
                // Lock user row to avoid race conditions
                $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();

                $before = (float) ($lockedUser->wallet_balance ?? 0.0);
                $amount = (float) $validated['amount'];
                $after  = $before + $amount;

                // Create transaction as completed (admin credit)
                $tx = Transaction::create([
                    'user_id'    => $lockedUser->id,
                    'booking_id' => null,
                    'amount'     => $amount,
                    'currency'   => strtoupper($validated['currency']),
                    'type'       => 'wallet_topup',
                    'status'     => 'completed',
                    'payment_gateway_reference' => $reference,
                    'transaction_date' => now(),
                    'payment_gateway' => $validated['payment_gateway'] ?? 'admin',
                    'description' => $validated['note'] ?? null,
                ]);

                // Credit wallet
                $lockedUser->wallet_balance = $after;
                $lockedUser->save();

                return [$tx, $before, $after];
            });

            /** @var \App\Models\Flights\Transaction $tx */
            [$tx, $before, $after] = $result;

            return response()->json([
                'status'  => true,
                'message' => 'Deposit successful.',
                'data'    => [
                    'trx_id'         => $tx->payment_gateway_reference,
                    'date'           => optional($tx->transaction_date)->toDateString(),
                    'amount'         => (float) $tx->amount,
                    'currency'       => $tx->currency,
                    'payment_gateway'=> $tx->payment_gateway,
                    'status'         => $tx->status,
                    'balance_before' => $before,
                    'balance_after'  => $after,
                    'performed_by'   => Auth::id(),
                ],
            ], 200);

        } catch (Throwable $e) {
            Log::error('Admin deposit failed: '.$e->getMessage(), ['exception' => $e]);
            return response()->json([
                'status'  => false,
                'message' => 'Failed to deposit funds. Please try again later.',
                'error'   => app()->environment('production') ? null : $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Admin: deduct funds from a user's wallet.
     *
     * POST /api/admin/users/{id}/debit
     * Body:
     * - amount: numeric|min:0.01
     * - currency: 3-letter code (e.g., KES, USD)
     * - payment_gateway_reference: string|null (unique if provided)
     * - payment_gateway: string|null (defaults to 'admin')
     * - note: string|null
     * - allow_negative: boolean|null (default false)  // optional, if you ever want to allow overdrafts
     */
    public function debit(Request $request, $id)
    {
        // $this->authorize('manage-wallets');

        $validated = $request->validate([
            'amount'    => 'required|numeric|min:0.01',
            'currency'  => 'required|string|size:3',
            'payment_gateway_reference' => 'nullable|string|max:64',
            'payment_gateway' => 'nullable|string|max:64',
            'note'      => 'nullable|string|max:500',
            'allow_negative' => 'nullable|boolean',
        ]);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Generate a reference if none provided
        $reference = $validated['payment_gateway_reference']
            ?? strtoupper('ADMIN-'.bin2hex(random_bytes(6)));

        // Idempotency: if a completed tx with same reference exists, return it
        $existing = Transaction::where('payment_gateway_reference', $reference)->first();
        if ($existing && $existing->status === 'completed') {
            return response()->json([
                'status'  => true,
                'message' => 'Debit already processed.',
                'data' => [
                    'trx_id'         => $existing->payment_gateway_reference,
                    'date'           => optional($existing->transaction_date)->toDateString(),
                    'amount'         => (float) $existing->amount,
                    'currency'       => $existing->currency,
                    'payment_gateway'=> $existing->payment_gateway,
                    'status'         => $existing->status,
                    'balance_after'  => (float) $user->wallet_balance,
                ],
            ], 200);
        }

        $allowNegative = (bool) ($validated['allow_negative'] ?? false);

        try {
            $result = DB::transaction(function () use ($user, $validated, $reference, $allowNegative) {
                // Lock the user row
                $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();

                $before = (float) ($lockedUser->wallet_balance ?? 0.0);
                $amount = (float) $validated['amount'];
                $after  = $before - $amount;

                if (!$allowNegative && $after < 0) {
                    abort(409, 'Insufficient wallet balance for debit.');
                }

                // Create transaction as completed (admin debit)
                $tx = Transaction::create([
                    'user_id'    => $lockedUser->id,
                    'booking_id' => null,
                    'amount'     => $amount, // keep positive; we track via "type"
                    'currency'   => strtoupper($validated['currency']),
                    'type'       => 'wallet_debit',
                    'status'     => 'completed',
                    'payment_gateway_reference' => $reference,
                    'transaction_date' => now(),
                    'payment_gateway' => $validated['payment_gateway'] ?? 'admin',
                    'description' => $validated['note'] ?? null,
                ]);

                // Debit wallet
                $lockedUser->wallet_balance = $after;
                $lockedUser->save();

                return [$tx, $before, $after];
            });

            /** @var \App\Models\Flights\Transaction $tx */
            [$tx, $before, $after] = $result;

            return response()->json([
                'status'  => true,
                'message' => 'Debit successful.',
                'data'    => [
                    'trx_id'         => $tx->payment_gateway_reference,
                    'date'           => optional($tx->transaction_date)->toDateString(),
                    'amount'         => (float) $tx->amount,
                    'currency'       => $tx->currency,
                    'payment_gateway'=> $tx->payment_gateway,
                    'status'         => $tx->status,
                    'balance_before' => $before,
                    'balance_after'  => $after,
                    'performed_by'   => Auth::id(),
                ],
            ], 200);

        } catch (Throwable $e) {
            // $code = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            // if ($code === 409) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => $e->getMessage(),
            //     ], 409);
            // }

            Log::error('Admin debit failed: '.$e->getMessage(), ['exception' => $e]);
            return response()->json([
                'status'  => false,
                'message' => 'Failed to debit funds. Please try again later.',
                'error'   => app()->environment('production') ? null : $e->getMessage(),
            ], 500);
        }
    }

}
