<?php

namespace App\Http\Controllers\Api\v2;

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
 * v2 FlightController — accepts a structured `flights` array (multi-city native),
 * no redundant flat origin/destination/departureDate params. Precise pricing
 * reconstructs journeys from the cached shopping response via shoppingKey.
 */
class FlightController extends Controller
{
    protected PKfareService $pkfareService;

    public function __construct(PKfareService $pkfareService)
    {
        $this->pkfareService = $pkfareService;
        $this->middleware('throttle:30,1')->only(['search', 'precisePricing', 'ancillaryPricing']);
    }

    /**
     * Search for flights.
     * Accepts a `flights` array as the primary structure — supports one-way,
     * round-trip, and multi-city without requiring flat origin/destination fields.
     * Round-trip return legs should be included as flights[1] by the client.
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'tripType'              => 'nullable|string',
                'flights'               => 'required|array|min:1',
                'flights.*.origin'      => 'required|string|size:3',
                'flights.*.destination' => 'required|string|size:3',
                'flights.*.depart'      => 'required|date_format:Y-m-d|after_or_equal:today',
                'flights.*.cabinClass'  => 'nullable|string',
                'adults'                => 'required|integer|min:1',
                'children'              => 'nullable|integer|min:0',
                'infants'               => 'nullable|integer|min:0',
                'cabinType'             => 'nullable|string',
                // returnDate is accepted for backward compatibility (old JSX frontend sends it)
                'returnDate'            => 'nullable|date_format:Y-m-d',
            ]);

            // Normalize cabin: TSX frontend sends IATA codes (Y/C/F/W), old JSX sends full words.
            // PKFare search requires the full-word form; map single-letter codes here as a safety net.
            $cabinCodeMap = ['Y' => 'Economy', 'C' => 'Business', 'F' => 'First', 'W' => 'PremiumEconomy'];
            $rawCabin      = $validated['cabinType'] ?? '';
            $topLevelCabin = $cabinCodeMap[$rawCabin] ?? $rawCabin;

            // Normalize flights (uppercase IATA codes; no per-leg cabinClass so
            // PKfareService falls back to $criteria['cabinClass'] for each outbound leg).
            $flights = array_map(fn(array $f): array => [
                'origin'      => strtoupper($f['origin']),
                'destination' => strtoupper($f['destination']),
                'depart'      => $f['depart'],
            ], $validated['flights']);

            // Normalize to lowercase so "RoundTrip", "return", "Oneway", "MultiCity", etc.
            // are all handled consistently regardless of which frontend sent the request.
            $tripType    = strtolower($validated['tripType'] ?? 'oneway');
            $isRoundTrip = \in_array($tripType, ['roundtrip', 'return'], true);

            if ($isRoundTrip) {
                // PKfareService auto-appends the return leg with cabinClass '' (intentional
                // in v1 — PKFare rejects 'Y' on the return leg with P001). So we always
                // send only the outbound leg in flights[] and pass returnDate separately.
                //
                // returnDate source priority:
                //   1. flights[1].depart  (new TSX frontend sends both legs, no returnDate body)
                //   2. validated returnDate (old JSX frontend sends 1 leg + returnDate body)
                $outbound   = $flights[0];
                $returnDate = (\count($flights) >= 2)
                    ? $flights[1]['depart']
                    : ($validated['returnDate'] ?? null);

                $criteria = [
                    'tripType'      => 'roundtrip',
                    'flights'       => [$outbound],
                    'origin'        => $outbound['origin'],
                    'destination'   => $outbound['destination'],
                    'departureDate' => $outbound['depart'],
                    'returnDate'    => $returnDate,
                    'adults'        => (int)$validated['adults'],
                    'children'      => (int)($validated['children'] ?? 0),
                    'infants'       => (int)($validated['infants'] ?? 0),
                    'cabinClass'    => $topLevelCabin,
                ];
            } else {
                // Oneway and MultiCity: all legs forwarded directly
                $criteria = [
                    'tripType'   => $tripType,  // 'oneway' or 'multicity'
                    'flights'    => $flights,
                    'adults'     => (int)$validated['adults'],
                    'children'   => (int)($validated['children'] ?? 0),
                    'infants'    => (int)($validated['infants'] ?? 0),
                    'cabinClass' => $topLevelCabin,
                ];
            }

            $cacheKey = 'v2_flight_search_' . md5(json_encode($criteria));

            Log::info('v2 flight search', ['tripType' => $criteria['tripType'], 'legs' => \count($flights), 'key' => $cacheKey]);

            $resp = Cache::get($cacheKey);

            if (!$resp) {
                Log::info('v2 flight search cache miss, calling PKfareService', ['criteria' => $criteria]);
                $resp = $this->pkfareService->searchFlights($criteria);

                $errorResponse = $this->handlePkfareError($resp, 'search');
                if ($errorResponse) return $errorResponse;

                Cache::put($cacheKey, $resp, 600);
            }

            $data        = $resp['data'] ?? $resp;
            $shoppingKey = $data['shoppingKey'] ?? null;

            // Cache raw PKFare solutions keyed by shoppingKey so precisePricing
            // can reconstruct journeys without requiring the client to send them.
            if ($shoppingKey) {
                Cache::put("v2_shop_raw_{$shoppingKey}", $data['solutions'] ?? [], 600);
            }

            $offers = MapOffer::normalize($data);

            return response()->json([
                'message'     => 'Flights retrieved successfully.',
                'shoppingKey' => $shoppingKey,
                'searchKey'   => $data['searchKey'] ?? null,
                'offers'      => $offers,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            Log::error('v2 flight search failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Failed to search flights.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Precise pricing for a selected offer.
     * Accepts offerId, shoppingKey, solutionKey, coherenceKey — no journeys array.
     * Journeys and pax counts are reconstructed from the cached shopping response.
     */
    public function precisePricing(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'offerId'      => 'nullable|string',
                'shoppingKey'  => 'required|string',
                'solutionKey'  => 'required|string',
                'coherenceKey' => 'nullable|string',
                'solutionId'   => 'nullable|string',
            ]);

            $shoppingKey = $validated['shoppingKey'];
            $solutionKey = $validated['solutionKey'];

            // Retrieve raw PKFare solutions from the search cache
            $cachedSolutions = Cache::get("v2_shop_raw_{$shoppingKey}", []);

            if (empty($cachedSolutions)) {
                return response()->json([
                    'success' => false,
                    'code'    => 'CACHE_EXPIRED',
                    'message' => 'Your search session has expired. Please search again.',
                ], 400);
            }

            // Find the specific solution by its key
            $solution = null;
            foreach ($cachedSolutions as $sol) {
                if (($sol['solutionKey'] ?? null) === $solutionKey) {
                    $solution = $sol;
                    break;
                }
            }

            if (!$solution) {
                return response()->json([
                    'success' => false,
                    'code'    => 'SOLUTION_NOT_FOUND',
                    'message' => 'The selected flight is no longer available. Please search again.',
                ], 400);
            }

            $criteria = [
                'solutionKey' => $solutionKey,
                'journeys'    => $solution['journeys'] ?? [],
                'adults'      => (int)($solution['adults'] ?? 1),
                'children'    => (int)($solution['children'] ?? 0),
                'infants'     => (int)($solution['infants'] ?? 0),
            ];

            $cacheKey = 'v2_precise_pricing_' . md5(json_encode($criteria));

            $pricingResp = Cache::get($cacheKey);

            if (!$pricingResp) {
                $pricingResp = $this->pkfareService->getPrecisePricing($criteria);

                $errorResponse = $this->handlePkfareError($pricingResp, 'pricing');
                if ($errorResponse) return $errorResponse;

                Cache::put($cacheKey, $pricingResp, 300);
            }

            $data = $pricingResp['data'] ?? $pricingResp;

            return response()->json([
                'message' => 'Precise pricing retrieved successfully.',
                'offer'   => MapPrecisePricing::normalize($data),
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            Log::error('v2 precise pricing failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to get precise pricing.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Ancillary pricing (baggage, seats).
     * Same pattern as precisePricing — uses shoppingKey + solutionKey to reconstruct journeys.
     */
    public function ancillaryPricing(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'shoppingKey' => 'required|string',
                'solutionKey' => 'required|string',
                'solutionId'  => 'nullable|string',
                'adults'      => 'nullable|integer|min:1',
                'children'    => 'nullable|integer|min:0',
                'infants'     => 'nullable|integer|min:0',
            ]);

            $shoppingKey = $validated['shoppingKey'];
            $solutionKey = $validated['solutionKey'];

            $cachedSolutions = Cache::get("v2_shop_raw_{$shoppingKey}", []);

            if (empty($cachedSolutions)) {
                return response()->json([
                    'success' => false,
                    'code'    => 'CACHE_EXPIRED',
                    'message' => 'Your search session has expired. Please search again.',
                ], 400);
            }

            $solution = null;
            foreach ($cachedSolutions as $sol) {
                if (($sol['solutionKey'] ?? null) === $solutionKey) {
                    $solution = $sol;
                    break;
                }
            }

            if (!$solution) {
                return response()->json([
                    'success' => false,
                    'code'    => 'SOLUTION_NOT_FOUND',
                    'message' => 'The selected flight is no longer available. Please search again.',
                ], 400);
            }

            $criteria = [
                'solutionId'  => $validated['solutionId'] ?? null,
                'solutionKey' => $solutionKey,
                'journeys'    => $solution['journeys'] ?? [],
                'adults'      => (int)($validated['adults'] ?? $solution['adults'] ?? 1),
                'children'    => (int)($validated['children'] ?? $solution['children'] ?? 0),
                'infants'     => (int)($validated['infants'] ?? $solution['infants'] ?? 0),
            ];

            $cacheKey = 'v2_ancillary_' . md5(json_encode($criteria));

            $ancillaryResp = Cache::get($cacheKey);

            if (!$ancillaryResp) {
                $ancillaryResp = $this->pkfareService->ancillaryPricing($criteria);

                $errorResponse = $this->handlePkfareError($ancillaryResp, 'ancillary');
                if ($errorResponse) return $errorResponse;

                Cache::put($cacheKey, $ancillaryResp, 3600);
            }

            return response()->json([
                'message' => 'Ancillary pricing retrieved successfully.',
                'data'    => $ancillaryResp['data'] ?? $ancillaryResp,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            Log::error('v2 ancillary pricing failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to get ancillary pricing.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Centralized PKFare error handler — identical mapping to v1.
     */
    private function handlePkfareError(array $response, string $context): ?JsonResponse
    {
        $errorCode = $response['errorCode'] ?? null;

        if ($errorCode === '0' || $errorCode === null) {
            return null;
        }

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
                'B005' => 'Pricing expired. Please perform the search again.',
                'B017' => 'The price of this flight has changed.',
            ],
            'ancillary' => [],
        ];

        $mappedMessage = $errorMaps[$context][$errorCode] ?? null;
        $apiMessage    = $response['errorMsg'] ?? null;

        $message = ($apiMessage && $mappedMessage === null)
            ? $apiMessage
            : ($mappedMessage ?? $apiMessage ?? 'An unknown provider error occurred.');

        Log::warning("v2 PKFare {$context} error: [{$errorCode}] {$message}");

        return response()->json([
            'success' => false,
            'code'    => $errorCode,
            'message' => $message,
        ], 400);
    }
}
