<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Exception;

/**
 * Service class for interacting with the PKfare Flight API.
 * * This class encapsulates all API requests, handles authentication signatures,
 * formats payloads to match PKfare's specific schema requirements, and manages errors.
 */
class PKfareService
{
    /**
     * @var Client The Guzzle HTTP client instance used for API requests.
     */
    protected Client $client;

    /**
     * @var string The base URL for the PKfare API.
     */
    protected string $baseUrl;

    /**
     * @var string The Partner ID / API Key provided by PKfare.
     */
    protected string $apiKey;

    /**
     * @var string The API Secret provided by PKfare, used for signing requests.
     */
    protected string $apiSecret;

    /**
     * Constructor.
     * Initializes configuration and sets up the Guzzle HTTP client.
     *
     * @throws InvalidArgumentException If API credentials are not set in the environment.
     */
    public function __construct()
    {
        $this->baseUrl = config('app.pkfare_api_base_url', 'https://api.pkfare.com');
        $this->apiKey = config('app.pkfare_api_key', '');
        $this->apiSecret = config('app.pkfare_api_secret', '');

        if (empty($this->apiKey) || empty($this->apiSecret)) {
            Log::error('PKfare API keys are not set in the environment variables.');
            throw new InvalidArgumentException('PKfare API keys are not configured.');
        }

        // Initialize the Guzzle client with base URI, default headers, and timeout.
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 75, // 75 seconds timeout for long-running flight searches
        ]);
    }

    /**
     * Generates the standard authentication payload required by PKfare.
     * PKfare uses an MD5 hash of the Partner ID and API Secret for authentication.
     *
     * @return array The authentication block for the API payload.
     */
    protected function getAuthPayload(): array
    {
        return [
            'partnerId' => $this->apiKey,
            'sign' => md5($this->apiKey . $this->apiSecret),
        ];
    }

    /**
     * Formats the journeys array to match PKfare's specific indexed key requirement.
     * PKfare expects flight segments to be grouped by journey keys (e.g., 'journey_0', 'journey_1').
     *
     * @param array $journeys Raw array of journey segments.
     * @return array Formatted array grouped by 'journey_X' keys.
     */
    protected function formatJourneys(array $journeys): array
    {
        // If a single flat journey segment is passed, wrap it in an array to standardize the loop.
        if (!empty($journeys) && isset($journeys[0]['flightNum'])) {
            $journeys = [$journeys];
        }

        $formattedJourneys = [];
        foreach ($journeys as $index => $segments) {
            $key = 'journey_' . $index;
            $formattedJourneys[$key] = array_map(function ($segment) {
                return [
                    'airline' => $segment['airline'] ?? '',
                    'flightNum' => $segment['flightNum'] ?? '',
                    'arrival' => $segment['arrival'] ?? '',
                    // Support alternative keys (strArrivalDate vs arrivalDate) gracefully
                    'arrivalDate' => $segment['strArrivalDate'] ?? $segment['arrivalDate'] ?? '',
                    'arrivalTime' => $segment['strArrivalTime'] ?? $segment['arrivalTime'] ?? '',
                    'departure' => $segment['departure'] ?? '',
                    'departureDate' => $segment['strDepartureDate'] ?? $segment['departureDate'] ?? '',
                    'departureTime' => $segment['strDepartureTime'] ?? $segment['departureTime'] ?? '',
                    'bookingCode' => $segment['bookingCode'] ?? '',
                ];
            }, $segments);
        }

        return $formattedJourneys;
    }

    /**
     * Executes a GET request to the PKfare API.
     *
     * @param string $endpoint The API endpoint (e.g., '/json/someEndpoint').
     * @param array $query Optional query parameters.
     * @return array The JSON decoded response.
     * @throws Exception If the request fails or returns invalid JSON.
     */
    public function get(string $endpoint, array $query = []): array
    {
        try {
            $response = $this->client->get($endpoint, ['query' => $query]);
            $data = json_decode($response->getBody()->getContents(), true);

            if (!is_array($data)) {
                throw new Exception("Invalid JSON response from PKfare API on GET {$endpoint}");
            }

            return $data;
        } catch (RequestException $e) {
            // Explicitly throw the exception returned by the helper
            throw $this->handleRequestException($e, $endpoint);
        }
    }

    /**
     * Executes a POST request to the PKfare API.
     *
     * @param string $endpoint The API endpoint (e.g., '/json/shoppingV8').
     * @param array $data The JSON payload body.
     * @return array The JSON decoded response.
     * @throws Exception If the request fails or returns invalid JSON.
     */
    public function post(string $endpoint, array $data = []): array
    {
        try {
            $response = $this->client->post($endpoint, ['json' => $data]);
            $decodedData = json_decode($response->getBody()->getContents(), true);

            if (!is_array($decodedData)) {
                throw new Exception("Invalid JSON response from PKfare API on POST {$endpoint}");
            }

            return $decodedData;
        } catch (RequestException $e) {
            // Explicitly throw the exception returned by the helper
            throw $this->handleRequestException($e, $endpoint);
        }
    }

    /**
     * Handles Guzzle Request Exceptions, logs them, and prepares a generic exception.
     *
     * @param RequestException $e The exception caught
     * @param string $endpoint The endpoint that was called
     * @return Exception
     */
    protected function handleRequestException(RequestException $e, string $endpoint): Exception
    {
        $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
        $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';

        Log::error("PKfare API request failed for endpoint: {$endpoint}", [
            'status_code' => $statusCode,
            'message' => $e->getMessage(),
            'response_body' => $responseBody,
        ]);

        // Return the exception instead of throwing it here
        return new Exception("PKfare API request failed: " . $e->getMessage());
    }

    /**
     * Searches for flights based on provided passenger and routing criteria.
     *
     * @param array $criteria Contains search rules: flights (array), adults, children, infants, airline, nonstop, etc.
     * @return array The shopping API response payload.
     * @throws Exception If no flight legs are provided.
     */
    public function searchFlights(array $criteria): array
    {
        $flights = $criteria['flights'] ?? [];

        if (empty($flights)) {
            throw new Exception("At least one flight leg must be provided.");
        }

        $payload = [
            'authentication' => $this->getAuthPayload(),
            'search' => [
                'adults' => $criteria['adults'] ?? 1,
                'children' => $criteria['children'] ?? 0,
                'infants' => $criteria['infants'] ?? 0,
                'nonstop' => $criteria['nonstop'] ?? 0,
                'airline' => $criteria['airline'] ?? '',
                'solutions' => $criteria['solutions'] ?? 0,
                'tag' => '',
                'returnTagPrice' => 'Y',
                'searchAirLegs' => [],
            ],
        ];

        // Build outbound flight legs
        foreach ($flights as $flight) {
            $payload['search']['searchAirLegs'][] = [
                'cabinClass'   => $flight['cabinClass'] ?? $criteria['cabinClass'] ?? '',
                'departureDate'=> $flight['depart'],
                'origin'       => $flight['origin'],
                'destination'  => $flight['destination'],
                'airline'      => $flight['airline'] ?? $criteria['airline'] ?? '',
            ];
        }

        // Automatically append return leg if it's a round trip
        if (!empty($criteria['returnDate'])) {
            $payload['search']['searchAirLegs'][] = [
                'cabinClass'   => '',
                'departureDate'=> $criteria['returnDate'],
                'origin'       => $criteria['destination'], // swap origin/destination for return
                'destination'  => $criteria['origin'],
                'airline'      => $criteria['airline'] ?? '',
            ];
        }

        return $this->post('/json/shoppingV8', $payload);
    }

    /**
     * Retrieves precise pricing and validates availability for a selected flight solution.
     *
     * @param array $criteria Requires 'journeys' array and 'solutionKey'.
     * @return array The precise pricing response.
     */
    public function getPrecisePricing(array $criteria): array
    {
        $payload = [
            'authentication' => $this->getAuthPayload(),
            'pricing' => [
                'journeys' => $this->formatJourneys($criteria['journeys'] ?? []),
                'adults' => $criteria['adults'] ?? 1,
                'children' => $criteria['children'] ?? 0,
                'infants' => $criteria['infants'] ?? 0,
                'solutionId' => "direct_pricing",
                'solutionKey' => $criteria['solutionKey'] ?? '',
                'cabin' => '',
                'tag' => "",
            ],
        ];

        return $this->post('/json/precisePricing_V10', $payload);
    }

    /**
     * Utility method to extract specific journey data from a list of solutions based on a solutionKey.
     *
     * @param array $solutions List of available flight solutions.
     * @param string $solutionKey The unique key identifying the chosen solution.
     * @return array An array containing the solution key and its journeys.
     * @throws Exception If the solutionKey is not found in the provided solutions array.
     */
    public function extractPricingInfoFromSolutions(array $solutions, string $solutionKey): array
    {
        foreach ($solutions as $solution) {
            if ($solution['solutionKey'] === $solutionKey) {
                return [
                    'solutionKey' => $solutionKey,
                    'journeys' => $solution['journeys'],
                ];
            }
        }

        throw new Exception("Solution with key {$solutionKey} not found.");
    }

    /**
     * Requests ancillary pricing (e.g., baggage, seat selection) for a given journey.
     *
     * @param array $criteria Requires 'journeys', 'solutionId', and passenger counts.
     * @return array The ancillary pricing response.
     */
    public function ancillaryPricing(array $criteria): array
    {
        $payload = [
            'authentication' => $this->getAuthPayload(),
            'pricing' => [
                'adults' => $criteria['adults'] ?? 1,
                'children' => $criteria['children'] ?? 0,
                "ancillary" => [2], // 2 usually denotes Baggage in typical airline APIs, verify with PKfare docs
                'solutionId' => $criteria['solutionId'] ?? null,
                'journeys' => $this->formatJourneys($criteria['journeys'] ?? []),
            ]
        ];

        return $this->post('/json/ancillaryPricingV6', $payload);
    }

    /**
     * Submits a booking request to PKfare.
     *
     * @param array $bookingDetails An extensive associative array containing passenger info,
     * contact info, pricing breakdown, and flight segments.
     * @return array The booking confirmation response, usually including a PNR or order number.
     */
    public function createBooking(array $bookingDetails): array
    {
        $payload = [
            'authentication' => $this->getAuthPayload(),
            'booking' => [
                // Map local passenger array to PKfare's expected format
                'passengers' => array_map(function ($passenger, $index) {
                    return [
                        'passengerIndex' => $index + 1,
                        'birthday' => $passenger['dob'],
                        'firstName' => $passenger['firstName'],
                        'lastName' => $passenger['lastName'],
                        'nationality' => $passenger['nationality'] ?? 'KE',
                        'cardType' => $passenger['cardType'] ?? 'P', // 'P' for Passport
                        'cardNum' => $passenger['passportNumber'] ?? null,
                        'cardExpiredDate' => $passenger['passportExpiry'] ?? null,
                        'psgType' => $passenger['type'], // ADT, CHD, INF
                        'sex' => strtoupper(substr($passenger['gender'], 0, 1)), // Ensure 'M' or 'F'
                        'ffpNumber' => $passenger['ffpNumber'] ?? null,
                        'ffpAirline' => $passenger['ffpAirline'] ?? null,
                        'ktn' => $passenger['ktn'] ?? null, // Known Traveler Number
                        'redress' => $passenger['redress'] ?? null,
                        'associatedPassengerIndex' => $passenger['associatedPassengerIndex'] ?? null, // Required for infants
                    ];
                }, $bookingDetails['passengers'], array_keys($bookingDetails['passengers'])),

                'solution' => [
                    'solutionId' => $bookingDetails['solutionId'],
                    // Fare breakdown mapping
                    'adtFare' => $bookingDetails['selectedFlight']['priceBreakdown']['ADT']['fare'] ?? 0,
                    'adtTax'  => $bookingDetails['selectedFlight']['priceBreakdown']['ADT']['taxes'] ?? 0,
                    'chdFare' => $bookingDetails['selectedFlight']['priceBreakdown']['CHD']['fare'] ?? 0,
                    'chdTax'  => $bookingDetails['selectedFlight']['priceBreakdown']['CHD']['taxes'] ?? 0,
                    'infFare' => $bookingDetails['selectedFlight']['priceBreakdown']['INF']['fare'] ?? 0,
                    'infTax'  => $bookingDetails['selectedFlight']['priceBreakdown']['INF']['taxes'] ?? 0,
                    // Format flight segments
                    'journeys' => $this->formatJourneys($bookingDetails['selectedFlight']['segments'] ?? []),
                ],

                'contact' => [
                    'name' => $bookingDetails['contactInfo']['name'],
                    'email' => $bookingDetails['contactInfo']['email'],
                    'telCode' => $bookingDetails['contactInfo']['telCode'] ?? '+1',
                    'mobile' => $bookingDetails['contactInfo']['phone'],
                    'buyerEmail' => $bookingDetails['contactInfo']['buyerEmail'] ?? null,
                    'buyerTelCode' => $bookingDetails['contactInfo']['buyerTelCode'] ?? null,
                    'buyerMobile' => $bookingDetails['contactInfo']['buyerMobile'] ?? null,
                ],

                'ancillary' => $bookingDetails['ancillary'] ?? [],
            ]
        ];

        return $this->post('/json/preciseBooking_V7', $payload);
    }

    /**
     * Retrieves the current status and details of an existing booking.
     *
     * @param string $bookingReference The internal PKfare order number.
     * @return array The full booking details response.
     */
    public function getBookingDetails(string $bookingReference): array
    {
        $payload = [
            'authentication' => $this->getAuthPayload(),
            'data' => [
                'orderNum' => $bookingReference,
                'includeFields' => "passengers,journeys,solutions,ancillary,scheduleChange,checkinInfo"
            ]
        ];

        return $this->post('/json/orderDetail/v10', $payload);
    }

    /**
     * Cancels an existing booking/PNR.
     *
     * @param array $bookingDetails Requires 'orderNum' and 'virtualPnr' (or regular PNR).
     * @return array The cancellation response.
     */
    public function cancelBooking(array $bookingDetails): array
    {
        $payload = [
            'authentication' => $this->getAuthPayload(),
            'cancel' => [
                'orderNum' => $bookingDetails['orderNum'],
                'virtualPnr' => $bookingDetails['pnr']
            ]
        ];

        return $this->post('/json/cancel', $payload);
    }

    /**
     * Validates PNR and order price before payment and enforces ticketing within 30 minutes.
     *
     * @param string $orderNum The order number from the booking response.
     * @return array The order pricing confirmation.
     */
    public function orderPricing(string $orderNum): array
    {
        $payload = [
            'authentication' => $this->getAuthPayload(),
            'orderPricing' => [
                'orderNum' => $orderNum
            ]
        ];

        return $this->post('/json/orderPricingV5', $payload);
    }

    /**
     * Issues the actual ticket for a created order.
     *
     * @param array $criteria Requires 'orderNum' and 'PNR'. Optionally accepts contact info.
     * @return array The ticketing response (should contain ticket numbers upon success).
     */
    public function ticketOrder(array $criteria): array
    {
        $payload = [
            'authentication' => $this->getAuthPayload(),
            'ticketing' => [
                'orderNum' => $criteria['orderNum'],
                'PNR' => $criteria['PNR'],
                'name' => $criteria['name'] ?? null,
                'email' => $criteria['email'] ?? null,
                'telNum' => $criteria['telNum'] ?? null,
            ]
        ];

        return $this->post('/json/ticketing', $payload);
    }
}
