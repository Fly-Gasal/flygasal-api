<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name'            => 'required|string|max:255',
                'email'           => 'required|string|email|max:255|unique:users',
                'phone_number'    => 'required|string|max:15',
                'password'        => 'required|string|min:8',
                'role'            => 'nullable|string|in:agent,user',
                'agency_name'     => 'nullable|string|max:255',
                'agency_license'  => 'nullable|string|max:255',
                'agency_city'     => 'nullable|string|max:255',
                'agency_address'  => 'nullable|string|max:255',
                'agency_logo'     => 'nullable|image|mimes:png,jpg,jpeg,gif,svg|max:2048',
            ]);

            // Store the logo file and keep only its path
            $logoPath = null;
            if ($request->hasFile('agency_logo')) {
                $logoPath = $request->file('agency_logo')->store('agency-logos', 'public');
            }

            $user = User::create([
                'name'            => $validatedData['name'],
                'email'           => $validatedData['email'],
                'phone_number'    => $validatedData['phone_number'],
                'password'        => Hash::make($validatedData['password']),
                'wallet_balance'  => 0,
                'agency_name'     => $validatedData['agency_name']    ?? null,
                'agency_license'  => $validatedData['agency_license'] ?? null,
                'agency_city'     => $validatedData['agency_city']    ?? null,
                'agency_address'  => $validatedData['agency_address'] ?? null,
                'agency_logo'     => $logoPath,
                'is_active'       => false,
            ]);

            $roleName = $validatedData['role'] ?? 'user';
            $role = Role::where('name', $roleName)->first();

            if ($role) {
                $user->assignRole($role);
            } else {
                Log::warning("Role '{$roleName}' not found during registration for user {$user->id}.");
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'      => 'User registered successfully.',
                'user'         => $user,
                'access_token' => $token,
                'token_type'   => 'Bearer',
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('User registration failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to register user. Please try again later.',
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'email'    => 'required|string|email',
                'password' => 'required|string',
            ]);

            // Look up the user explicitly — avoids relying on Auth::attempt()'s
            // session guard resolving correctly in a stateless API context.
            $user = User::where('email', $validatedData['email'])->first();

            if (!$user || !Hash::check($validatedData['password'], $user->password)) {
                return response()->json([
                    'message' => 'Invalid credentials.',
                ], 401);
            }

            if (!$user->is_active) {
                return response()->json([
                    'message' => 'Your account is pending approval. Please wait for an administrator to activate it.',
                ], 403);
            }

            // Single-session policy: revoke all previous tokens before issuing a new one
            $user->tokens()->delete();

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'      => 'Logged in successfully.',
                'user'         => $user,
                'role'         => $user->getRoleNames()->first() ?? 'No role assigned',
                'access_token' => $token,
                'token_type'   => 'Bearer',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('User login failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to log in. Please try again later.',
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
