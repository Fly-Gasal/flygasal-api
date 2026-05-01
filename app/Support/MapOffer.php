<?php

namespace App\Support;

/**
 * Transforms the highly nested and supplier-specific PKFare shopping response
 * into a clean, normalized format suitable for frontend consumption.
 */
final class MapOffer
{
    /**
     * Normalize PKFare "data" payload to UI-ready offers.
     *
     * @param array $payload The raw 'data' node from the PKFare response.
     * @return array<int, array<string, mixed>> An array of normalized flight offers.
     */
    public static function normalize(array $payload): array
    {
        // 1. Create fast-lookup dictionaries for segments and flights
        $segmentsById = [];
        foreach (($payload['segments'] ?? []) as $segment) {
            if (!empty($segment['segmentId'])) {
                $segmentsById[$segment['segmentId']] = $segment;
            }
        }

        $flightsById = [];
        foreach (($payload['flights'] ?? []) as $flight) {
            if (!empty($flight['flightId'])) {
                $flightsById[$flight['flightId']] = $flight;
            }
        }

        $solutions = $payload['solutions'] ?? [];
        if (empty($solutions)) {
            return [];
        }

        $normalizedOffers = [];

        foreach ($solutions as $sol) {
            // --- PASSENGER COUNTS ---
            $passengers = [
                'adults'   => (int)($sol['adults'] ?? 1),
                'children' => (int)($sol['children'] ?? 0),
                'infants'  => (int)($sol['infants'] ?? 0),
            ];
            $passengers['total'] = $passengers['adults'] + $passengers['children'] + $passengers['infants'];

            // --- JOURNEYS (Legs: e.g., Outbound, Return) ---
            $journeys = $sol['journeys'] ?? [];
            if (empty($journeys) || !is_array($journeys)) {
                continue;
            }

            // Ensure journey_0, journey_1 are processed in correct chronological order
            uksort($journeys, fn($a, $b) => strnatcmp((string)$a, (string)$b));

            $legs = [];
            $globalSegList = [];
            $globalIdxToSegId = []; // Maps PKFare's 1-based index to our segment IDs
            $marketingCarriersSet = [];
            $operatingCarriersSet = [];
            $flightIdsAll = [];
            $lastTktCandidates = [];

            foreach ($journeys as $journeyKey => $flightIdsOfJourney) {
                $legFlightIds = array_values(array_filter((array)$flightIdsOfJourney));
                if (empty($legFlightIds)) continue;

                $legSegments = [];

                foreach ($legFlightIds as $flightId) {
                    $flight = $flightsById[$flightId] ?? null;
                    if (!$flight) continue;

                    $flightIdsAll[] = $flightId;

                    // Track the earliest required ticketing time
                    if (!empty($flight['lastTktTime'])) {
                        $isoTime = self::parseDateFlex($flight['lastTktTime']);
                        if ($isoTime) $lastTktCandidates[] = $isoTime;
                    }

                    // NOTE: PKFare misspells this as "segmengtIds". We must preserve their typo to read the data.
                    $segmentIds = $flight['segmengtIds'] ?? [];

                    foreach ((array)$segmentIds as $segmentId) {
                        if (!isset($segmentsById[$segmentId])) continue;
                        $seg = $segmentsById[$segmentId];

                        // Collect unique carriers for UI filtering
                        if (!empty($seg['airline'])) {
                            $marketingCarriersSet[$seg['airline']] = true;
                        }
                        $operatingCarrier = trim((string)($seg['opFltAirline'] ?? ''));
                        if ($operatingCarrier !== '') {
                            $operatingCarriersSet[$operatingCarrier] = true;
                        }

                        // Enrich segment with standardized dates and tracking tags
                        $enrichedSegment = self::withIsoTimes($seg + ['flightId' => $flightId]);
                        $enrichedSegment['journeyKey'] = $journeyKey;
                        $enrichedSegment['legIndex']   = count($legs);

                        $legSegments[] = $enrichedSegment;

                        // PKFare maps baggage and rules using a global 1-based index across ALL segments.
                        // We build a map here to translate "Segment 1" to the actual UUID.
                        $globalIdxToSegId[count($globalSegList) + 1] = $segmentId;
                        $globalSegList[] = $segmentId;
                    }
                }

                if (empty($legSegments)) continue;

                // Defensively sort segments by departure time to ensure logical order
                usort($legSegments, fn(array $a, array $b): int =>
                    (int)($a['departureDate'] ?? 0) <=> (int)($b['departureDate'] ?? 0)
                );

                $firstSeg = $legSegments[0];
                $lastSeg  = $legSegments[count($legSegments) - 1];
                $firstFlight  = $flightsById[$legFlightIds[0]] ?? null;

                // Build a 1-based index map specific to this leg
                $legIdxToSegId = [];
                foreach ($legSegments as $i => $s) {
                    $legIdxToSegId[$i + 1] = $s['segmentId'];
                }

                $legs[] = [
                    'flightIds'     => $legFlightIds,
                    'segments'      => $legSegments,
                    'origin'        => $firstSeg['departure'] ?? null,
                    'destination'   => $lastSeg['arrival'] ?? null,
                    'departureTime' => self::formatEpochToIso($firstSeg['departureDate'] ?? null),
                    'arrivalTime'   => self::formatEpochToIso($lastSeg['arrivalDate'] ?? null),
                    'journeyTime'   => $firstFlight['journeyTime'] ?? null,
                    'transferCount' => $firstFlight['transferCount'] ?? max(count($legSegments) - 1, 0),
                    'stops'         => max(count($legSegments) - 1, 0),
                    'terminals'     => [
                        'from' => $firstSeg['departureTerminal'] ?? null,
                        'to'   => $lastSeg['arrivalTerminal'] ?? null,
                    ],
                    'idxToSegId'    => $legIdxToSegId,
                ];
            }

            if (empty($legs)) continue;

            // --- BAGGAGE & RULES MAPPING ---
            [$adtChecked, $adtCarry] = self::mapBaggageByGlobalIndex(
                $sol['baggageMap']['ADT'] ?? [],
                $globalIdxToSegId
            );

            $rulesADT = self::mapMiniRulesByGlobalIndex(
                $sol['miniRuleMap']['ADT'] ?? [],
                $globalIdxToSegId
            );

            // --- PRICING ---
            $currency   = $sol['currency'] ?? 'USD';
            $priceBreak = self::buildPriceBreakdown($sol, $passengers, $currency);

            // --- METADATA & IDENTIFIERS ---
            $supplier    = $payload['supplier']    ?? ($sol['supplier'] ?? null);
            $solutionId  = $sol['solutionId']      ?? null;
            $solutionKey = $sol['solutionKey']     ?? null;
            $shoppingKey = $payload['shoppingKey'] ?? null;
            $plating     = $sol['platingCarrier']  ?? null;

            // Generate a coherence key to validate state during precise pricing
            $coherenceKey = implode('|', [
                $solutionId ?: 'NA',
                $solutionKey ?: 'NAKEY',
                $supplier ?: 'SRC',
                $plating ?: 'PLATE',
                $currency ?: 'CUR',
                $shoppingKey ?: 'SHOP',
            ]);

            $marketing = array_values(array_keys($marketingCarriersSet));
            $operating = array_values(array_filter(array_keys($operatingCarriersSet)));

            // Generate an internal Unique ID for this specific offer
            $head = $legs[0];
            $firstSegForId = $head['segments'][0] ?? null;
            $offerId = implode('|', [
                $solutionId ?: ($firstSegForId['segmentId'] ?? 'OFF'),
                $head['origin'] ?? 'OOO',
                $head['destination'] ?? 'DDD',
                $head['departureTime'] ?? '',
            ]);

            // Determine if the offer has expired based on last ticketing time
            $lastTktIso = null;
            if (!empty($lastTktCandidates)) {
                sort($lastTktCandidates);
                $lastTktIso = $lastTktCandidates[0]; // Earliest time wins
            }
            $expired = $lastTktIso ? (strtotime($lastTktIso) < time()) : false;

            // Calculate total stops across all bounds
            $stopsByLeg = array_map(fn(array $l): int => max(0, (int)($l['stops'] ?? 0)), $legs);
            $totalStops = array_sum($stopsByLeg);
            $outboundStops = $stopsByLeg[0] ?? null;
            $returnStops = $stopsByLeg[1] ?? null;

            // Flatten segments for easy UI rendering (e.g., standard flight timeline)
            $flatSegments = [];
            $seenSegments = [];
            foreach ($legs as $leg) {
                foreach ($leg['segments'] as $segObj) {
                    $sid = $segObj['segmentId'] ?? null;
                    if ($sid && isset($seenSegments[$sid])) continue;
                    if ($sid) $seenSegments[$sid] = true;
                    $flatSegments[] = $segObj;
                }
            }

            // --- ASSEMBLE FINAL OFFER ---
            $normalizedOffers[] = [
                'id'                => $offerId,
                'solutionKey'       => $solutionKey,
                'solutionId'        => $solutionId,
                'shoppingKey'       => $shoppingKey,
                'supplier'          => $supplier,
                'coherenceKey'      => $coherenceKey,
                'platingCarrier'    => $plating,
                'marketingCarriers' => $marketing,
                'operatingCarriers' => $operating,
                'origin'            => $head['origin'],
                'destination'       => $head['destination'],
                'summary' => [
                    'legs' => array_map(function (array $leg) use ($coherenceKey): array {
                        $leg['coherenceKey'] = $coherenceKey;
                        return $leg;
                    }, $legs),
                    'globalIdxToSegId' => $globalIdxToSegId,
                ],
                'passengers'     => $passengers,
                'priceBreakdown' => $priceBreak,
                'baggage' => [
                    'adt' => [
                        'checkedBySegment' => $adtChecked,
                        'carryOnBySegment' => $adtCarry,
                    ],
                ],
                'rules'          => ['adt' => $rulesADT],
                'flightIds'      => array_values(array_unique($flightIdsAll)),
                'segments'       => $flatSegments,
                'journeyTime'    => $firstFlight['journeyTime'] ?? null,
                'lastTktTime'    => $lastTktIso,
                'expired'        => $expired,
                'stops'          => (count($legs) === 1) ? ($outboundStops ?? 0) : null,
                'totalStops'     => $totalStops,
                'stopsByLeg'     => $stopsByLeg,
                'outboundStops'  => $outboundStops,
                'returnStops'    => $returnStops,
            ];
        }

        return $normalizedOffers;
    }

    // ---------------------------------------------------------------------
    // PRICING HELPERS
    // ---------------------------------------------------------------------

    /**
     * Calculates the exact price breakdown per passenger type and overall totals.
     */
    private static function buildPriceBreakdown(array $sol, array $pax, string $currency): array
    {
        $unitFares = [
            'ADT' => [
                'fare'  => self::toFloat($sol['adtFare'] ?? 0),
                'tax'   => self::toFloat($sol['adtTax']  ?? 0),
                'count' => (int)($pax['adults'] ?? 0),
            ],
            'CHD' => [
                'fare'  => self::toFloat($sol['chdFare'] ?? 0),
                'tax'   => self::toFloat($sol['chdTax']  ?? 0),
                'count' => (int)($pax['children'] ?? 0),
            ],
            'INF' => [
                'fare'  => self::toFloat($sol['infFare'] ?? $sol['infantFare'] ?? 0),
                'tax'   => self::toFloat($sol['infTax']  ?? $sol['infantTax']  ?? 0),
                'count' => (int)($pax['infants'] ?? 0),
            ],
        ];

        $passengerTotals = [];
        $sumBase = 0.0;
        $sumTax = 0.0;

        foreach ($unitFares as $typeCode => $row) {
            $count = max(0, $row['count']);
            $fare  = $row['fare'];
            $tax   = $row['tax'];

            $baseSubtotal = $fare * $count;
            $taxSubtotal  = $tax  * $count;

            $sumBase += $baseSubtotal;
            $sumTax  += $taxSubtotal;

            $passengerTotals[$typeCode] = [
                'count'    => $count,
                'unit'     => [
                    'fare'  => self::round($fare),
                    'tax'   => self::round($tax),
                    'total' => self::round($fare + $tax),
                ],
                'subtotal' => [
                    'base'  => self::round($baseSubtotal),
                    'taxes' => self::round($taxSubtotal),
                    'total' => self::round($baseSubtotal + $taxSubtotal),
                ],
            ];
        }

        $fees = [
            'qCharge'            => self::toFloat($sol['qCharge'] ?? 0),
            'tktFee'             => self::toFloat($sol['tktFee'] ?? 0),
            'platformServiceFee' => self::toFloat($sol['platformServiceFee'] ?? 0),
            'merchantFee'        => self::toFloat($sol['merchantFee'] ?? 0),
        ];

        $sumFees = array_sum($fees);
        $grandTotal = $sumBase + $sumTax + $sumFees;

        return [
            'currency'     => $currency,
            'perPassenger' => $passengerTotals,
            'fees' => [
                'items' => array_map([self::class, 'round'], $fees),
                'total' => self::round($sumFees),
            ],
            'totals' => [
                'base'  => self::round($sumBase),
                'taxes' => self::round($sumTax),
                'fees'  => self::round($sumFees),
                'grand' => self::round($grandTotal),
            ],
            'source' => [
                'pricesRaw' => $sol['prices'] ?? null,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // UTILITY HELPERS
    // ---------------------------------------------------------------------

    /** Converts epoch ms to ISO-8601. */
    private static function formatEpochToIso(int|float|string|null $ms): ?string
    {
        return is_numeric($ms) ? date(DATE_ATOM, ((int)$ms) / 1000) : null;
    }

    /** Parses mixed date formats (epoch ms or string) into ISO-8601. */
    private static function parseDateFlex(int|float|string|null $value): ?string
    {
        if (empty($value)) return null;
        if (is_numeric($value)) {
            return date(DATE_ATOM, ((int)$value) / 1000);
        }
        $ts = strtotime((string)$value);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }

    /** Safely casts mixed values to float. */
    private static function toFloat(mixed $val): float
    {
        return is_numeric($val) ? (float)$val : 0.0;
    }

    /** Rounds currency values to 2 decimal places. */
    private static function round(float|int $val): float
    {
        return round((float)$val, 2);
    }

    /** Injects standardized ISO time formats into a raw segment array. */
    private static function withIsoTimes(array $segment): array
    {
        $segment['departureIso'] = self::formatEpochToIso($segment['departureDate'] ?? null);
        $segment['arrivalIso']   = self::formatEpochToIso($segment['arrivalDate'] ?? null);
        return $segment;
    }

    /**
     * Translates PKFare's 1-based global baggage index array into actual segment IDs.
     */
    private static function mapBaggageByGlobalIndex(array $adtBlocks, array $globalIdxToSegId): array
    {
        $adtChecked = [];
        $adtCarry   = [];

        foreach ($adtBlocks as $block) {
            $indices = (array)($block['segmentIndexList'] ?? []);

            foreach ($indices as $index) {
                $segmentId = $globalIdxToSegId[$index] ?? null;
                if (!$segmentId) continue;

                if (isset($block['baggageAmount']) || isset($block['baggageWeight'])) {
                    $adtChecked[$segmentId] = [
                        'amount' => $block['baggageAmount'] ?? null,
                        'weight' => $block['baggageWeight'] ?? null,
                    ];
                }

                if (isset($block['carryOnAmount']) || isset($block['carryOnWeight']) || isset($block['carryOnSize'])) {
                    $adtCarry[$segmentId] = [
                        'amount' => $block['carryOnAmount'] ?? null,
                        'weight' => $block['carryOnWeight'] ?? null,
                        'size'   => $block['carryOnSize'] ?? null,
                    ];
                }
            }
        }

        return [$adtChecked, $adtCarry];
    }

    /**
     * Translates PKFare's 1-based global rules index array into actual segment IDs.
     */
    private static function mapMiniRulesByGlobalIndex(array $adtBlocks, array $globalIdxToSegId): array
    {
        $rulesADT = [];

        foreach ($adtBlocks as $block) {
            $ids = [];
            foreach ((array)($block['segmentIndex'] ?? []) as $index) {
                $segmentId = $globalIdxToSegId[$index] ?? null;
                if ($segmentId) $ids[] = $segmentId;
            }

            $miniRules = array_map(function ($rule) {
                // Map PKFare's numeric penalty types to readable strings
                $rule['label'] = match ($rule['penaltyType'] ?? -1) {
                    0       => 'Refund',
                    1       => 'Change',
                    2       => 'No-show',
                    3       => 'Reissue / Reroute',
                    default => 'Penalty',
                };
                return $rule;
            }, (array)($block['miniRules'] ?? []));

            $rulesADT[] = [
                'segmentIds' => $ids,
                'miniRules'  => $miniRules,
            ];
        }

        return $rulesADT;
    }
}
