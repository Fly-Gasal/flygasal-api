<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiUser;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Manages user-owned API keys and webhook endpoints.
 *
 * API Keys: Sanctum personal access tokens tagged with the 'api-key:' prefix.
 * Webhook Endpoints: URLs that receive signed POST events when booking/transaction events occur.
 */
class DeveloperController extends Controller
{
    private const TOKEN_TYPE = 'api-key';

    private const VALID_SCOPES = [
        'flights:search',
        'flights:pricing',
        'bookings:read',
        'bookings:write',
        'transactions:read',
        'profile:read',
    ];

    private const VALID_EVENTS = [
        'booking.created',
        'booking.confirmed',
        'booking.ticketed',
        'booking.cancelled',
        'transaction.credit',
        'transaction.debit',
    ];

    // ─── API Key Management ───────────────────────────────────────────────────

    /**
     * List the authenticated user's API keys (excluding the current session token).
     */
    public function index(Request $request): JsonResponse
    {
        $keys = $request->user()
            ->tokens()
            ->where('name', 'LIKE', self::TOKEN_TYPE . ':%')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => $this->formatToken($t));

        return response()->json(['data' => $keys]);
    }

    /**
     * Create a new API key.
     * The plain-text token is returned ONCE and never stored.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:80',
            'scopes'   => 'required|array|min:1',
            'scopes.*' => 'string|in:' . implode(',', self::VALID_SCOPES),
        ]);

        $tokenName = self::TOKEN_TYPE . ':' . trim($validated['name']);

        $token = $request->user()->createToken(
            $tokenName,
            $validated['scopes']
        );

        return response()->json([
            'message'     => 'API key created successfully.',
            'plain_token' => 'fgk_' . $token->plainTextToken,
            'key'         => $this->formatToken($token->accessToken),
        ], 201);
    }

    /**
     * Revoke a specific API key (verifies ownership).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $request->user()
            ->tokens()
            ->where('id', $id)
            ->where('name', 'LIKE', self::TOKEN_TYPE . ':%')
            ->first();

        if (!$token) {
            return response()->json(['message' => 'API key not found.'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'API key revoked.']);
    }

    /**
     * Revoke all API keys for the authenticated user.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()
            ->tokens()
            ->where('name', 'LIKE', self::TOKEN_TYPE . ':%')
            ->delete();

        return response()->json(['message' => 'All API keys revoked.']);
    }

    // ─── Webhook Endpoint Management ──────────────────────────────────────────

    /**
     * List webhook endpoints for the authenticated user.
     */
    public function listWebhooks(Request $request): JsonResponse
    {
        $endpoints = $request->user()
            ->webhookEndpoints()
            ->latest()
            ->get()
            ->map(fn ($e) => $this->formatEndpoint($e));

        return response()->json(['data' => $endpoints]);
    }

    /**
     * Create a new webhook endpoint.
     * A unique HMAC signing secret is generated and returned (stored in full).
     */
    public function storeWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url'      => 'required|url|max:2048',
            'events'   => 'required|array|min:1',
            'events.*' => 'string|in:' . implode(',', self::VALID_EVENTS),
        ]);

        $endpoint = $request->user()->webhookEndpoints()->create([
            'url'       => $validated['url'],
            'events'    => $validated['events'],
            'secret'    => 'whsec_' . bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        return response()->json(['data' => $this->formatEndpoint($endpoint)], 201);
    }

    /**
     * Update a webhook endpoint (toggle active or change events).
     */
    public function updateWebhook(Request $request, int $id): JsonResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->find($id);

        if (!$endpoint) {
            return response()->json(['message' => 'Webhook endpoint not found.'], 404);
        }

        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'events'    => 'sometimes|array|min:1',
            'events.*'  => 'string|in:' . implode(',', self::VALID_EVENTS),
        ]);

        $endpoint->update($validated);

        return response()->json(['data' => $this->formatEndpoint($endpoint)]);
    }

    /**
     * Delete a webhook endpoint.
     */
    public function destroyWebhook(Request $request, int $id): JsonResponse
    {
        $endpoint = $request->user()->webhookEndpoints()->find($id);

        if (!$endpoint) {
            return response()->json(['message' => 'Webhook endpoint not found.'], 404);
        }

        $endpoint->delete();

        return response()->json(['message' => 'Webhook endpoint deleted.']);
    }

    // ─── Agency API Access ────────────────────────────────────────────────────

    /**
     * Return the authenticated agency's linked api_users record (or null).
     */
    public function myAccess(Request $request): JsonResponse
    {
        $apiUser = ApiUser::where('user_id', $request->user()->id)->first();

        if (!$apiUser) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id'             => $apiUser->id,
            'status'         => $apiUser->status,
            'plan'           => $apiUser->plan,
            'api_key'        => $apiUser->status === 'active' ? $apiUser->api_key : null,
            'requests_today' => $apiUser->requests_today,
            'requests_limit' => $apiUser->requests_limit,
            'is_active'      => $apiUser->is_active,
            'use_case'       => $apiUser->use_case,
            'last_used_at'   => $apiUser->last_used_at?->toIso8601String(),
            'created_at'     => $apiUser->created_at->toIso8601String(),
        ]]);
    }

    /**
     * Submit an API access request on behalf of the authenticated agency.
     * Creates a pending api_users record linked to their account.
     */
    public function requestAccess(Request $request): JsonResponse
    {
        $user = $request->user();

        if (ApiUser::where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'You already have an API access request on file.',
            ], 422);
        }

        $validated = $request->validate([
            'use_case' => 'required|string|min:20|max:1000',
        ]);

        $apiUser = ApiUser::create([
            'user_id'        => $user->id,
            'name'           => $user->agency_name ?? $user->name,
            'email'          => $user->email,
            'plan'           => 'free',
            'api_key'        => null,
            'status'         => 'pending',
            'use_case'       => $validated['use_case'],
            'requests_limit' => ApiUser::PLAN_LIMITS['free'],
        ]);

        return response()->json(['data' => [
            'id'         => $apiUser->id,
            'status'     => $apiUser->status,
            'plan'       => $apiUser->plan,
            'use_case'   => $apiUser->use_case,
            'created_at' => $apiUser->created_at->toIso8601String(),
        ]], 201);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function formatToken(PersonalAccessToken $token): array
    {
        $label = preg_replace('/^' . preg_quote(self::TOKEN_TYPE . ':', '/') . '/', '', $token->name);

        return [
            'id'         => $token->id,
            'name'       => $label,
            'prefix'     => 'fgk_',
            'scopes'     => $token->abilities ?? [],
            'last_used'  => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at->toIso8601String(),
            'status'     => 'active',
        ];
    }

    private function formatEndpoint(WebhookEndpoint $endpoint): array
    {
        return [
            'id'                 => $endpoint->id,
            'url'                => $endpoint->url,
            'events'             => $endpoint->events ?? [],
            'secret'             => $endpoint->secret,
            'is_active'          => $endpoint->is_active,
            'last_triggered_at'  => $endpoint->last_triggered_at?->toIso8601String(),
            'created_at'         => $endpoint->created_at->toIso8601String(),
        ];
    }
}
