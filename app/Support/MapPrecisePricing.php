<?php

namespace App\Support;

/**
 * Normalizes PKFare's "precise pricing" payload into a structured UI-ready offer.
 * Designed to maintain structural parity with MapOffer so the frontend can use
 * the same components for rendering search results and confirmed pricing.
 */
final class MapPrecisePricing
{
    /**
     * Normalize PKFare "precise pricing" `data` payload.
     *
     * @param array $payload The PKFare precise pricing "data" array
     * @return array<string, mixed> A single normalized offer
     */
    public static function normalize(array $payload): array
    {
        $solution = $payload['solution'] ?? [];
        $segmentsArr = $payload['segments'] ?? [];
        $flightsArr  = $payload['flights']  ?? [];

        // 1. Index segments and flights by their IDs for fast lookup
        $segmentsById = [];
        foreach ($segmentsArr as $s) {
            if (!empty($s['segmentId'])) {
                $segmentsById[$s['segmentId']] = $s;
            }
        }

        $flightsById = [];
        foreach ($flightsArr as $f) {
            if (!empty($f['flightId'])) {
                $flightsById[$f['flightId']] = $f;
            }
        }

        // 2. Passengers summary
        $passengers = [
            'adults'   => (int)($solution['adults'] ?? 1),
            'children' => (int)($solution['children'] ?? 0),
            'infants'  => (int)($solution['infants'] ?? 0),
        ];
        $passengers['total'] = $passengers['adults'] + $passengers['children'] + $passengers['infants'];

        // 3. Process Journeys into structured Legs (Maintains parity with MapOffer)
        $journeys = $solution['journeys'] ?? [];
        uksort($journeys, fn($a, $b) => strnatcmp((string)$a, (string)$b));

        $legs = [];
        $globalIdxToSegId = []; // 1-based index mapping for baggage/rules
        $globalSegList = [];
        $flightIdsAll = [];
        $marketingCarriersSet = [];
        $operatingCarriersSet = [];

        foreach ($journeys as $journeyKey => $flightIdsOfJourney) {
            $legFlightIds = array_values(array_filter((array)$flightIdsOfJourney));
            if (empty($legFlightIds)) continue;

            $legSegments = [];

            foreach ($legFlightIds as $flightId) {
                $flight = $flightsById[$flightId] ?? null;
                if (!$flight) continue;

                $flightIdsAll[] = $flightId;

                // Support both the correct spelling and PKFare's typo
                $segIds = $flight['segmentIds'] ?? $flight['segmengtIds'] ?? [];

                foreach ((array)$segIds as $segmentId) {
                    if (!isset($segmentsById[$segmentId])) continue;
                    $seg = $segmentsById[$segmentId];

                    // Collect carriers for top-level summary
                    if (!empty($seg['airline'])) {
                        $marketingCarriersSet[$seg['airline']] = true;
                    }
                    $opAirline = trim((string)($seg['opFltAirline'] ?? ''));
                    if ($opAirline !== '') {
                        $operatingCarriersSet[$opAirline] = true;
                    }

                    // Enrich segment data
                    $enrichedSegment = self::withIsoTimes($seg + ['flightId' => $flightId]);
                    $enrichedSegment['journeyKey'] = $journeyKey;
                    $enrichedSegment['legIndex']   = count($legs);

                    $legSegments[] = $enrichedSegment;

                    // Build global 1-based index map for baggage & rules
                    $globalIdxToSegId[count($globalSegList) + 1] = $segmentId;
                    $globalSegList[] = $segmentId;
                }
            }

            if (empty($legSegments)) continue;

            // Ensure chronological order
            usort($legSegments, fn($a, $b) => (int)($a['departureDate'] ?? 0) <=> (int)($b['departureDate'] ?? 0));

            $firstSeg = $legSegments[0];
            $lastSeg  = $legSegments[count($legSegments) - 1];
            $firstFlight  = $flightsById[$legFlightIds[0]] ?? null;

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

        if (empty($legs)) {
            return [
                'error' => 'No valid journeys found in precise pricing payload.',
                'raw'   => $payload,
            ];
        }

        // 4. Map Baggage & Rules (Adult & Child)
        [$adtChecked, $adtCarry] = self::mapBaggageByGlobalIndex($solution['baggageMap']['ADT'] ?? [], $globalIdxToSegId);
        [$chdChecked, $chdCarry] = self::mapBaggageByGlobalIndex($solution['baggageMap']['CHD'] ?? [], $globalIdxToSegId);

        $rulesADT = self::mapMiniRulesByGlobalIndex($solution['miniRuleMap']['ADT'] ?? [], $globalIdxToSegId);
        $rulesCHD = self::mapMiniRulesByGlobalIndex($solution['miniRuleMap']['CHD'] ?? [], $globalIdxToSegId);

        // 5. Build Pricing Breakdown
        $currency = $solution['currency'] ?? 'USD';
        $priceBreakdown = self::buildPriceBreakdown($solution, $passengers, $currency);

        // 6. Meta & Carriers
        $marketing = array_values(array_keys($marketingCarriersSet));
        $operating = array_values(array_filter(array_keys($operatingCarriersSet)));

        $head = $legs[0];
        $firstSeg = $head['segments'][0];

        $ancillaries = $payload['ancillaryAvailability'] ?? [];

        // Flatten segments for easy top-level access if needed
        $flatSegments = [];
        $seen = [];
        foreach ($legs as $leg) {
            foreach ($leg['segments'] as $segObj) {
                $sid = $segObj['segmentId'] ?? null;
                if ($sid && isset($seen[$sid])) continue;
                if ($sid) $seen[$sid] = true;
                $flatSegments[] = $segObj;
            }
        }

        // Calculate total stops
        $stopsByLeg = array_map(fn(array $l): int => max(0, (int)($l['stops'] ?? 0)), $legs);
        $totalStops = array_sum($stopsByLeg);

        // 7. Compose Final Normalized Offer
        return [
            'id'                 => $firstSeg['segmentId'] ?? null,
            'type'               => 'precise_pricing',
            'solutionKey'        => $solution['solutionKey'] ?? null,
            'solutionId'         => $solution['solutionId'] ?? null,
            'fareType'           => $solution['fareType'] ?? null,
            'platingCarrier'     => $solution['platingCarrier'] ?? null,
            'bookingWithoutCard' => (int)($solution['bookingWithoutCard'] ?? 0),

            'marketingCarriers'  => $marketing,
            'operatingCarriers'  => $operating,
            'flightIds'          => array_values(array_unique($flightIdsAll)),

            'origin'             => $head['origin'],
            'destination'        => $head['destination'],

            'summary' => [
                'legs' => $legs,
                'globalIdxToSegId' => $globalIdxToSegId,
            ],

            // Legacy flattened segments array
            'segments'           => $flatSegments,

            'journeyTime'        => $legs[0]['journeyTime'] ?? null,
            'stops'              => (count($legs) === 1) ? ($stopsByLeg[0] ?? 0) : null,
            'totalStops'         => $totalStops,
            'stopsByLeg'         => $stopsByLeg,

            'passengers'         => $passengers,

            'baggage' => [
                'adt' => [
                    'checkedBySegment' => $adtChecked,
                    'carryOnBySegment' => $adtCarry,
                ],
                'chd' => [
                    'checkedBySegment' => $chdChecked,
                    'carryOnBySegment' => $chdCarry,
                ],
                'rawByIndex' => $solution['baggages'] ?? null,
            ],

            'rules' => [
                'adt' => $rulesADT,
                'chd' => $rulesCHD,
            ],

            'priceBreakdown' => $priceBreakdown,

            'ancillaryAvailability' => [
                'paidBag'  => (bool)($ancillaries['paidBag'] ?? false),
                'paidSeat' => (bool)($ancillaries['paidSeat'] ?? false),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // PRICING HELPERS
    // ---------------------------------------------------------------------

    /**
     * Reuses the exact same pricing logic structure as MapOffer to ensure frontend parity.
     */
    private static function buildPriceBreakdown(array $sol, array $pax, string $currency): array
    {
        $unit = [
            'ADT' => [
                'fare' => self::toFloat($sol['adtFare'] ?? 0),
                'tax'  => self::toFloat($sol['adtTax']  ?? 0),
                'count'=> (int)($pax['adults'] ?? 0),
            ],
            'CHD' => [
                'fare' => self::toFloat($sol['chdFare'] ?? 0),
                'tax'  => self::toFloat($sol['chdTax']  ?? 0),
                'count'=> (int)($pax['children'] ?? 0),
            ],
            'INF' => [
                'fare' => self::toFloat($sol['infFare'] ?? $sol['infantFare'] ?? 0),
                'tax'  => self::toFloat($sol['infTax']  ?? $sol['infantTax']  ?? 0),
                'count'=> (int)($pax['infants'] ?? 0),
            ],
        ];

        $ptc = [];
        $sumBase = 0.0;
        $sumTax = 0.0;

        foreach ($unit as $code => $row) {
            $count = max(0, $row['count']);
            $baseSub = $row['fare'] * $count;
            $taxSub  = $row['tax']  * $count;

            $sumBase += $baseSub;
            $sumTax  += $taxSub;

            $ptc[$code] = [
                'count' => $count,
                'unit'  => [
                    'fare'  => self::round($row['fare']),
                    'tax'   => self::round($row['tax']),
                    'total' => self::round($row['fare'] + $row['tax']),
                ],
                'subtotal' => [
                    'base'  => self::round($baseSub),
                    'taxes' => self::round($taxSub),
                    'total' => self::round($baseSub + $taxSub),
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
            'currency' => $currency,
            'perPassenger' => $ptc,
            'fees' => [
                'items' => array_map([self::class, 'round'], $fees),
                'total' => self::round($sumFees),
            ],
            'totals' => [
                'base'  => self::round($sumBase),
                'taxes' => self::round($sumTax),
                'fees'  => self::round($sumFees),
                'grand' => self::round($grandTotal),
            ]
        ];
    }

    // ---------------------------------------------------------------------
    // UTILITY HELPERS
    // ---------------------------------------------------------------------

    private static function formatEpochToIso(mixed $ms): ?string
    {
        return is_numeric($ms) ? date(DATE_ATOM, ((int)$ms) / 1000) : null;
    }

    private static function toFloat(mixed $v): float
    {
        return is_numeric($v) ? (float)$v : 0.0;
    }

    private static function round(mixed $v): float
    {
        return round((float)$v, 2);
    }

    private static function withIsoTimes(array $seg): array
    {
        $seg['departureIso'] = self::formatEpochToIso($seg['departureDate'] ?? null);
        $seg['arrivalIso']   = self::formatEpochToIso($seg['arrivalDate'] ?? null);
        return $seg;
    }

    private static function mapBaggageByGlobalIndex(array $ptcBlocks, array $globalIdxToSegId): array
    {
        $checked = [];
        $carry   = [];

        foreach ($ptcBlocks as $b) {
            foreach ((array)($b['segmentIndexList'] ?? []) as $n) {
                $sid = $globalIdxToSegId[$n] ?? null;
                if (!$sid) continue;

                if (isset($b['baggageAmount']) || isset($b['baggageWeight'])) {
                    $checked[$sid] = [
                        'amount' => $b['baggageAmount'] ?? null,
                        'weight' => $b['baggageWeight'] ?? null,
                    ];
                }
                if (isset($b['carryOnAmount']) || isset($b['carryOnWeight']) || isset($b['carryOnSize'])) {
                    $carry[$sid] = [
                        'amount' => $b['carryOnAmount'] ?? null,
                        'weight' => $b['carryOnWeight'] ?? null,
                        'size'   => $b['carryOnSize'] ?? null,
                    ];
                }
            }
        }
        return [$checked, $carry];
    }

    private static function mapMiniRulesByGlobalIndex(array $ptcBlocks, array $globalIdxToSegId): array
    {
        $rules = [];
        foreach ($ptcBlocks as $block) {
            $ids = [];
            foreach ((array)($block['segmentIndex'] ?? []) as $n) {
                $sid = $globalIdxToSegId[$n] ?? null;
                if ($sid) $ids[] = $sid;
            }
            $miniRules = array_map(function ($r) {
                $r['label'] = match ($r['penaltyType'] ?? -1) {
                    0 => 'Refund',
                    1 => 'Change',
                    2 => 'No-show',
                    3 => 'Reissue / Reroute',
                    default => 'Penalty',
                };
                return $r;
            }, (array)($block['miniRules'] ?? []));

            $rules[] = ['segmentIds' => $ids, 'miniRules' => $miniRules];
        }
        return $rules;
    }
}
