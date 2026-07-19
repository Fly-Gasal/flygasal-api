<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = ApiUser::with('user:id,name,email,agency_name')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(15);

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:api_users,email',
            'plan'     => 'required|in:free,basic,pro,enterprise',
            'user_id'  => 'nullable|exists:users,id',
            'use_case' => 'nullable|string|max:1000',
        ]);

        $apiUser = ApiUser::create([
            'user_id'        => $validated['user_id'] ?? null,
            'name'           => $validated['name'],
            'email'          => $validated['email'],
            'plan'           => $validated['plan'],
            'api_key'        => ApiUser::generateKey(),
            'status'         => 'active',
            'use_case'       => $validated['use_case'] ?? null,
            'requests_limit' => ApiUser::PLAN_LIMITS[$validated['plan']],
        ]);

        return response()->json(['api_user' => $apiUser->load('user:id,name,email,agency_name')], 201);
    }

    public function update(Request $request, ApiUser $apiUser): JsonResponse
    {
        $validated = $request->validate([
            'name'  => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:api_users,email,' . $apiUser->id,
            'plan'  => 'sometimes|in:free,basic,pro,enterprise',
        ]);

        if (isset($validated['plan']) && $validated['plan'] !== $apiUser->plan) {
            $validated['requests_limit'] = ApiUser::PLAN_LIMITS[$validated['plan']];
        }

        $apiUser->update($validated);

        return response()->json(['api_user' => $apiUser->load('user:id,name,email,agency_name')]);
    }

    public function approve(ApiUser $apiUser): JsonResponse
    {
        if ($apiUser->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be approved.'], 422);
        }

        $apiUser->update([
            'api_key' => ApiUser::generateKey(),
            'status'  => 'active',
        ]);

        return response()->json(['api_user' => $apiUser->fresh()->load('user:id,name,email,agency_name')]);
    }

    public function regenerateKey(ApiUser $apiUser): JsonResponse
    {
        $apiUser->update(['api_key' => ApiUser::generateKey()]);
        return response()->json(['api_key' => $apiUser->api_key]);
    }

    public function toggleActive(ApiUser $apiUser): JsonResponse
    {
        $newStatus = $apiUser->status === 'suspended' ? 'active' : 'suspended';
        $apiUser->update([
            'is_active' => !$apiUser->is_active,
            'status'    => $newStatus,
        ]);
        return response()->json(['api_user' => $apiUser]);
    }

    public function destroy(ApiUser $apiUser): JsonResponse
    {
        $apiUser->delete();
        return response()->json(['message' => 'API user deleted.']);
    }
}
