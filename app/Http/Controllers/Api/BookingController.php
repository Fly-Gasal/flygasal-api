<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flights\Booking;
use App\Models\Flights\BookingPassenger;
use App\Models\Flights\BookingSegment;
use App\Models\Flights\Transaction;
use App\Services\PKfareService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Handles the lifecycle of flight bookings.
 * Interacts with PKfareService and manages local database synchronization.
 */
class BookingController extends Controller
{
    protected PKfareService $pkfareService;

    public function __construct(PKfareService $pkfareService)
    {
        $this->pkfareService = $pkfareService;

        // Protect mutating endpoints from double-submission or spam
        $this->middleware('throttle:10,1')->only(['store', 'cancel', 'ticketOrder']);
    }

    /**
     * Display a listing of the user's bookings.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Booking::with('transactions')->latest();

        // Scope to the current user unless they are an admin/agent
        if (!$request->user()->hasRole('agent') && !$request->user()->hasRole('admin')) {
            $query->where('user_id', $request->user()->id);
        }

        // Reduced pagination chunk to avoid huge memory spikes, standard is 15-50.
        $bookings = $query->paginate(50);

        return response()->json([
            'status'  => true,
            'message' => 'Bookings retrieved successfully.',
            'data'    => $bookings,
        ]);
    }

    /**
     * Store a newly created booking in storage and call the provider API.
     */
    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $validatedData = $request->validate([
                'selectedFlight' => 'required|array',
                'solutionId'     => 'required|string',
                'passengers'     => 'required|array|min:1',
                'passengers.*.firstName'      => 'required|string|max:255',
                'passengers.*.lastName'       => 'required|string|max:255',
                'passengers.*.type'           => 'required|string|in:ADT,CHD,INF',
                'passengers.*.dob'            => 'required|date_format:Y-m-d',
                'passengers.*.gender'         => 'required|string|in:Male,Female',
                'passengers.*.passportNumber' => 'nullable|string|max:255',
                'passengers.*.passportExpiry' => 'nullable|date_format:Y-m-d|after_or_equal:today',
                'passengers.*.nationality'    => 'nullable|string|size:2',
                'contactName'  => 'required|string|max:155',
                'contactEmail' => 'required|email|max:255',
                'contactPhone' => 'required|string|max:20',
                'totalPrice'   => 'required|numeric|min:0',
                'currency'     => 'required|string|size:3',
                'agent_fee'    => 'nullable|numeric|min:0',
            ]);

            $user = $request->user();
            if (!$user) {
                DB::rollBack();
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $pkfareBookingDetails = [
                'selectedFlight' => $validatedData['selectedFlight'],
                'solutionId'     => $validatedData['solutionId'],
                'passengers'     => $validatedData['passengers'],
                'contactInfo'    => [
                    'name'  => $validatedData['contactName'],
                    'email' => $validatedData['contactEmail'],
                    'phone' => $validatedData['contactPhone'],
                ],
            ];

            $pkfareResponse = (array) $this->pkfareService->createBooking($pkfareBookingDetails);

            // Centralized error check
            $errorCheck = $this->handlePkfareError($pkfareResponse, 'booking');
            if ($errorCheck) {
                DB::rollBack();
                return $errorCheck;
            }

            $data     = $pkfareResponse['data'] ?? [];
            $solution = $data['solution'] ?? [];

            $adtFare = (float)($solution['adtFare'] ?? 0);
            $adtTax  = (float)($solution['adtTax']  ?? 0);
            $chdFare = (float)($solution['chdFare'] ?? 0);
            $chdTax  = (float)($solution['chdTax']  ?? 0);
            $totalAmount = $adtFare + $adtTax + $chdFare + $chdTax;

            $booking = Booking::create([
                'user_id'         => $user->id,
                'order_num'       => $data['orderNum'] ?? null,
                'pnr'             => $data['pnr'] ?? null,
                'solution_id'     => $solution['solutionId'] ?? $validatedData['solutionId'],
                'fare_type'       => $solution['fareType'] ?? null,
                'currency'        => $solution['currency'] ?? $validatedData['currency'],
                'adt_fare'        => $adtFare,
                'adt_tax'         => $adtTax,
                'chd_fare'        => $chdFare,
                'chd_tax'         => $chdTax,
                'infants'         => (int)($solution['infants'] ?? 0),
                'adults'          => (int)($solution['adults'] ?? 0),
                'children'        => (int)($solution['children'] ?? 0),
                'plating_carrier' => $solution['platingCarrier'] ?? null,
                'baggage_info'    => $solution['baggageMap'] ?? null,
                'flights'         => $data['flights'] ?? null,
                'segments'        => $data['segments'] ?? null,
                'passengers'      => $validatedData['passengers'],
                'agent_fee'       => (float)($validatedData['agent_fee'] ?? 0),
                'total_amount'    => $totalAmount,
                'contact_name'    => $validatedData['contactName'],
                'contact_email'   => $validatedData['contactEmail'],
                'contact_phone'   => $validatedData['contactPhone'],
                'status'          => 'pending',
                'payment_status'  => 'unpaid',
                'issue_status'    => 'TO_BE_PAID',
                'booking_date'    => now(),
            ]);

            // Save passengers
            foreach ($validatedData['passengers'] as $i => $p) {
                BookingPassenger::create([
                    'booking_id'      => $booking->id,
                    'passenger_index' => $i + 1,
                    'psg_type'        => $p['type'],
                    'sex'             => $p['gender'] === 'Male' ? 'M' : 'F',
                    'birthday'        => $p['dob'],
                    'first_name'      => strtoupper($p['firstName']),
                    'last_name'       => strtoupper($p['lastName']),
                    'nationality'     => strtoupper($p['nationality'] ?? ''),
                    'card_type'       => !empty($p['passportNumber']) ? 'P' : null,
                    'card_num'        => $p['passportNumber'] ?? null,
                    'card_expired_date' => $p['passportExpiry'] ?? null,
                ]);
            }

            // Save segments
            if (!empty($data['segments'])) {
                foreach ($data['segments'] as $idx => $seg) {
                    $departureDateTime = !empty($seg['strDepartureDate']) && !empty($seg['strDepartureTime'])
                        ? Carbon::parse($seg['strDepartureDate'] . ' ' . $seg['strDepartureTime']) : null;

                    $arrivalDateTime = !empty($seg['strArrivalDate']) && !empty($seg['strArrivalTime'])
                        ? Carbon::parse($seg['strArrivalDate'] . ' ' . $seg['strArrivalTime']) : null;

                    BookingSegment::updateOrCreate(
                        ['booking_id' => $booking->id, 'segment_no' => $idx + 1],
                        [
                            'airline'            => $seg['airline'] ?? null,
                            'equipment'          => $seg['equipment'] ?? null,
                            'departure_terminal' => $seg['departureTerminal'] ?? null,
                            'arrival_terminal'   => $seg['arrivalTerminal'] ?? null,
                            'departure_date'     => $departureDateTime,
                            'arrival_date'       => $arrivalDateTime,
                            'departure'          => $seg['departure'] ?? null,
                            'arrival'            => $seg['arrival'] ?? null,
                            'flight_num'         => $seg['flightNum'] ?? null,
                            'cabin_class'        => $seg['cabinClass'] ?? null,
                            'booking_code'       => $seg['bookingCode'] ?? null,
                        ]
                    );
                }
            }

            DB::commit();
            Log::info("Booking stored successfully: {$booking->order_num}");

            return response()->json([
                'message' => 'Booking created successfully.',
                'booking' => $booking->load(['passengers', 'segments']),
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking creation failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create booking.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified booking from local DB.
     */
    public function show(string $bookingId): JsonResponse
    {
        $booking = Booking::with('transactions')->where('order_num', $bookingId)->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        if (!$this->isAuthorizedForBooking($booking)) {
            return response()->json(['message' => 'Unauthorized to view this booking.'], 403);
        }

        return response()->json([
            'message' => 'Booking retrieved successfully.',
            'data'    => $booking,
        ]);
    }

    /**
     * Retrieve live booking details from PKFare.
     * Caches the response for 1 minute to prevent rapid refreshing from UI triggering API limits.
     */
    public function orderDetails(string $bookingId): JsonResponse
    {
        $booking = Booking::where('order_num', $bookingId)->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        if (!$this->isAuthorizedForBooking($booking)) {
            return response()->json(['message' => 'Unauthorized to view this booking.'], 403);
        }

        // Cache the live order details for 60 seconds
        $cacheKey = "pkfare_order_details_{$booking->order_num}";
        $pkfareResponse = Cache::remember($cacheKey, 60, function () use ($booking) {
            return $this->pkfareService->getBookingDetails($booking->order_num);
        });

        $errorCheck = $this->handlePkfareError($pkfareResponse, 'details');
        if ($errorCheck) return $errorCheck;

        $pkfareResponse['data']['paymentStatus'] = $booking->payment_status ?? 'N/A';

        return response()->json([
            'code'    => $pkfareResponse['errorCode'],
            'message' => 'Booking retrieved successfully.',
            'booking' => $pkfareResponse['data'],
        ]);
    }

    /**
     * Cancel the specified booking.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $validatedData = $request->validate([
            'orderNum' => 'required|string|exists:bookings,order_num',
            'pnr'      => 'required|string',
        ]);

        if (!$this->isAuthorizedForBooking($booking)) {
            return response()->json(['message' => 'Unauthorized to cancel this booking.'], 403);
        }

        if (in_array($booking->status, ['cancelled', 'ticketed', 'completed'])) {
            return response()->json(['message' => 'Booking cannot be cancelled in its current status.'], 400);
        }

        DB::beginTransaction();
        try {
            $pkfareResponse = $this->pkfareService->cancelBooking([
                'orderNum'   => $validatedData['orderNum'],
                'virtualPnr' => $validatedData['pnr']
            ]);

            $errorCheck = $this->handlePkfareError($pkfareResponse, 'cancel');
            if ($errorCheck) {
                DB::rollBack();
                return $errorCheck;
            }

            $booking->update(['status' => 'cancelled']);
            DB::commit();

            return response()->json([
                'message'         => 'Booking cancelled successfully.',
                'booking'         => $booking,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking cancellation failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to cancel booking.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle ticketing for a given booking.
     */
    public function ticketOrder(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'orderNum'      => 'required|string|exists:bookings,order_num',
            'pnr'           => 'required|string|exists:bookings,pnr',
            'contact'       => 'required|array',
            'contact.name'  => 'required|string',
            'contact.email' => 'required|email',
            'contact.telNum'=> 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // 1. Order Pricing Verification
            $pkfareResponse = $this->pkfareService->orderPricing($validatedData['orderNum']);
            $errorCheck = $this->handlePkfareError($pkfareResponse, 'pricing');
            if ($errorCheck) {
                DB::rollBack();
                return $errorCheck;
            }

            // 2. Ticketing Request
            $ticketResponse = $this->pkfareService->ticketOrder([
                'orderNum' => $validatedData['orderNum'],
                'PNR'      => $validatedData['pnr'],
                'name'     => $validatedData['contact']['name'],
                'email'    => $validatedData['contact']['email'],
                'telNum'   => $validatedData['contact']['telNum'],
            ]);

            $errorCheckTicketing = $this->handlePkfareError($ticketResponse, 'ticketing');
            if ($errorCheckTicketing) {
                DB::rollBack();
                return $errorCheckTicketing;
            }

            // 3. Update Status
            $booking = Booking::where('order_num', $validatedData['orderNum'])->firstOrFail();
            $booking->update(['issue_status' => 'ISS_PRC']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order ticketed successfully.',
                'booking' => $booking,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order ticketing failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to ticket booking.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * DRY helper to manage Authorization checks.
     */
    private function isAuthorizedForBooking(Booking $booking): bool
    {
        $user = auth()->user();
        if ($user->hasRole('admin') || $user->hasRole('agent')) {
            return true;
        }
        return $booking->user_id === $user->id;
    }

    /**
     * Centralized Error Handling for PKFare API Responses.
     * Keeps main controller methods clean.
     */
    private function handlePkfareError(array $response, string $context): ?JsonResponse
    {
        $errorCode = $response['errorCode'] ?? null;
        if ($errorCode === '0' || $errorCode === null) {
            return null; // No error
        }

        // Context-aware error mapping (PHP 8 match expression could also be used here)
        $errorMaps = [
            'booking' => [
                '0307' => 'Seats are no longer available.',
                'B005' => 'Pricing expired.'
            ],
            'details' => [
                'B037' => 'Order does not exist.'
            ],
            'cancel'  => [
                'B009' => 'Order status must be "to_be_paid".',
                'B041' => 'Order already cancelled.'
            ],
            'pricing' => [
                'B108' => 'Supplier day rollover — fare not guaranteed.',
                'B115' => 'Latest ticketing time expired.'
            ],
            'ticketing'=> [
                'B022' => 'Ticketing failed. Insufficient balance.',
                'B024' => 'Order already paid.'
            ]
        ];

        // Generic fallback messages
        $defaultMap = [
            'S001' => 'System error.',
            'B002' => 'Partner ID does not exist.',
            'B003' => 'Invalid signature.',
            'P001' => 'Invalid input data.'
        ];

        // Find specific error, then general error, then default to API message
        $message = $errorMaps[$context][$errorCode]
                ?? $defaultMap[$errorCode]
                ?? ($response['errorMsg'] ?? 'Request failed.');

        Log::warning("PKFare {$context} failed: [{$errorCode}] {$message}");

        return response()->json([
            'success' => false,
            'code'    => $errorCode,
            'message' => $message,
        ], 400);
    }
}
