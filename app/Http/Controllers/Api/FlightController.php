<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PKfareService;
use App\Support\MapOffer;
use App\Support\MapPrecisePricing;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The FlightController handles flight discovery and pricing.
 * Optimized with smart caching (only caching successes) to reduce
 * third-party API costs while handling provider errors gracefully.
 */
class FlightController extends Controller
{
    protected PKfareService $pkfareService;

    public function __construct(PKfareService $pkfareService)
    {
        $this->pkfareService = $pkfareService;

        // Rate limiting: Protect search endpoints from scraping bots or abuse.
        // Allows 30 requests per minute per IP address.
        $this->middleware('throttle:30,1')->only(['search', 'precisePricing', 'ancillaryPricing']);
    }

    /**
     * Search for flights based on user criteria.
     * Caches identical successful searches for 10 minutes.
     */
    public function search(Request $request): JsonResponse
    {
        try {
            // 1. Validate incoming request data
            $validatedData = $request->validate([
                'tripType'      => 'nullable|string',
                'flights'       => 'nullable|array',
                'origin'        => 'required|string|size:3',
                'destination'   => 'required|string|size:3',
                'departureDate' => 'required|date_format:Y-m-d|after_or_equal:today',
                'returnDate'    => 'nullable|date_format:Y-m-d|after:departureDate|required_if:tripType,RoundTrip',
                'adults'        => 'required|integer|min:1',
                'children'      => 'nullable|integer|min:0',
                'infants'       => 'nullable|integer|min:0',
                'cabinType'     => 'nullable|string'
            ]);

            $criteria = [
                'flights'       => $validatedData['flights'] ?? [],
                'tripType'      => $validatedData['tripType'] ?? 'Oneway',
                'origin'        => strtoupper($validatedData['origin']),
                'destination'   => strtoupper($validatedData['destination']),
                'departureDate' => $validatedData['departureDate'],
                'returnDate'    => $validatedData['returnDate'] ?? null,
                'adults'        => $validatedData['adults'],
                'children'      => $validatedData['children'] ?? 0,
                'infants'       => $validatedData['infants'] ?? 0,
                'cabinClass'    => $validatedData['cabinType'] ?? '',
            ];

            // Generate a unique cache key based on the exact search criteria
            $cacheKey = 'flight_search_' . md5(json_encode($criteria));

            // 2. Check cache first
            $resp = Cache::get($cacheKey);

            // 3. If no cache exists, fetch fresh data from API
            if (!$resp) {
                $resp = $this->pkfareService->searchFlights($criteria);

                // Catch API Errors immediately (DO NOT cache failed requests like Timeouts or Rate Limits)
                $errorResponse = $this->handlePkfareError($resp, 'search');
                if ($errorResponse) {
                    return $errorResponse;
                }

                // If successful, cache the response for 10 minutes (600 seconds)
                Cache::put($cacheKey, $resp, 600);
            }

            $data = $resp['data'] ?? $resp;

            // Normalize the provider data into our application's standard format
            $offers = MapOffer::normalize($data);

            return response()->json([
                'message'     => 'Flights retrieved successfully.',
                'shoppingKey' => $data['shoppingKey'] ?? null,
                'searchKey'   => $data['searchKey'] ?? null,
                'offers'      => $offers,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            Log::error('Flight search failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Failed to search flights.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Perform precise pricing for a selected solution.
     * Validates if the selected seat is still available and the price hasn't changed.
     * Caches the result for 5 minutes.
     */
    public function precisePricing(Request $request): JsonResponse
    {
        try {
            // 1. Validate request data
            $validatedData = $request->validate([
                'solutionId'  => 'nullable|string',
                'solutionKey' => 'nullable|string',
                'journeys'    => 'nullable|array',
                'adults'      => 'required|integer|min:1',
                'children'    => 'nullable|integer|min:0',
                'infants'     => 'nullable|integer|min:0',
                'cabinType'   => 'nullable|string',
                'tag'         => 'nullable|string',
            ]);

            $criteria = [
                'solutionId'  => $validatedData['solutionId'] ?? "direct pricing",
                'solutionKey' => $validatedData['solutionKey'] ?? null,
                'journeys'    => $validatedData['journeys'] ?? [],
                'adults'      => $validatedData['adults'],
                'children'    => $validatedData['children'] ?? 0,
                'infants'     => $validatedData['infants'] ?? 0,
                'cabin'       => $validatedData['cabinType'] ?? 'ECONOMY',
                'tag'         => ""
            ];

            $cacheKey = 'precise_pricing_' . md5(json_encode($criteria));

            // 2. Check cache
            $precisePricing = Cache::get($cacheKey);

            // 3. Fetch from API if not cached
            if (!$precisePricing) {
                $precisePricing = $this->pkfareService->getPrecisePricing($criteria);

                // Handle precise pricing specific errors (e.g., 0 seats left, price changed)
                $errorResponse = $this->handlePkfareError($precisePricing, 'pricing');
                if ($errorResponse) return $errorResponse;

                // Cache precise pricing for a shorter window (5 minutes) because seat availability changes rapidly
                Cache::put($cacheKey, $precisePricing, 300);
            }

            $data = $precisePricing['data'] ?? $precisePricing;

            return response()->json([
                'message' => 'Precise pricing retrieved successfully.',
                'offer'   => MapPrecisePricing::normalize($data),
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            Log::error('Precise pricing failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to get precise pricing.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retrieves ancillary products (e.g., baggage, seats).
     * Highly cacheable since baggage rules rarely change minute-by-minute.
     */
    public function ancillaryPricing(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'solutionId'  => 'required|string',
                'solutionKey' => 'nullable|string',
                'journeys'    => 'nullable|array',
                'adults'      => 'required|integer|min:1',
                'children'    => 'nullable|integer|min:0',
                'infants'     => 'nullable|integer|min:0',
                'cabinType'   => 'nullable|string',
                'tag'         => 'nullable|string',
            ]);

            $criteria = [
                'solutionId'  => $validatedData['solutionId'],
                'solutionKey' => $validatedData['solutionKey'] ?? null,
                'journeys'    => $validatedData['journeys'] ?? [],
                'adults'      => $validatedData['adults'],
                'children'    => $validatedData['children'] ?? 0,
                'infants'     => $validatedData['infants'] ?? 0,
                'cabin'       => $validatedData['cabinType'] ?? 'Economy',
                'tag'         => $validatedData['tag'] ?? 'direct pricing'
            ];

            $cacheKey = 'ancillary_pricing_' . md5(json_encode($criteria));

            $ancillaryPricing = Cache::get($cacheKey);

            if (!$ancillaryPricing) {
                $ancillaryPricing = $this->pkfareService->ancillaryPricing($criteria);

                $errorResponse = $this->handlePkfareError($ancillaryPricing, 'ancillary');
                if ($errorResponse) return $errorResponse;

                // Baggage rules rarely fluctuate minute-to-minute; cache for 1 hour (3600 seconds)
                Cache::put($cacheKey, $ancillaryPricing, 3600);
            }

            return response()->json([
                'message' => 'Ancillary pricing retrieved successfully.',
                'data'    => $ancillaryPricing['data'] ?? $ancillaryPricing,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            Log::error('Ancillary pricing failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to get ancillary pricing.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Centralized Error Handling for PKFare API Responses.
     * Evaluates error codes and returns standardized JSON responses.
     *
     * @param array $response The raw response array from PKFare
     * @param string $context The context of the call ('search', 'pricing', 'ancillary')
     * @return JsonResponse|null Returns a JSON response if an error occurred, null if successful.
     */
    private function handlePkfareError(array $response, string $context): ?JsonResponse
    {
        $errorCode = $response['errorCode'] ?? null;

        // Return null if request was successful (0 or null)
        if ($errorCode === '0' || $errorCode === null) {
            return null;
        }

        // Map the specific PKFare error codes to friendly messages based on context
        $errorMaps = [
            'search' => [
                'S001' => 'System error.',
                'B002' => 'Partner ID does not exist. Please check API configuration.',
                'B003' => 'Illegal signature. Please check API credentials.',
                'P001' => 'A provided parameter is illegal or invalid.',
                'P002' => 'A required parameter is missing.',
                'P004' => 'The maximum number of passengers with a seat is 9.',
                'P005' => 'There must be at least one adult passenger.',
                'P009' => 'The number of infants cannot exceed the number of adult passengers.',
                'B013' => 'The requested route is currently restricted. Please contact support.',
                'B014' => 'Flight search failed due to an upstream provider error.',
                'B024' => 'The search timed out. Please try your search again.',
                'B034' => 'Flight shopping service is currently offline.',
                'B035' => 'Too many requests. Please try again in a few moments.',
                'B038' => 'Departure date must be within one year from today.',
                'B039' => 'Could not find data for the requested city, country, or airline.',
                'B046' => 'This specific routing has been taken offline by the provider.',
                'B059' => 'Shopping failed due to an error from the upstream airline.',
                'B060' => 'Interface is limited due to restriction rules.',
                'B009' => 'The supplier for this route is currently offline.',
                'B066' => 'A maximum of 2 journeys are supported for this search.',
                'B195' => 'The requested search tag is invalid.',
            ],
            'pricing' => [
                'S001' => 'System error.',
                'B002' => 'Partner ID does not exist. Please check API configuration.',
                'B003' => 'Illegal signature. Please check API credentials.',
                'B035' => 'Concurrency exceeded system limits. Please try again.',
                'P001' => 'A provided parameter is illegal.',
                'P002' => 'A required parameter is missing.',
                'P004' => 'The maximum number of passengers with a seat is 9.',
                'P005' => 'There must be at least one adult passenger.',
                'P009' => 'The number of infants cannot exceed the number of adult passengers.',
                'B013' => 'The requested route is currently limited. Please contact support.',
                'B015' => 'Pricing failed due to an unknown provider reason.',
                'B016' => 'Cannot price flights that are near take-off.',
                'B018' => 'Airline or flight restriction applies to this selection.',
                'B019' => 'Cannot find any supplier for this specific flight search.',
                'B020' => 'Cannot find any price for this specific flight option.',
                'B021' => 'The selected booking code has 0 seats left. Please choose another flight.',
                'B024' => 'Pricing request timed out. Please try again.',
                'B026' => 'The last ticketing time is insufficient to issue a ticket.',
                'B039' => 'Could not find related data for the requested city, country, or airline.',
                'B046' => 'This routing has been taken offline by the provider.',
                'B068' => 'Pricing request details do not match the original shopping response.',
                'B121' => 'The supplier is currently offline. Please try again later.',
                // Legacy fallbacks just in case
                'B005' => 'Pricing expired. Please perform the search again.',
                'B017' => 'The price of this flight has changed.',
            ],
            'ancillary' => [] // Add specific ancillary codes here if needed in the future
        ];

        // 1. Try to get context-specific mapped error
        $mappedMessage = $errorMaps[$context][$errorCode] ?? null;

        // 2. Fetch the raw API message (useful for dynamic errors like "The field of departureDate is illegal")
        $apiMessage = $response['errorMsg'] ?? null;

        // 3. Logic to determine the best message:
        // If the API provided a dynamic message and we don't have a hardcoded map, use the API message.
        // Otherwise, prioritize our mapped user-friendly message, falling back to a generic error.
        $message = ($apiMessage && $mappedMessage === null)
            ? $apiMessage
            : ($mappedMessage ?? $apiMessage ?? 'An unknown provider error occurred.');

        // Log the error for internal backend monitoring
        Log::warning("PKFare {$context} failed: [{$errorCode}] {$message}");

        // Return a standard 400 Bad Request to the frontend
        return response()->json([
            'success' => false,
            'code'    => $errorCode,
            'message' => $message,
        ], 400);
    }
}
