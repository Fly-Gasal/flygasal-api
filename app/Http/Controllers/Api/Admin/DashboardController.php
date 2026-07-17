<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Flights\Booking;
use App\Models\Flights\Transaction;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/summary?range=7d|30d|6m|12m
     * Returns:
     *  - totals: users, bookings, cancelled, unpaid, revenue
     *  - trends: arrays for small sparklines (same length as labels)
     *  - labels: x-axis labels matching trend points
     */
    public function summary(Request $request)
    {
        $range = $this->normalizeRange($request->query('range', '30d'));

        // Light cache; bust whenever you prefer (e.g., after write ops)
        $cacheKey = "dashboard.summary.{$range}";
        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($range) {
            [$from, $to, $period] = $this->computeWindow($range);

            // Zero-filled buckets
            [$labels, $keys] = $this->makeBuckets($from, $to, $period);
            $pointCount = count($keys);

            // --- Totals (same semantics as before) ---
            $totalUsers = User::count();

            $bookingStatusesConfirm = ['confirmed', 'completed', 'paid', 'issued'];
            $bookingsInRange = Booking::query()
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('status', $bookingStatusesConfirm)
                ->count();

            $cancelledInRange = Booking::query()
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'cancelled')
                ->count();

            $unpaidOpen = Booking::query()
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->count();

            $revenueInRange = (float) Transaction::query()
                ->where('type', 'booking_payment')
                ->where('status', 'completed')
                ->whereBetween('created_at', [$from, $to])
                ->sum('amount');

            // --- Trends (SQL aggregation → fill zero-based arrays) ---
            $trendUsers       = array_fill(0, $pointCount, 0);
            $trendBookings    = array_fill(0, $pointCount, 0);
            $trendCancelled   = array_fill(0, $pointCount, 0);
            $trendUnpaid      = array_fill(0, $pointCount, 0);
            $trendRevenue     = array_fill(0, $pointCount, 0);

            // Users created in window (grouped by bucket)
            $userCounts = $this->groupedCounts('users', function ($q) use ($from, $to) {
                $q->whereBetween('created_at', [$from, $to]);
            }, $period);

            // Bookings grouped by bucket and status
            $bucketFmt = $period === 'month' ? '%Y-%m' : '%Y-%m-%d';
            $bookingRows = DB::table('bookings')
                ->selectRaw("DATE_FORMAT(created_at, '{$bucketFmt}') as bucket, status, COUNT(*) as c")
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('bucket', 'status')
                ->get();

            $bookingByBucket = [];
            foreach ($bookingRows as $r) {
                $b = (string) $r->bucket;
                $s = (string) $r->status;
                $bookingByBucket[$b][$s] = (int) $r->c;
            }

            // Revenue grouped by bucket
            $revenueMap = $this->groupedCounts('transactions', function ($q) use ($from, $to) {
                $q->where('type', 'booking_payment')
                  ->where('status', 'completed')
                  ->whereBetween('created_at', [$from, $to]);
            }, $period, 'SUM(amount) as agg');

            // Fill arrays by bucket key order
            foreach ($keys as $i => $k) {
                if (isset($userCounts[$k])) {
                    $trendUsers[$i] = (int) $userCounts[$k];
                }

                $row = $bookingByBucket[$k] ?? [];
                // Confirmed-ish
                $trendBookings[$i]  = (int) array_sum(array_intersect_key($row, array_flip($bookingStatusesConfirm)));
                // Cancelled count
                $trendCancelled[$i] = (int) ($row['cancelled'] ?? 0);
                // New unpaid created in this bucket (sparkline semantics kept)
                $trendUnpaid[$i]    = (int) ($row['unpaid'] ?? 0);

                if (isset($revenueMap[$k])) {
                    $trendRevenue[$i] = (float) $revenueMap[$k];
                }
            }

            $recentBookings = Booking::latest()
                ->limit(8)
                ->get(['id', 'order_num', 'pnr', 'status', 'payment_status', 'total_amount', 'currency', 'contact_name', 'contact_email', 'created_at']);

            return response()->json([
                'status' => true,
                'data' => [
                    'range'    => $range,
                    'period'   => $period,
                    'labels'   => $labels,
                    'currency' => 'USD',
                    'totals'   => [
                        'users'     => $totalUsers,
                        'bookings'  => $bookingsInRange,
                        'cancelled' => $cancelledInRange,
                        'unpaid'    => $unpaidOpen,
                        'revenue'   => $revenueInRange,
                    ],
                    'trends'   => [
                        'users'     => $trendUsers,
                        'bookings'  => $trendBookings,
                        'cancelled' => $trendCancelled,
                        'unpaid'    => $trendUnpaid,
                        'revenue'   => $trendRevenue,
                    ],
                    'recent_bookings' => $recentBookings,
                ],
            ]);
        });
    }

    /**
     * GET /api/dashboard/sales?range=7d|30d|6m|12m
     * Returns labels + a single "sales" (revenue) dataset for the chart.
     */
    public function sales(Request $request)
    {
        $range = $this->normalizeRange($request->query('range', '30d'));

        $cacheKey = "dashboard.sales.{$range}";
        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($range) {
            [$from, $to, $period] = $this->computeWindow($range);
            [$labels, $keys] = $this->makeBuckets($from, $to, $period);
            $series = array_fill(0, count($keys), 0.0);

            // SQL aggregation for revenue
            $revenueMap = $this->groupedCounts('transactions', function ($q) use ($from, $to) {
                $q->where('type', 'booking_payment')
                  ->where('status', 'completed')
                  ->whereBetween('created_at', [$from, $to]);
            }, $period, 'SUM(amount) as agg');

            foreach ($keys as $i => $k) {
                if (isset($revenueMap[$k])) {
                    $series[$i] = (float) $revenueMap[$k];
                }
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'range'    => $range,
                    'period'   => $period,
                    'currency' => 'USD',
                    'labels'   => $labels,
                    'datasets' => [
                        [
                            'label' => 'Sales',
                            'data'  => $series,
                        ],
                    ],
                ],
            ]);
        });
    }

    /* -------------------- Helpers -------------------- */

    private function normalizeRange(string $range): string
    {
        $allowed = ['7d', '30d', '6m', '12m'];
        return in_array($range, $allowed, true) ? $range : '30d';
    }

    /**
     * @return array{0:Carbon,1:Carbon,2:string} [$from, $to, $period]
     */
    private function computeWindow(string $range): array
    {
        $now = Carbon::now();
        switch ($range) {
            case '7d':
                return [$now->copy()->startOfDay()->subDays(6), $now->copy()->endOfDay(), 'day'];
            case '30d':
                return [$now->copy()->startOfDay()->subDays(29), $now->copy()->endOfDay(), 'day'];
            case '6m':
                return [$now->copy()->startOfMonth()->subMonths(5), $now->copy()->endOfMonth(), 'month'];
            case '12m':
                return [$now->copy()->startOfMonth()->subMonths(11), $now->copy()->endOfMonth(), 'month'];
            default:
                return [$now->copy()->startOfDay()->subDays(29), $now->copy()->endOfDay(), 'day'];
        }
    }

    /**
     * Build zero-filled buckets and pretty labels.
     *
     * @return array{0:array<int,string>,1:array<int,string>} [$labels, $keys]
     *  - $keys are the canonical bucket keys we use to match data (Y-m-d or Y-m)
     */
    private function makeBuckets(Carbon $from, Carbon $to, string $period): array
    {
        if ($period === 'month') {
            $from = $from->copy()->startOfMonth();
            $to   = $to->copy()->startOfMonth();
            $step = '1 month';
            $formatKey = 'Y-m';
            $formatLabel = 'M Y';
        } else { // day
            $from = $from->copy()->startOfDay();
            $to   = $to->copy()->startOfDay();
            $step = '1 day';
            $formatKey = 'Y-m-d';
            $formatLabel = 'M j';
        }

        $periodIter = CarbonPeriod::create($from, $step, $to);
        $keys = [];
        $labels = [];

        foreach ($periodIter as $d) {
            $keys[] = $d->format($formatKey);
            $labels[] = $d->translatedFormat($formatLabel);
        }

        return [$labels, $keys];
    }

    /**
     * Get the canonical bucket key for a timestamp.
     */
    private function bucketKey($timestamp, string $period): string
    {
        $dt = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);
        return $period === 'month'
            ? $dt->copy()->startOfMonth()->format('Y-m')
            : $dt->copy()->startOfDay()->format('Y-m-d');
    }

    /**
     * Group by day/month bucket using SQL and return map [bucket => aggregated value]
     *
     * @param  string   $table      Table name
     * @param  callable $wheres     Closure(Query\Builder) to add where clauses
     * @param  string   $period     'day' | 'month'
     * @param  string   $valueExpr  Aggregation (e.g. 'COUNT(*) as agg', 'SUM(amount) as agg')
     * @return array<string,int|float>  ['2025-10-01' => 3, '2025-10-02' => 5, ...]
     */
    private function groupedCounts(string $table, callable $wheres, string $period, string $valueExpr = 'COUNT(*) as agg'): array
    {
        $bucketFmt = $period === 'month' ? '%Y-%m' : '%Y-%m-%d';

        $q = DB::table($table)
            ->selectRaw("DATE_FORMAT(created_at, '{$bucketFmt}') as bucket, {$valueExpr}")
            ->when(true, function ($qq) use ($wheres) { $wheres($qq); })
            ->groupBy('bucket');

        // pluck(agg, bucket) → ['bucket' => agg]
        return $q->pluck('agg', 'bucket')->all();
    }
}
